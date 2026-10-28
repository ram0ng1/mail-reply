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

use EmailReplyParser\EmailReplyParser as WdEmailReplyParser;

/**
 * Extrai o texto novo de uma resposta, sem assinatura, histórico citado nem
 * restos de MIME. O corte por marcador de citação roda antes do
 * willdurand/email-reply-parser, que só reconhece o formato em inglês. Se a
 * limpeza apagar tudo, recua para estratégias mais leves.
 */
class EmailParser
{
    private const RAW_FALLBACK_LINES = 30;

    public function extractReply(string $rawBody, bool $stripSignatures = true): string
    {
        if (trim($rawBody) === '') {
            return '';
        }

        $cleaned = $this->cleanupMimeArtifacts($rawBody);

        $text = $this->toPlainText($cleaned);

        if (trim($text) === '') {
            return '';
        }

        $text = $this->truncateAtQuoteMarker($text);

        if (trim($text) === '') {
            return '';
        }

        if ($stripSignatures) {
            $parsed = trim($this->stripMobileSignature(WdEmailReplyParser::parseReply($text)));
            if ($parsed !== '') {
                return $parsed;
            }
        }

        $parsed = trim($this->stripQuotesOnly($text));
        if ($parsed !== '') {
            return $parsed;
        }

        return $this->firstNonEmptyLines($text, self::RAW_FALLBACK_LINES);
    }

    /**
     * Remove boundaries e cabeçalhos MIME que sobram quando o Rfc822Parser
     * não consegue isolar a parte de texto. Duas passadas porque uma linha
     * em branco intercalada esconde um cabeçalho da primeira.
     */
    protected function cleanupMimeArtifacts(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        if (preg_match('/^Content-Transfer-Encoding:\s*quoted-printable/im', $body)) {
            $body = quoted_printable_decode($body);
        }
        if (preg_match('/^Content-Transfer-Encoding:\s*base64/im', $body)) {
            $body = preg_replace_callback(
                '/(?:^[A-Za-z0-9+\/=]{60,}\s*$\n?)+/m',
                static fn ($m) => base64_decode($m[0], false) ?: $m[0],
                $body,
            ) ?? $body;
        }

        $body = $this->stripMimeLines($body);
        $body = $this->stripMimeLines($body);

        return $body;
    }

    /**
     * O padrão de boundary `^--\S` é permissivo de propósito; continuações
     * indentadas de cabeçalho também saem.
     */
    protected function stripMimeLines(string $body): string
    {
        $lines = explode("\n", $body);
        $kept = [];
        $skipNextBlank = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^--(?!-*$)\S+$/', $trimmed)) {
                $skipNextBlank = true;
                continue;
            }

            if (preg_match('/^(Content-Type|Content-Transfer-Encoding|Content-Disposition|Content-ID|Content-Description|MIME-Version)\s*[:=]/i', $line)) {
                $skipNextBlank = true;
                continue;
            }

            if ($skipNextBlank && preg_match('/^\s+\S/', $line) && trim($line) !== '') {
                continue;
            }

            if ($skipNextBlank && $trimmed === '') {
                $skipNextBlank = false;
                continue;
            }

            $skipNextBlank = false;
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /**
     * HTML vira texto preservando a estrutura de linhas que o detector de
     * citação usa: blocos (`div`, `p`, `li`, `tr`…) e `<br>` viram quebras.
     * Antes disso, os contêineres de citação dos clientes de e-mail
     * (`blockquote`, `.gmail_quote`, `#divRplyFwdMsg` do Outlook e tudo
     * que vem depois dele, `.yahoo_quoted`) são removidos.
     */
    protected function toPlainText(string $body): string
    {
        if (preg_match('/<(html|body|div|p|br|blockquote|table|span)[\s>\/]/i', $body)) {
            $body = $this->stripQuoteContainers($body);
            $body = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $body) ?? $body;
            $body = preg_replace('#<br\s*/?>#i', "\n", $body) ?? $body;
            $body = preg_replace('#</?(div|p|li|ul|ol|tr|table|h[1-6]|blockquote|pre|hr)\b[^>]*>#i', "\n", $body) ?? $body;
            $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $body = preg_replace('/[ \t\x{00A0}]+\n/u', "\n", $body) ?? $body;
            $body = preg_replace('/\n{3,}/', "\n\n", $body) ?? $body;
            $body = trim($body, "\n");
        }

