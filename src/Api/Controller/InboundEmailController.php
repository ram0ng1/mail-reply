<?php

namespace Ramon\MailReply\Api\Controller;

use Flarum\Foundation\ErrorHandling\LogReporter;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Repository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\MailReply\Inbound\RecipientExtractor;
use Ramon\MailReply\Job\ProcessInboundEmailJob;
use Ramon\MailReply\Parser\Rfc822Parser;
use Ramon\MailReply\Service\SecretBox;
use Throwable;

/**
 * Endpoint público de inbound. Recebe respostas por e-mail vindas de:
 *
 *   1. Scripts pipe locais no MTA — RFC 822 cru via `Content-Type:
 *      message/rfc822` (caminho recomendado quando o admin não pode/quer
 *      ter o Flarum lendo IMAP direto).
 *   2. Provedores como Mailgun/Postmark — form-data ou JSON canônico.
 *
 * Autenticação: header `X-Mail-Reply-Token` com segredo configurado no
 * admin, ou assinatura do Mailgun quando a signing key está salva. CSRF é isento via `Extend\Csrf::exemptRoute()` no extend.php
 * (caller é externo, não tem session/CSRF token).
 *
 * Fora do modo `webhook` o endpoint responde 404 — evita dupla entrega
 * quando IMAP e webhook foram configurados juntos. O processamento sai
 * para o `ProcessInboundEmailJob`; o controller só normaliza e despacha.
 */
