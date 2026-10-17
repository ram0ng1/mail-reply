<?php

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
            if (preg_match('/\bfor\s+<?([^\s<>;@]+@[^\s<>;]+?)>?\s*(?:;|$)/i', (string) $value, $m)) {
                $out[] = $m[1];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Divide uma lista de endereços respeitando vírgulas dentro de aspas.
     *
     * @return array<int, string>
     */
    public static function splitAddresses(string $value): array
    {
        $parts = preg_split('/,\s*(?=(?:[^"]*"[^"]*")*[^"]*$)/', $value) ?: [];
        $out = [];

        foreach ($parts as $part) {
            $address = self::firstAddress($part);
            if ($address !== '') {
                $out[] = $address;
            }
        }

        return $out;
    }

    public static function firstAddress(string $value): string
    {
        if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', $value, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/[a-z0-9._%+\-=]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $value, $m)) {
            return trim($m[0]);
        }

        return '';
    }
}
