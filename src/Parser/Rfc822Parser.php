<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\MailReply\Parser;

use Ramon\MailReply\Inbound\RecipientExtractor;

/**
 * Parser mínimo de mensagens RFC 822 — headers, remetente, destinatários
 * e o primeiro text/plain + text/html (descendo em multipart aninhado),
 * já decodificados e convertidos para UTF-8. Anexos são ignorados.
 */
class Rfc822Parser
{
    /**
     * @return array{
     *     to: array<int, string>,
     *     from: string,
     *     subject: string,
     *     body_text: string,
     *     body_html: string,
     *     message_id: string,
     *     headers: array<string, array<int, string>>
     * }
     */
    public function parse(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        [$headerBlock, $body] = array_pad(explode("\n\n", $raw, 2), 2, '');

        $headers = self::parseHeaderBlock($headerBlock);

        [$bodyText, $bodyHtml] = $this->extractBody($headers, $body);

        // Sem parte de texto num multipart (só imagem, só PGP, só convite) o
        // corpo cru é MIME, não resposta: fica vazio e vira `empty_body`.
        $multipart = str_starts_with(strtolower(self::first($headers, 'content-type')), 'multipart/');

        if (! $multipart && trim($bodyText) === '' && trim($bodyHtml) === '' && trim($body) !== '') {
            $bodyText = $body;
        }

        return [
            'to'         => RecipientExtractor::fromHeaders($headers),
            'from'       => RecipientExtractor::firstAddress(self::first($headers, 'from')),
            'subject'    => self::decodeWords(self::first($headers, 'subject')),
            'body_text'  => $bodyText,
            'body_html'  => $bodyHtml,
            'message_id' => trim(self::first($headers, 'message-id')),
            'headers'    => $headers,
        ];
    }

