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

use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Avisa o autor quando a resposta por e-mail não virou post (padrão do
 * Discourse). Só para motivos que o usuário consegue resolver e só para o
 * endereço confirmado da conta — remetente forjado nunca recebe nada. No
 * máximo um aviso por usuário e motivo a cada 24h, marcado como
 * `Auto-Submitted: auto-replied` para não gerar laço com auto-respostas.
 */
class RejectionNotifier
{
    public const NOTIFIABLE_REASONS = [
        'not_allowed', 'discussion_unavailable', 'private_discussion',
        'rate_limited', 'empty_body', 'too_long', 'validation',
    ];

    private const THROTTLE_SECONDS = 86400;

    public function __construct(
        protected Mailer $mailer,
        protected TranslatorInterface $translator,
        protected SettingsRepositoryInterface $settings,
        protected Repository $cache,
        protected LoggerInterface $logger,
    ) {
    }

    public function enabled(): bool
    {
        $value = $this->settings->get('ramon-mail-reply.send_rejection_notices');

        return $value === null || $value === '' || (bool) $value;
    }

    public function notify(User $user, string $reason, string $originalSubject): void
    {
        if (! in_array($reason, self::NOTIFIABLE_REASONS, true) || ! $this->enabled()) {
            return;
        }

        if (! $this->cache->add('mail-reply.rejection.'.$user->id.'.'.$reason, 1, self::THROTTLE_SECONDS)) {
            return;
        }

        $previousLocale = $this->translator->getLocale();

        try {
            $this->translator->setLocale($user->getPreference('locale') ?? (string) $this->settings->get('default_locale'));

            $forum = (string) $this->settings->get('forum_title');
            $prefix = 'ramon-mail-reply.email.rejected.';
            $body = $this->translator->trans($prefix.'body', ['username' => $user->display_name, 'forum' => $forum])
                ."\n\n".$this->translator->trans($prefix.'reason_'.$reason)
                ."\n\n".$this->translator->trans($prefix.'footer', ['subject' => $originalSubject]);
            $subject = $this->translator->trans($prefix.'subject', ['forum' => $forum]);

            $this->mailer->raw($body, function (Message $message) use ($user, $subject) {
                $message->to($user->email, $user->display_name)->subject($subject);
                $message->getSymfonyMessage()->getHeaders()->addTextHeader('Auto-Submitted', 'auto-replied');
            });
        } catch (Throwable $e) {
            $this->logger->warning('[mail-reply] rejection notice not sent', ['reason' => $reason, 'error' => $e->getMessage()]);
        } finally {
            $this->translator->setLocale($previousLocale);
        }
    }
}
