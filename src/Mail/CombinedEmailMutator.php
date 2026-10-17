<?php

namespace Ramon\MailReply\Mail;

use Flarum\Discussion\Discussion;
use Flarum\Foundation\ErrorHandling\LogReporter;
use Flarum\Mail\MutateEmail;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Mail\Events\MessageSending;
use Psr\Log\LoggerInterface;
use Ramon\MailReply\Token\TokenService;
use Throwable;

/**
 * Substitui o listener `Flarum\Mail\MutateEmail` via binding no container.
 *
 * Até o Flarum 2.0.1 o `MutateEmail::handle()` do core devolve `true`, e o
 * `events->until()` do Laravel Mailer para no primeiro valor não-null —
 * nenhum outro listener de `MessageSending` roda. No 2.0.2 o core passa a
 * devolver `void`. Por isso compomos em vez de herdar: chamamos o
 * mutator do core e depois injetamos o Reply-To, sem tipo de retorno —
 * a assinatura é compatível com todas as versões 2.0.x e não interrompe
 * os listeners de outras extensões.
 */
class CombinedEmailMutator
{
    /**
     * Pedem a servidores e clientes que não respondam automaticamente
     * (férias, Outlook OOF, reações do Teams) — o retorno viraria post.
     */
    private const AUTO_REPLY_SUPPRESSION_HEADERS = [
        'Auto-Submitted' => 'auto-generated',
        'X-Auto-Response-Suppress' => 'All',
        'x-ms-reactions' => 'disallow',
    ];

    /**
     * Memoização por-request do flag `is_private` por discussion_id.
     * Mailer dispara MessageSending múltiplas vezes em um batch de
     * notificação; evitar query repetida pra mesma discussion.
     *
     * @var array<int, bool>
     */
    protected array $privateCache = [];

