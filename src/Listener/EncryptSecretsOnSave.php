<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\MailReply\Listener;

use Flarum\Settings\Event\Saving;
use Ramon\MailReply\Service\SecretBox;

/**
 * Cifra a senha IMAP e a signing key do Mailgun no momento em que o
 * admin salva, para que nunca fiquem em texto puro na tabela `settings`.
 * Valores já cifrados (o campo devolve o `mp1:` salvo) passam intactos.
 */
class EncryptSecretsOnSave
{
    private const KEYS = [
        'ramon-mail-reply.imap_password',
        'ramon-mail-reply.mailgun_signing_key',
    ];

    public function __construct(
        protected SecretBox $secretBox,
    ) {
    }

    public function handle(Saving $event): void
    {
        foreach (self::KEYS as $key) {
            $value = $event->settings[$key] ?? null;

            if (is_string($value) && $value !== '' && ! SecretBox::isEncrypted($value)) {
                $event->settings[$key] = $this->secretBox->encode($value);
            }
        }
    }
}
