<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\MailReply\Inbound;

use Carbon\Carbon;
use Flarum\Discussion\Command\ReadDiscussion;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\DispatchEventsTrait;
use Flarum\Foundation\ValidationException;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Saving;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Ramon\MailReply\Database\InboundEmail;
use Ramon\MailReply\Database\MailReplyPost;
use Ramon\MailReply\Parser\EmailParser;
use Ramon\MailReply\Token\TokenService;

/**
 * Processa um payload de inbound de ponta a ponta e cria o post. Toda
 * recusa definitiva sai como `InboundRejected` e fica registrada em
 * `mail_reply_inbound` pelo hash do Message-ID — reentregas da mesma
 * mensagem (re-fetch IMAP, retry de webhook) viram no-op silencioso.
 */
class InboundProcessor
{
    use DispatchEventsTrait;

    public const MAX_CONTENT_LENGTH = 63000;

    public const DEFAULT_REPLIES_PER_HOUR = 20;

    public function __construct(
        protected TokenService $tokens,
        protected EmailParser $parser,
        protected SettingsRepositoryInterface $settings,
        protected Dispatcher $events,
        protected LoggerInterface $logger,
        protected RejectionNotifier $notifier,
        protected BusDispatcher $bus,
    ) {
    }

    /**
     * @param array{to: string|array<int,string>, from?: string, subject?: string, body_text?: string, body_html?: string, stripped_text?: string, message_id?: string, headers?: array<string, array<int,string>|string>} $payload
     */
    public function process(array $payload): CommentPost
    {
        $hash = self::messageHash($payload);

        if ($this->alreadyRecorded($hash)) {
            throw new InboundRejected('duplicate', 'Message was already processed.', true);
        }

        $context = ['user_id' => null, 'discussion_id' => null];

        try {
            [$user, $discussion, $content] = $this->accept($payload, $context);
        } catch (InboundRejected $e) {
            $this->record($hash, $e->silent ? InboundEmail::STATUS_IGNORED : InboundEmail::STATUS_REJECTED, $e->reason, $context);
            $this->notifyRejection($e, $context, $payload);

            throw $e;
        }

        try {
            $post = CommentPost::query()->getConnection()->transaction(function () use ($user, $discussion, $content, $hash) {
                $post = $this->createPost($user, $discussion, $content);

                $this->record($hash, InboundEmail::STATUS_PROCESSED, null, [
                    'user_id' => (int) $user->id,
                    'discussion_id' => (int) $discussion->id,
                    'post_id' => (int) $post->id,
                ], true);

                return $post;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Só é duplicata se outro worker registrou esta mensagem; uma
            // colisão no número do post é falha transitória e precisa de nova
            // tentativa.
            if ($this->alreadyRecorded($hash)) {
                throw new InboundRejected('duplicate', 'Message was processed concurrently.', true);
            }

            throw $e;
        } catch (ValidationException|PermissionDeniedException $e) {
            // Outra extensão (filtro de palavras, antispam) recusou o post no
            // `Saving`: recusa definitiva, registrada como qualquer outra.
            $rejected = new InboundRejected('validation', $e->getMessage());
            $this->record($hash, InboundEmail::STATUS_REJECTED, $rejected->reason, $context);
            $this->notifyRejection($rejected, $context, $payload);

            throw $rejected;
        }

        $this->dispatchEventsFor($post, $user);

        // Como o PostResource do core: quem responde leu a discussão até a
        // própria resposta. Sem isso o flarum/subscriptions deixa de avisar
        // dos posts seguintes.
        $this->bus->dispatch(new ReadDiscussion($discussion->id, $user, $post->number));

        return $post;
    }

    /**
     * @phpstan-impure
     */
    protected function alreadyRecorded(string $hash): bool
    {
        return InboundEmail::query()->where('message_hash', $hash)->exists();
    }

    /**
     * Avisa o autor só quando a mensagem veio do e-mail confirmado da
     * própria conta; falha no aviso nunca altera o resultado da recusa.
     *
     * @param array{user_id: ?int, discussion_id: ?int} $context
     * @param array<string, mixed> $payload
     */
    protected function notifyRejection(InboundRejected $e, array $context, array $payload): void
    {
        if ($e->silent || $context['user_id'] === null || ! in_array($e->reason, RejectionNotifier::NOTIFIABLE_REASONS, true)) {
            return;
        }

        $user = User::query()->find($context['user_id']);

        if ($user !== null && $this->senderMatches($user, strtolower(trim((string) ($payload['from'] ?? ''))))) {
            $this->notifier->notify($user, $e->reason, (string) ($payload['subject'] ?? ''));
        }
    }

    /**
     * Identidade estável da mensagem. Sem Message-ID, cai num digest do
     * remetente + assunto + corpo, suficiente para retries idênticos.
     *
     * @param array<string, mixed> $payload
     */
    public static function messageHash(array $payload): string
    {
        $messageId = strtolower(trim((string) ($payload['message_id'] ?? ''), " \t\n\r<>"));

        if ($messageId !== '') {
            return hash('sha256', 'mid|'.$messageId);
        }

        return hash('sha256', 'raw|'.implode("\0", [
            strtolower(trim((string) ($payload['from'] ?? ''))),
            (string) ($payload['subject'] ?? ''),
            (string) ($payload['body_text'] ?? ''),
            (string) ($payload['body_html'] ?? ''),
        ]));
    }

    /**
     * Valida a mensagem na ordem barato → caro. `$context` recebe os ids
     * resolvidos para o registro de auditoria mesmo quando há recusa.
     *
     * @param array<string, mixed> $payload
     * @param array{user_id: ?int, discussion_id: ?int} $context
     * @return array{0: User, 1: Discussion, 2: string}
     */
    protected function accept(array $payload, array &$context): array
    {
        $from = strtolower(trim((string) ($payload['from'] ?? '')));
        $headers = (array) ($payload['headers'] ?? []);

        if ($reason = AutoReplyDetector::reason($headers, $from, (string) ($payload['subject'] ?? ''))) {
            throw new InboundRejected($reason, 'Automatically generated message ignored.', true);
        }

        $bind = $this->findBinding($payload);

        if ($bind === null) {
            throw new InboundRejected('invalid_address', 'No valid signed reply address found among recipients.');
        }

        $context['user_id'] = $bind['user_id'];
        $context['discussion_id'] = $bind['discussion_id'];

        /** @var User|null $user */
        $user = User::query()->find($bind['user_id']);

        if ($user === null) {
            $context['user_id'] = null;

            throw new InboundRejected('unknown_user', 'Signed address references a user that no longer exists.');
        }

        if ($this->requiresSenderMatch() && ! $this->senderMatches($user, $from)) {
            throw new InboundRejected('sender_mismatch', 'Sender address does not match the account the reply address was issued to.');
        }

        if (self::dmarcFailed($headers)) {
            throw new InboundRejected('sender_unauthenticated', 'The receiving server reported a DMARC failure for the From domain.');
        }

        /** @var Discussion|null $discussion */
        $discussion = Discussion::query()->whereVisibleTo($user)->find($bind['discussion_id']);

        if ($discussion === null) {
            throw new InboundRejected('discussion_unavailable', 'Discussion no longer exists or is not visible to the user.');
        }

        if ((bool) ($discussion->is_private ?? false)) {
            throw new InboundRejected('private_discussion', 'Replies via email are only supported on public discussions.');
        }

        if (! $user->can('reply', $discussion)) {
            throw new InboundRejected('not_allowed', 'User is not allowed to reply to this discussion.');
        }

        if ($this->exceedsHourlyLimit($user)) {
            throw new InboundRejected('rate_limited', 'Too many replies by email in the last hour.');
        }

        $content = $this->extractContent($payload);

        if ($content === '') {
            $this->logger->info('[mail-reply] reply body empty after parsing', [
                'user_id' => $bind['user_id'],
                'discussion_id' => $bind['discussion_id'],
                'text_bytes' => strlen((string) ($payload['body_text'] ?? '')),
                'html_bytes' => strlen((string) ($payload['body_html'] ?? '')),
            ]);

            throw new InboundRejected('empty_body', 'Reply body is empty after parsing.');
        }

        $content = $this->prependReplyQuote($content, $bind['post_id'], $discussion, $user);

        if (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
            throw new InboundRejected('too_long', 'Reply exceeds the maximum post length.');
        }

        return [$user, $discussion, $content];
    }

    /**
     * Usa o texto já sem citações do provedor (Mailgun `stripped-text`,
     * Postmark `StrippedTextReply`) quando disponível; senão o parser
     * local trata text/plain e, na falta dele, o HTML.
     *
     * @param array<string, mixed> $payload
     */
    protected function extractContent(array $payload): string
    {
        $stripped = trim((string) ($payload['stripped_text'] ?? ''));

        if ($stripped !== '') {
            return $stripped;
        }

        $text = (string) ($payload['body_text'] ?? '');
        $html = (string) ($payload['body_html'] ?? '');

        $content = $this->parser->extractReply(trim($text) !== '' ? $text : $html);

        if ($content === '' && trim($text) !== '' && trim($html) !== '') {
            $content = $this->parser->extractReply($html);
        }

        return $content;
    }

    /**
     * O From é só um cabeçalho; quem garante o domínio é o DMARC. Vale o
     * `Authentication-Results` do topo, que é o do servidor que recebeu a
     * mensagem — os de baixo vêm de quem enviou e não merecem confiança. Só
     * a falha explícita recusa: sem o cabeçalho, nada muda.
     *
     * @param array<string, mixed> $headers
     */
    public static function dmarcFailed(array $headers): bool
    {
        $top = (array) ($headers['authentication-results'] ?? []);
        $first = strtolower((string) ($top[0] ?? ''));

        return (bool) preg_match('/(?:^|[;\s])dmarc\s*=\s*fail\b/', substr($first, 0, 4096));
    }

    protected function requiresSenderMatch(): bool
    {
        $value = $this->settings->get('ramon-mail-reply.require_sender_match');

        return $value === null || $value === '' || (bool) $value;
    }

    /**
     * O alias assinado é o primeiro fator; o remetente igual ao e-mail
     * confirmado da conta é o segundo — impede que uma notificação
     * encaminhada ou um alias vazado poste em nome do usuário.
     */
    protected function senderMatches(User $user, string $from): bool
    {
        $email = strtolower(trim((string) $user->email));

        return $from !== '' && $email !== '' && (bool) $user->is_email_confirmed && hash_equals($email, $from);
    }

    protected function exceedsHourlyLimit(User $user): bool
    {
        $value = $this->settings->get('ramon-mail-reply.max_replies_per_hour');
        // Campo apagado no admin volta ao padrão; só 0 desliga o limite.
        $limit = is_numeric($value) ? (int) $value : self::DEFAULT_REPLIES_PER_HOUR;

        if ($limit <= 0 || $user->can('postWithoutThrottle')) {
            return false;
        }

        return InboundEmail::query()
            ->where('user_id', $user->id)
            ->where('status', InboundEmail::STATUS_PROCESSED)
            ->where('created_at', '>=', Carbon::now()->subHour())
            ->count() >= $limit;
    }

    /**
     * Prefixa `@"username"#p{postId}` (sintaxe do flarum/mentions) para a
     * resposta renderizar como réplica ao post citado. Só considera posts
     * da mesma discussão e visíveis ao usuário.
     */
    protected function prependReplyQuote(string $content, ?int $postId, Discussion $discussion, User $user): string
    {
        if ($postId === null || $postId <= 0) {
            return $content;
        }

        /** @var Post|null $original */
        $original = Post::query()
            ->whereVisibleTo($user)
            ->where('discussion_id', $discussion->id)
            ->with('user')
            ->find($postId);

        $username = trim((string) $original?->user?->username);

        if ($username === '') {
            return $content;
        }

        $username = str_replace(['\\', '"'], ['\\\\', '\\"'], $username);

        return sprintf('@"%s"#p%d', $username, $postId)."\n\n".$content;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{user_id: int, discussion_id: int, post_id: ?int}|null
     */
    protected function findBinding(array $payload): ?array
    {
        $to = $payload['to'] ?? null;

        foreach (is_array($to) ? $to : [$to] as $address) {
            if (is_string($address) && $address !== '' && ($bind = $this->tokens->parseAddress($address))) {
                return $bind;
            }
        }

        return null;
    }

    /**
     * Registra o resultado. Fora da transação do post, uma colisão de
     * hash só significa que outro worker registrou antes — ignorada.
     *
     * @param array{user_id?: ?int, discussion_id?: ?int, post_id?: ?int} $ids
     */
    protected function record(string $hash, string $status, ?string $reason, array $ids, bool $strict = false): void
    {
        $row = new InboundEmail();
        $row->message_hash = $hash;
        $row->user_id = $ids['user_id'] ?? null;
        $row->discussion_id = $ids['discussion_id'] ?? null;
        $row->post_id = $ids['post_id'] ?? null;
        $row->status = $status;
        $row->reason = $reason;
        $row->created_at = Carbon::now();

        try {
            $row->save();
        } catch (UniqueConstraintViolationException $e) {
            if ($strict) {
                throw $e;
            }
        }
    }

    /**
     * O core faz o trabalho atômico: `Post::boot creating` atribui o
     * `number` e levanta `Posted`, cujo listener atualiza os contadores da
     * discussão. A marca "via e-mail" vai na companion table. Os eventos
     * só são despachados depois do commit (ver `process()`).
     */
    protected function createPost(User $user, Discussion $discussion, string $content): CommentPost
    {
        $post = new CommentPost();
        $post->created_at = Carbon::now();
        $post->discussion_id = $discussion->id;
        $post->user_id = $user->id;
        $post->setContentAttribute($content, $user);

        $this->events->dispatch(new Saving($post, $user, ['attributes' => ['content' => $content]]));

        $post->save();

        $meta = new MailReplyPost();
        $meta->post_id = (int) $post->id;
        $meta->save();

        return $post;
    }
}
