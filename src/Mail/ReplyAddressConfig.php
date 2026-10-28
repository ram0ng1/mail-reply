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

use Flarum\Settings\SettingsRepositoryInterface;
use RuntimeException;

/**
 * Caixa que recebe as respostas, independente do `mail_from` do fórum e
 * possivelmente em outro provedor. De `inbox@reply.exemplo.com` sai o alias
 * `inbox+u42d123-{sig}@reply.exemplo.com`.
 */
class ReplyAddressConfig
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * Descarta um `+tag` colado no endereço, senão o alias viraria
     * `inbox+x+token@…`.
     *
     * @return array{local: string, domain: string}|null
     */
    public function parts(): ?array
    {
        $address = trim((string) $this->settings->get('ramon-mail-reply.reply_address'));

        if ($address === '' || ! str_contains($address, '@')) {
            return null;
        }

        $at = strrpos($address, '@');
        $local = substr($address, 0, $at);
        $domain = substr($address, $at + 1);

        if ($local === '' || $domain === '') {
            return null;
        }

        if (($plus = strpos($local, '+')) !== false) {
            $local = substr($local, 0, $plus);
        }

        return ['local' => $local, 'domain' => $domain];
    }

    public function requireParts(): array
    {
        $parts = $this->parts();

        if ($parts === null) {
            throw new RuntimeException(
                'Mail Reply address is not configured. Set "Reply mailbox" under Admin → Extensions → Mail Reply.',
            );
        }

        return $parts;
    }

    /**
     * Caixa real no servidor IMAP; os aliases `+algo` chegam nela quando o
     * provedor suporta subendereçamento.
     */
    public function baseAddress(): ?string
    {
        $parts = $this->parts();

        return $parts === null ? null : $parts['local'].'@'.$parts['domain'];
    }
}