        return str_replace(["\r\n", "\r"], "\n", $body);
    }

    protected function stripQuoteContainers(string $html): string
    {
        if (! class_exists(\DOMDocument::class)) {
            return preg_replace('#<blockquote\b.*?</blockquote>#is', '', $html) ?? $html;
        }

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8"><div id="mr-root">'.$html.'</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return $html;
        }

        $xpath = new \DOMXPath($doc);

        foreach (iterator_to_array($xpath->query('//*[@id="divRplyFwdMsg" or @id="appendonsend"]') ?: []) as $node) {
            while ($node->nextSibling !== null) {
                $node->parentNode?->removeChild($node->nextSibling);
            }
            $node->parentNode?->removeChild($node);
        }

        $quotes = '//blockquote'
            .' | //*[contains(concat(" ", normalize-space(@class), " "), " gmail_quote ")]'
            .' | //*[contains(concat(" ", normalize-space(@class), " "), " yahoo_quoted ")]'
            .' | //*[contains(concat(" ", normalize-space(@class), " "), " moz-cite-prefix ")]';

        foreach (iterator_to_array($xpath->query($quotes) ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $root = $doc->getElementById('mr-root');

        if ($root === null) {
            return $html;
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    /**
     * Trunca o body no primeiro marcador de quote/forward, independente do
     * idioma do cliente: sinais estruturais, não palavras por locale.
     *
     *   - "Original Message" / "Forwarded message" entre traços;
     *   - linha de atribuição ("Em …, Fulano <x@y> escreveu:", "On … wrote:",
     *     `2026年1月5日 … <x@y>:`): termina com `:` e tem ano de 4 dígitos com
     *     vírgula ou hora, ou um `<email>`. O Gmail quebra a linha longa em
     *     duas no text/plain, então a linha seguinte também é tentada;
     *   - bloco de cabeçalho do Outlook (`De: Fulano <x@y>` seguido de
     *     `Enviado:`, `Para:`…).
     *
     * Atribuição só corta quando é crível: tem um e-mail ou vem seguida de
     * linhas citadas com `>`. Assim "Oi pessoal, os números de 2025:" no
     * meio da resposta não apaga o resto.
     */
    protected function truncateAtQuoteMarker(string $text): string
    {
        $lines = explode("\n", $text);
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            if (preg_match('/^\s*-{2,}\s*\S[^\n]*\S\s*-{2,}\s*$/u', $line)) {
                return implode("\n", array_slice($lines, 0, $i));
            }

            foreach ([1, 2] as $span) {
                if ($i + $span > $count) {
                    break;
                }

                $candidate = implode(' ', array_map('trim', array_slice($lines, $i, $span)));

                if ($this->isAttribution($candidate) && $this->isCredibleAttribution($candidate, $lines, $i + $span)) {
                    return implode("\n", array_slice($lines, 0, $i));
                }
            }

            if (preg_match('/^\s*\S{1,30}\s*[:：]\s+.*<[^@\s<>]+@[^@\s<>]+>/u', $line) && $this->headerFollows($lines, $i + 1)) {
                return implode("\n", array_slice($lines, 0, $i));
            }
        }

        return $text;
    }

    protected function isAttribution(string $line): bool
    {
        $patterns = [
            '/^\s*>?\s*(?=.*[\d\p{N}]{4})(?=.*[,，、]).+[:：]\s*$/u',
            '/^\s*>?\s*(?=.*[\d\p{N}]{4})(?=.*[\d\p{N}]{1,2}:[\d\p{N}]{2}).+[:：]\s*$/u',
            '/^\s*>?\s*.*<[^@\s<>]+@[^@\s<>]+>.*[:：]\s*$/u',
        ];

        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $lines
     */
    protected function isCredibleAttribution(string $candidate, array $lines, int $next): bool
    {
        if (preg_match('/[^\s<>@]+@[^\s<>@]+\.[^\s<>@]+/u', $candidate) || str_starts_with(ltrim($candidate), '>')) {
            return true;
        }

        for ($j = $next, $n = count($lines); $j < $n; $j++) {
            if (trim($lines[$j]) !== '') {
                return str_starts_with(ltrim($lines[$j]), '>');
            }
        }

        return false;
    }

    /**
     * Outro campo de cabeçalho (`Enviado:`, `Sent:`, `Para:`…) logo abaixo
     * confirma o bloco colado pelo Outlook.
     *
     * @param array<int, string> $lines
     */
    protected function headerFollows(array $lines, int $from): bool
    {
        for ($j = $from, $n = min(count($lines), $from + 4); $j < $n; $j++) {
            if (preg_match('/^\s*\S{1,30}\s*[:：]\s+\S/u', $lines[$j])) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Enviado do meu iPhone" e afins no fim da resposta: o
     * email-reply-parser só conhece a versão em inglês.
     */
    protected function stripMobileSignature(string $text): string
    {
        $pattern = '/\n\s*(sent from my .{1,40}|enviado do meu .{1,40}|enviado de meu .{1,40}|enviado desde mi .{1,40}|'
            .'envoy[ée] de mon .{1,40}|von meinem .{1,40} gesendet|inviato da(l mio)? .{1,40}|'
            .'(get|obter o|baixe o) outlook (for|para) (ios|android))\s*$/iu';

        return rtrim((string) preg_replace($pattern, '', "\n".$text), "\n");
    }

    /**
     * Limpeza leve: corta na primeira linha de atribuição ou
     * "Original Message" e descarta linhas citadas com `>`.
     */
    protected function stripQuotesOnly(string $text): string
    {
        $lines = explode("\n", $text);
        $kept = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*(On .+ wrote:|Em .+ escreveu:|-----\s?Original Message\s?-----|Forwarded message)/i', $line)) {
                break;
            }

            if (preg_match('/^\s*>/', $line)) {
                continue;
            }

            $kept[] = $line;
        }

        return rtrim(implode("\n", $kept));
    }

    /**
     * Último recurso quando as outras limpezas apagam tudo.
     */
    protected function firstNonEmptyLines(string $text, int $maxLines): string
    {
        $lines = explode("\n", $text);
        $kept = [];
        $count = 0;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                if ($kept !== [] && end($kept) !== '') {
                    $kept[] = '';
                }
                continue;
            }

            $kept[] = $line;
            $count++;

            if ($count >= $maxLines) {
                break;
            }
        }

        return rtrim(implode("\n", $kept));
    }
}