    /**
     * Headers com nome em minúsculas e todos os valores preservados —
     * `Received` e `Delivered-To` aparecem várias vezes. Linhas de
     * continuação (começam com espaço/tab) são unidas à anterior.
     *
     * @return array<string, array<int, string>>
     */
    public static function parseHeaderBlock(string $headerBlock): array
    {
        $headers = [];
        $current = null;

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $headerBlock)) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                $last = array_key_last($headers[$current]);
                $headers[$current][$last] .= ' '.trim($line);
                continue;
            }

            if (! str_contains($line, ':')) {
                $current = null;
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $current = strtolower(trim($name));
            $headers[$current][] = trim($value);
        }

        return $headers;
    }

    /**
     * @param array<string, array<int, string>|string> $headers
     */
    public static function first(array $headers, string $name): string
    {
        $value = $headers[$name] ?? '';

        return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
    }

    /**
     * Decodifica encoded-words RFC 2047 (`=?UTF-8?B?...?=`).
     */
    public static function decodeWords(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return $value;
        }

        $decoded = function_exists('iconv_mime_decode')
            ? @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8')
            : false;

        return is_string($decoded) ? $decoded : $value;
    }

    /**
     * @param array<string, array<int, string>> $headers
     * @return array{0: string, 1: string}
     */
    protected function extractBody(array $headers, string $body): array
    {
        $contentType = self::first($headers, 'content-type');
        $type = strtolower($contentType);

        if (str_starts_with($type, 'multipart/')) {
            $boundary = $this->extractBoundary($contentType) ?? $this->detectBoundaryFromBody($body);

            if ($boundary !== null) {
                return $this->extractMultipart($body, $boundary);
            }
        }

        $body = $this->decode($body, strtolower(self::first($headers, 'content-transfer-encoding')), $contentType);

        if (str_starts_with($type, 'text/html')) {
            return ['', $body];
        }

        return [$body, ''];
    }

    /**
     * Lê o boundary preservando maiúsculas — boundaries são
     * case-sensitive (RFC 2046) e clientes como o Apple Mail os geram
     * com letras maiúsculas.
     */
    protected function extractBoundary(string $contentType): ?string
    {
        if (preg_match('/boundary\s*=\s*"([^"]+)"/i', $contentType, $m)) {
            return $m[1];
        }
        if (preg_match("/boundary\\s*=\\s*'([^']+)'/i", $contentType, $m)) {
            return $m[1];
        }
        if (preg_match('/boundary\s*=\s*([^\s;"\']+)/i', $contentType, $m)) {
            return $m[1];
        }

        return null;
    }

    protected function detectBoundaryFromBody(string $body): ?string
    {
        if (preg_match('/^--([^\s\-][^\r\n]*?)$/m', $body, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function extractMultipart(string $body, string $boundary, int $depth = 0): array
    {
        $parts = array_slice(explode('--'.$boundary, $body), 1);
        $text = '';
        $html = '';

        foreach ($parts as $part) {
            // Só o resto da linha do boundary sai: a linha em branco que vem
            // logo depois marca uma parte sem cabeçalhos (text/plain).
            $part = (string) preg_replace('/^[ \t]*\n/', '', $part, 1);
            if (trim($part) === '' || str_starts_with($part, '--')) {
                continue;
            }

            [$partHeadersBlock, $partBody] = str_starts_with($part, "\n")
                ? ['', substr($part, 1)]
                : array_pad(explode("\n\n", $part, 2), 2, '');
            $partHeaders = self::parseHeaderBlock($partHeadersBlock);
            $partContentType = self::first($partHeaders, 'content-type');
            $partType = strtolower($partContentType);

            if (str_starts_with(strtolower(self::first($partHeaders, 'content-disposition')), 'attachment')) {
                continue;
            }

            if (str_starts_with($partType, 'multipart/') && $depth < 5) {
                $innerBoundary = $this->extractBoundary($partContentType) ?? $this->detectBoundaryFromBody($partBody);
                if ($innerBoundary !== null) {
                    [$innerText, $innerHtml] = $this->extractMultipart($partBody, $innerBoundary, $depth + 1);
                    $text = $text === '' ? $innerText : $text;
                    $html = $html === '' ? $innerHtml : $html;
                    continue;
                }
            }

            $isText = str_starts_with($partType, 'text/plain') || $partType === '';
            $isHtml = str_starts_with($partType, 'text/html');

            if (! ($isText && $text === '') && ! ($isHtml && $html === '')) {
                continue;
            }

            $decoded = $this->decode(rtrim($partBody, "\n"), strtolower(self::first($partHeaders, 'content-transfer-encoding')), $partContentType);

            if ($isText) {
                $text = $decoded;
            } else {
                $html = $decoded;
            }
        }

        return [$text, $html];
    }

    protected function decode(string $content, string $encoding, string $contentType = ''): string
    {
        $content = match (trim($encoding)) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $content) ?? '', false),
            'quoted-printable' => quoted_printable_decode($content),
            default => $content,
        };

        return self::toUtf8($content, $contentType);
    }

    /**
     * Converte o corpo para UTF-8 a partir do `charset` do Content-Type.
     * Sem charset declarado, só converte quando o conteúdo não é UTF-8
     * válido (assume Windows-1252, o legado mais comum).
     */
    public static function toUtf8(string $content, string $contentType): string
    {
        $charset = preg_match('/charset\s*=\s*"?([A-Za-z0-9._:\-]+)"?/i', $contentType, $m) ? strtolower($m[1]) : '';

        if (in_array($charset, ['utf-8', 'utf8', 'us-ascii', 'ascii'], true)) {
            return mb_check_encoding($content, 'UTF-8') ? $content : mb_scrub($content, 'UTF-8');
        }

        if ($charset === '') {
            return mb_check_encoding($content, 'UTF-8') ? $content : (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        if (in_array(strtoupper($charset), array_map('strtoupper', mb_list_encodings()), true)) {
            return (string) mb_convert_encoding($content, 'UTF-8', $charset);
        }

        $converted = function_exists('iconv') ? @iconv($charset, 'UTF-8//IGNORE', $content) : false;

        return is_string($converted) ? $converted : mb_scrub($content, 'UTF-8');
    }
}
