<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Entrega as mensagens do cenário como os provedores fazem, sem MTA:
 * Postmark (JSON), Mailgun (formulário assinado), Cloudflare Email Workers
 * (RFC 822 cru) e o JSON canônico. Não usa nenhuma classe da extensão para
 * montar o payload: o MIME é aberto aqui mesmo, de forma independente.
 *
 *   php webhook.php <out-dir> <inbound-url>
 */

declare(strict_types=1);

[$out, $url] = [rtrim($argv[1], '/'), $argv[2]];
$manifest = json_decode((string) file_get_contents($out.'/manifest.json'), true);
$styles = ['postmark', 'mailgun', 'cloudflare', 'canonical'];
$extra = [];

foreach (glob($out.'/messages/*.eml') ?: [] as $i => $file) {
    $raw = (string) file_get_contents($file);
    $style = $styles[$i % count($styles)];
    [$headers, $text, $html] = parseMime($raw);
    $first = fn (string $name) => $headers[$name][0] ?? '';

    switch ($style) {
        case 'postmark':
            $body = json_encode([
                'From' => address($first('from')),
                'To' => $first('to'),
                'ToFull' => [['Email' => address($first('to')), 'Name' => '']],
                'Subject' => decodeWords($first('subject')),
                'TextBody' => $text,
                'HtmlBody' => $html,
                'Headers' => array_merge(...array_map(
                    fn ($name, $values) => array_map(fn ($v) => ['Name' => $name, 'Value' => $v], $values),
                    array_keys($headers),
                    $headers,
                )),
            ]);
            $status = post($url, $body, ['Content-Type: application/json', 'X-Mail-Reply-Token: '.$manifest['webhook_secret']]);
            break;
        case 'mailgun':
            $timestamp = (string) time();
            $token = bin2hex(random_bytes(25));
            $pairs = [];
            foreach ($headers as $name => $values) {
                foreach ($values as $v) {
                    $pairs[] = [$name, $v];
                }
            }
            $body = http_build_query([
                'recipient' => address($first('to')),
                'sender' => address($first('from')),
                'from' => $first('from'),
                'subject' => decodeWords($first('subject')),
                'body-plain' => $text,
                'body-html' => $html,
                'message-headers' => json_encode($pairs),
                'timestamp' => $timestamp,
                'token' => $token,
                'signature' => hash_hmac('sha256', $timestamp.$token, $manifest['mailgun_key']),
            ]);
            // Rotas do Mailgun não mandam header próprio: vale só a assinatura.
            $status = post($url, $body, ['Content-Type: application/x-www-form-urlencoded']);
            break;
        case 'cloudflare':
            $status = post($url, $raw, ['Content-Type: message/rfc822', 'X-Mail-Reply-Token: '.$manifest['webhook_secret']]);
            break;
        default:
            $body = json_encode([
                'to' => address($first('to')),
                'from' => address($first('from')),
                'subject' => decodeWords($first('subject')),
                'body_text' => $text,
                'body_html' => $html,
                'message_id' => trim($first('message-id'), '<> '),
                'headers' => array_map(fn ($v) => $v[0], $headers),
            ]);
            $status = post($url, $body, ['Content-Type: application/json', 'X-Mail-Reply-Token: '.$manifest['webhook_secret']]);
    }

    printf("%-22s %-10s HTTP %d\n", basename($file), $style, $status);
    $extra[] = ['case' => 'http-'.basename($file, '.eml'), 'ok' => $status === 202, 'detail' => "$style → HTTP $status"];
}

// Sem token nenhum o endpoint tem de recusar.
$status = post($url, '{"to":"x@forum.test"}', ['Content-Type: application/json']);
$extra[] = ['case' => 'http-sem-token', 'ok' => $status === 401, 'detail' => "HTTP $status"];

file_put_contents($out.'/extra-checks.json', json_encode($extra, JSON_UNESCAPED_UNICODE));

function post(string $url, string $body, array $headers): int
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    curl_exec($ch);

    return (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
}

function address(string $value): string
{
    return preg_match('/<([^>]+)>/', $value, $m) ? $m[1] : trim($value);
}

function decodeWords(string $value): string
{
    return iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: $value;
}

/**
 * @return array{0: array<string, list<string>>, 1: string, 2: string}
 */
function parseMime(string $raw): array
{
    $raw = str_replace("\r\n", "\n", $raw);
    [$head, $body] = explode("\n\n", $raw, 2) + [1 => ''];
    $headers = [];
    foreach (explode("\n", preg_replace('/\n[ \t]+/', ' ', $head)) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))][] = trim($v);
        }
    }
    $type = $headers['content-type'][0] ?? 'text/plain';
    $text = $html = '';

    if (preg_match('/boundary="?([^";]+)"?/i', $type, $m)) {
        foreach (explode('--'.$m[1], $body) as $part) {
            [$ph, $pb] = explode("\n\n", ltrim($part, "\n"), 2) + [1 => ''];
            $decoded = decodePart($ph, $pb);
            if (stripos($ph, 'text/plain') !== false) {
                $text = $decoded;
            } elseif (stripos($ph, 'text/html') !== false) {
                $html = $decoded;
            }
        }
    } else {
        $text = decodePart(implode("\n", array_map(fn ($k) => $k.': '.$headers[$k][0], array_keys($headers))), $body);
    }

    return [$headers, $text, $html];
}

function decodePart(string $head, string $body): string
{
    if (stripos($head, 'quoted-printable') !== false) {
        $body = quoted_printable_decode($body);
    } elseif (stripos($head, 'base64') !== false) {
        $body = (string) base64_decode($body);
    }
    if (preg_match('/charset="?([\w-]+)/i', $head, $m) && strtolower($m[1]) !== 'utf-8') {
        $body = (string) iconv($m[1], 'UTF-8', $body);
    }

    return rtrim($body, "\n");
}