    public function __construct(
        protected TokenService $tokens,
        protected LogReporter $log,
        protected LoggerInterface $logger,
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * Roda o mutator do core (List-Unsubscribe e afins) e em seguida
     * adiciona o Reply-To assinado. Não devolve valor: `until()` segue
     * para os próximos listeners em qualquer versão do core.
     */
    public function handle(MessageSending $event)
    {
        (new MutateEmail())->handle($event);

        try {
            $this->injectReplyTo($event);
        } catch (Throwable $e) {
            $this->log->report($e);
        }
    }

    /**
     * Quando o blueprint carrega um post específico (mention, reply, post
     * mention), embutimos o postId no alias para que o InboundProcessor
     * monte o `@"author"#p{postId}` no início da resposta — replicando o
     * "Responder ao post" do fórum.
     *
     * Algumas extensões passam blueprint/user como array ou outro tipo;
     * nosso fluxo só faz sentido com objetos.
     */
    protected function injectReplyTo(MessageSending $event): void
    {
        $data = $event->data;
        $user = $data['user'] ?? null;
        $blueprint = $data['blueprint'] ?? null;

        if (! is_object($user) || ! is_object($blueprint)) {
            return;
        }

        if ((string) $this->settings->get('ramon-mail-reply.delivery_mode') === 'disabled') {
            return;
        }

        $discussionId = $this->extractDiscussionId($blueprint);

        if ($discussionId === null) {
            $this->logger->debug('[mail-reply] skip: blueprint has no discussion', [
                'blueprint' => get_class($blueprint),
            ]);

            return;
        }

        if ($this->isPrivateDiscussionId($discussionId)) {
            return;
        }

        $postId = $this->extractPostId($blueprint);

        $aliasAddress = $this->tokens->mintAddress((int) $user->id, $discussionId, $postId);

        if ($aliasAddress === null) {
            $this->logger->debug('[mail-reply] skip: reply mailbox not configured');

            return;
        }

        $message = $event->message;
        $headers = $message->getHeaders();
        $headers->remove('Reply-To');
        $headers->addMailboxListHeader('Reply-To', [$aliasAddress]);

        foreach (self::AUTO_REPLY_SUPPRESSION_HEADERS as $name => $value) {
            if (! $headers->has($name)) {
                $headers->addTextHeader($name, $value);
            }
        }

        if ($postId !== null && $postId > 0) {
            $this->ensureSubjectHasPostId($message, $postId);
        }
    }

    /**
     * Quebra agrupamento por thread no cliente (Outlook, Gmail web, Apple
     * Mail). Esses clientes agrupam por Subject idêntico — se o usuário
     * recebe 4 notificações sobre o mesmo tópico, todas com "Ramon
     * mentioned you in 'X'", elas viram UMA conversa e o botão Responder
     * usa o Reply-To do PRIMEIRO e-mail, não do mais recente. Adicionar
     * `#{postId}` no final torna cada Subject único; cada e-mail vira sua
     * própria conversa e o Reply-To respeitado é o do próprio e-mail
     * aberto. Idempotente caso o evento seja re-enfileirado.
     */
    protected function ensureSubjectHasPostId(object $message, int $postId): void
    {
        if (! method_exists($message, 'getSubject') || ! method_exists($message, 'subject')) {
            return;
        }

        $currentSubject = (string) $message->getSubject();
        $marker = ' #'.$postId;

        if (str_ends_with($currentSubject, $marker) || str_contains($currentSubject, $marker.' ') || str_contains($currentSubject, $marker.']')) {
            return;
        }

        $message->subject($currentSubject.$marker);
    }

    /**
     * Lê `discussions.is_private` (fof/byobu) com memoização por request.
     * `select *` evita referenciar a coluna pelo nome: em fóruns sem byobu
     * ela não existe e o atributo simplesmente vem nulo (público).
     */
    protected function isPrivateDiscussionId(int $discussionId): bool
    {
        if (! isset($this->privateCache[$discussionId])) {
            $discussion = Discussion::query()->find($discussionId);
            $this->privateCache[$discussionId] = (bool) $discussion?->getAttribute('is_private');
        }

        return $this->privateCache[$discussionId];
    }

    /**
     * Tenta extrair discussion_id do blueprint. Usa `getSubject()` como
     * fonte primária (canônica do Flarum), com fallback pra propriedade
     * `$post` pública para blueprints atípicos.
     */
    protected function extractDiscussionId(object $blueprint): ?int
    {
        try {
            if (method_exists($blueprint, 'getSubject')) {
                $subject = $blueprint->getSubject();

                if ($subject instanceof Post && (int) $subject->discussion_id > 0) {
                    return (int) $subject->discussion_id;
                }

                if (is_object($subject) && method_exists($subject, 'getTable') && $subject->getTable() === 'discussions' && isset($subject->id)) {
                    return (int) $subject->id;
                }

                if (is_object($subject) && isset($subject->discussion_id)) {
                    return (int) $subject->discussion_id;
                }
            }

            if (property_exists($blueprint, 'post')) {
                $post = $blueprint->post;
                if ($post instanceof Post && (int) $post->discussion_id > 0) {
                    return (int) $post->discussion_id;
                }
            }
        } catch (Throwable $e) {
            $this->log->report($e);
        }

        return null;
    }

    /**
     * Extrai o postId que deve ser o "alvo" do quote-prepend — o post que
     * o usuário está respondendo ao replicar essa notificação.
     *
     * Regras (ordem importa):
     *
     * 1. `PostMentionedBlueprint` (flarum/mentions) tem DUAS Post
     *    properties: `$post` (post CITADO — o antigo) e `$reply` (post
     *    NOVO de quem te citou). `getSubject()` retorna `$post` porque
     *    semanticamente é o "subject" da notif, MAS pra nosso quote
     *    queremos `$reply` — você está respondendo a quem te citou, não
     *    ao seu próprio post antigo. Detectamos esse caso pela presença
     *    das duas propriedades.
     *
     * 2. `UserMentionedBlueprint`, `GroupMentionedBlueprint` — só têm
     *    `$post` (o post que menciona). `getSubject()` retorna `$post`
     *    corretamente. Caímos no caso geral.
     *
     * 3. `NewPostBlueprint` (subscriptions) — `getSubject()` retorna
     *    Discussion. Devolvemos null → alias sem `p{N}` → sem quote.
     */
    protected function extractPostId(object $blueprint): ?int
    {
        try {
            if (property_exists($blueprint, 'reply') && property_exists($blueprint, 'post')) {
                $reply = $blueprint->reply;
                if ($reply instanceof Post && (int) $reply->id > 0) {
                    return (int) $reply->id;
                }
            }

            if (method_exists($blueprint, 'getSubject')) {
                $subject = $blueprint->getSubject();
                if ($subject instanceof Post && (int) $subject->id > 0) {
                    return (int) $subject->id;
                }
            }
        } catch (Throwable $e) {
            $this->log->report($e);
        }

        return null;
    }
}