class InboundEmailController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected LogReporter $log,
        protected Rfc822Parser $rfc822,
        protected BusDispatcher $bus,
        protected Repository $cache,
        protected SecretBox $secretBox,
    ) {
    }

    /**
     * Teto do corpo bruto aceito. Respostas de fórum cabem com folga;
     * anexos grandes não são suportados e não devem prender o worker.
     */
    public const MAX_BODY_BYTES = 10 * 1024 * 1024;

    public const MAILGUN_MAX_SKEW_SECONDS = 300;

    /**
     * Detalhes de falha (classe, mensagem) vão só para o log: o caller é
     * externo e não autenticado até o hash_equals. O processamento sai do
     * request via `dispatch()` — em fila real responde na hora; no driver
     * `sync` o job roda inline e captura as próprias exceções.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ((string) $this->settings->get('ramon-mail-reply.delivery_mode') !== 'webhook') {
            return new JsonResponse(['error' => 'webhook_not_active'], 404);
        }

        if (! $this->authorize($request)) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $size = $request->getBody()->getSize();

        if ($size !== null && $size > self::MAX_BODY_BYTES) {
            return new JsonResponse(['error' => 'payload_too_large'], 413);
        }

        try {
            $payload = $this->normalise($request);
        } catch (Throwable $e) {
            $this->log->report($e);

            return new JsonResponse(['ok' => false, 'error' => 'invalid_payload'], 422);
        }

        try {
            $this->bus->dispatch(new ProcessInboundEmailJob($payload));
        } catch (Throwable $e) {
            $this->log->report($e);

            return new JsonResponse(['ok' => false, 'error' => 'dispatch_failed'], 500);
        }

        return new JsonResponse(['ok' => true, 'received' => true], 202);
    }

    /**
     * Aceita o header `X-Mail-Reply-Token` (scripts pipe, Postmark,
     * Cloudflare) ou a assinatura nativa do Mailgun, cujas Routes não
     * enviam headers customizados.
     */
    protected function authorize(ServerRequestInterface $request): bool
    {
        $expected = (string) $this->settings->get('ramon-mail-reply.webhook_secret');
        $provided = $request->getHeaderLine('X-Mail-Reply-Token');

        if ($expected !== '' && $provided !== '' && hash_equals($expected, $provided)) {
            return true;
        }

        return $this->mailgunSignatureValid($request);
    }

    /**
     * Assinatura do Mailgun: HMAC-SHA256(timestamp . token) com a signing
     * key da conta. Rejeita timestamps fora de uma janela de 5 minutos e
     * tokens já vistos (replay).
     */
    protected function mailgunSignatureValid(ServerRequestInterface $request): bool
    {
        $stored = (string) $this->settings->get('ramon-mail-reply.mailgun_signing_key');

        if ($stored === '') {
            return false;
        }

        try {
            $key = $this->secretBox->decode($stored);
        } catch (Throwable $e) {
            $this->log->report($e);

            return false;
        }

        $params = (array) $request->getParsedBody();
        $timestamp = (string) ($params['timestamp'] ?? '');
        $token = (string) ($params['token'] ?? '');
        $signature = strtolower((string) ($params['signature'] ?? ''));

        if ($key === '' || $token === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::MAILGUN_MAX_SKEW_SECONDS) {
            return false;
        }

        if (! hash_equals(hash_hmac('sha256', $timestamp.$token, $key), $signature)) {
            return false;
        }

        return $this->cache->add('mail-reply.mailgun_token.'.hash('sha256', $token), 1, self::MAILGUN_MAX_SKEW_SECONDS * 2);
    }

    /**
     * Aceita três formas de payload e devolve o formato canônico do
     * InboundProcessor:
     *
     *   1. `message/rfc822` (ou corpo com cara de headers) — RFC 822 cru,
     *      caminho dos scripts pipe e do Cloudflare Email Workers;
     *   2. JSON (Postmark ou canônico `{to, from, subject, body_text, ...}`);
     *   3. form-data do Mailgun (`recipient`, `body-plain`, `stripped-text`,
     *      `message-headers`).
     *
     * @return array{to: array<int,string>, from: string, subject: string, body_text: string, body_html: string, stripped_text: string, message_id: string, headers: array<string, array<int,string>>}
     */
    protected function normalise(ServerRequestInterface $request): array
    {
        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        $raw = (string) $request->getBody();

        if (str_contains($contentType, 'message/rfc822') || $this->looksLikeRfc822($raw, $contentType)) {
            return ['stripped_text' => ''] + $this->rfc822->parse($raw);
        }

        $params = (array) $request->getParsedBody();

        if ($params === []) {
            $json = json_decode($raw, true);
            $params = is_array($json) ? $json : [];
        }

        $headers = $this->providerHeaders($params);

        $to = $params['recipient']
            ?? $params['To']
            ?? $params['to']
            ?? null;

        $recipients = $this->mapAddressList($params['ToFull'] ?? []);
        foreach (is_array($to) ? $to : RecipientExtractor::splitAddresses((string) $to) as $address) {
            $recipients[] = (string) $address;
        }
        foreach (RecipientExtractor::fromHeaders($headers) as $address) {
            $recipients[] = $address;
        }

        $from = (string) ($params['sender'] ?? $params['From'] ?? $params['from'] ?? '');

        return [
            'to'            => array_values(array_unique(array_filter(array_map('trim', $recipients)))),
            'from'          => RecipientExtractor::firstAddress($from) ?: $from,
            'subject'       => (string) ($params['subject'] ?? $params['Subject'] ?? ''),
            'body_text'     => (string) ($params['body-plain'] ?? $params['TextBody'] ?? $params['body_text'] ?? $params['text'] ?? ''),
            'body_html'     => (string) ($params['body-html'] ?? $params['HtmlBody'] ?? $params['body_html'] ?? $params['html'] ?? ''),
            'stripped_text' => (string) ($params['stripped-text'] ?? $params['StrippedTextReply'] ?? $params['stripped_text'] ?? ''),
            'message_id'    => (string) ($params['Message-Id'] ?? $params['message-id'] ?? $params['message_id'] ?? Rfc822Parser::first($headers, 'message-id')),
            'headers'       => $headers,
        ];
    }

    /**
     * Headers originais nos formatos dos provedores: Mailgun manda
     * `message-headers` como JSON `[[nome, valor], ...]`; Postmark manda
     * `Headers` como `[{Name, Value}, ...]`; o formato canônico aceita um
     * mapa `{nome: valor}`.
     *
     * @param array<string, mixed> $params
     * @return array<string, array<int, string>>
     */
    protected function providerHeaders(array $params): array
    {
        $list = $params['message-headers'] ?? $params['Headers'] ?? $params['headers'] ?? [];

        if (is_string($list)) {
            $list = json_decode($list, true) ?: [];
        }

        $headers = [];

        foreach ((array) $list as $key => $entry) {
            if (is_array($entry) && isset($entry[0], $entry[1])) {
                [$name, $value] = [$entry[0], $entry[1]];
            } elseif (is_array($entry) && isset($entry['Name'], $entry['Value'])) {
                [$name, $value] = [$entry['Name'], $entry['Value']];
            } elseif (is_string($key) && is_scalar($entry)) {
                [$name, $value] = [$key, $entry];
            } else {
                continue;
            }

            if (is_scalar($name) && is_scalar($value)) {
                $headers[strtolower(trim((string) $name))][] = trim((string) $value);
            }
        }

        return $headers;
    }

    /**
     * Heurística para detectar RFC 822 cru sem Content-Type adequado.
     */
    protected function looksLikeRfc822(string $raw, string $contentType): bool
    {
        if (str_contains($contentType, 'application/json')) {
            return false;
        }

        $head = ltrim(substr($raw, 0, 2048));

        if ($head === '' || $head[0] === '{' || $head[0] === '[' || $head[0] === '<') {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z][A-Za-z0-9\-]*:\s/', $head);
    }

    /**
     * @param array<int|string, mixed> $list
     * @return array<int, string>
     */
    protected function mapAddressList(array $list): array
    {
        $out = [];

        foreach ($list as $entry) {
            if (is_array($entry) && isset($entry['Email'])) {
                $out[] = (string) $entry['Email'];
            } elseif (is_string($entry)) {
                $out[] = $entry;
            }
        }

        return $out;
    }
}
