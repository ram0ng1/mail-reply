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

/**
 * Coleta os destinatários de uma mensagem na ordem em que o alias
 * `+TAG` tem mais chance de sobreviver: headers de envelope primeiro
 * (preservam o endereço original quando o MTA reescreve o `To:`
 * visível, em BCC ou em encaminhamentos), depois `To`/`Cc`, por fim o
 * `for <addr>` dos headers `Received`.
 */
class RecipientExtractor
{
    private const ENVELOPE_HEADERS = [
        'envelope-to', 'x-original-to', 'delivered-to', 'x-envelope-to',
        'x-delivered-to', 'x-forwarded-to',
    ];

    /**
     * @param array<string, array<int, string>|string> $headers nomes em minúsculas
     * @return array<int, string>
     */
    public static function fromHeaders(array $headers): array
    {
        $out = [];

        foreach ([...self::ENVELOPE_HEADERS, 'to', 'cc'] as $name) {
            foreach ((array) ($headers[$name] ?? []) as $value) {
                foreach (self::splitAddresses((string) $value) as $address) {
                    $out[] = $address;
                }
            }
        }

        foreach ((array) ($headers['received'] ?? []) as $value) {
            if (preg_match('/\bfor\s+<?([^\s<>;@]+@[^\s<>;]+?)>?\s*(?:;|$)/i', substr((string) $value, 0, 2048), $m)) {
                $out[] = $m[1];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Teto de destinatários por cabeçalho: uma resposta legítima tem poucos,
     * e o resto não pode custar CPU a quem lê a caixa.
     */
    public const MAX_ADDRESSES_PER_HEADER = 100;

    /**
     * Divide uma lista de endereços nas vírgulas fora de aspas, numa só
     * passada. O cabeçalho vem de qualquer remetente antes de o alias ser
     * validado, então o custo tem de ser linear no tamanho.
     *
     * @return array<int, string>
     */
    public static function splitAddresses(string $value): array
    {
        $out = [];
        $start = 0;
        $quoted = false;
        $length = strlen($value);

        for ($i = 0; $i <= $length && count($out) < self::MAX_ADDRESSES_PER_HEADER; $i++) {
            $char = $i < $length ? $value[$i] : ',';

            if ($char === '"' && ($i === 0 || $value[$i - 1] !== '\\')) {
                $quoted = ! $quoted;
            } elseif ($char === ',' && (! $quoted || $i === $length)) {
                $address = self::firstAddress(substr($value, $start, $i - $start));
                if ($address !== '') {
                    $out[] = $address;
                }
                $start = $i + 1;
            }
        }

        return $out;
    }

    public static function firstAddress(string $value): string
    {
        // Um endereço com nome de exibição cabe com folga; o corte mantém as
        // regex abaixo baratas com entrada hostil.
        $value = substr($value, 0, 1024);

        if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', $value, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/[a-z0-9._%+\-=]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $value, $m)) {
            return trim($m[0]);
        }

        return '';
    }
}
