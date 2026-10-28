<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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
 * Substitui o listener `Flarum\Mail\MutateEmail` no container. Até o 2.0.1
 * o `handle()` do core devolve `true`, e o `until()` do Mailer para no
 * primeiro valor não nulo, calando os outros listeners de `MessageSending`.
 * Por isso compomos: chamamos o core, adicionamos o Reply-To e não devolvemos nada.
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
     * `is_private` por discussão, guardado durante o request: um lote de
     * notificações dispara vários `MessageSending` para a mesma discussão.
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
     * para os próximos listeners em qualquer versão do core. Sem tipo
     * nativo de propósito (ver o teste do mutator).
     *
     * @return void
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
     * Quando a notificação aponta para um post, o id vai no alias para a
     * resposta citar esse post. Algumas extensões passam blueprint ou
     * usuário que não são objetos; esses casos são ignorados.
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
     * Gmail, Outlook e Apple Mail agrupam e-mails de assunto igual e o botão
     * Responder usa o Reply-To do primeiro da conversa. O sufixo `#{postId}`
     * deixa cada assunto único. Idempotente se o evento for reenfileirado.
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
     * Usa `getSubject()` e, para blueprints que não seguem o padrão, a
     * propriedade pública `$post`.
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
     * Post que a resposta deve citar. No `PostMentionedBlueprint` o
     * `getSubject()` é o post citado, mas quem responde fala com o autor de
     * `$reply`. Quando o assunto é uma discussão, não há post a citar.
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
