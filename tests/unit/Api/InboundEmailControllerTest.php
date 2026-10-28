<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Ramon\MailReply\Tests\unit\Api;

use Flarum\Foundation\ErrorHandling\LogReporter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Ramon\MailReply\Api\Controller\InboundEmailController;
use Ramon\MailReply\Job\ProcessInboundEmailJob;
use Ramon\MailReply\Parser\Rfc822Parser;
use Ramon\MailReply\Service\SecretBox;
use Ramon\MailReply\Tests\Support\ArraySettings;
use Ramon\MailReply\Tests\Support\MemoryLogger;
use Ramon\MailReply\Tests\Support\Secrets;

final class InboundEmailControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const WEBHOOK_SECRET = 'test-webhook-secret';
    private const SIGNING_KEY = 'test-signing-key';

    private ArraySettings $settings;
    private Repository $cache;
    private SecretBox $box;
    private MemoryLogger $logger;

    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        if (! SecretBox::isSupported()) {
            self::markTestSkipped('libsodium XChaCha20-Poly1305 is not available.');
        }

        $this->box = Secrets::box();
        $this->settings = new ArraySettings([
            'ramon-mail-reply.delivery_mode'       => 'webhook',
            'ramon-mail-reply.webhook_secret'      => self::WEBHOOK_SECRET,
            'ramon-mail-reply.mailgun_signing_key' => $this->box->encode(self::SIGNING_KEY),
        ]);
        $this->cache = new Repository(new ArrayStore());
        $this->logger = new MemoryLogger();
        $this->dispatched = [];
    }

    private function controller(?BusDispatcher $bus = null): InboundEmailController
    {
        if ($bus === null) {
            $bus = Mockery::mock(BusDispatcher::class);
            $bus->shouldReceive('dispatch')->andReturnUsing(function (object $job) {
                $this->dispatched[] = $job;

                return null;
            });
        }

        return new InboundEmailController(
            $this->settings,
            new LogReporter($this->logger),
            new Rfc822Parser(),
            $bus,
            $this->cache,
            $this->box,
        );
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $parsedBody
     */
    private static function request(string $body = '', array $headers = [], ?array $parsedBody = null, ?StreamInterface $stream = null): ServerRequest
    {
        if ($stream === null) {
            $stream = new Stream('php://temp', 'wb+');
            $stream->write($body);
            $stream->rewind();
        }

        $request = new ServerRequest([], [], '/api/mail-reply/inbound', 'POST', $stream, $headers);

        return $parsedBody === null ? $request : $request->withParsedBody($parsedBody);
    }

    /**
     * @return array<string, mixed>
     */
    private static function mailgunForm(string $key = self::SIGNING_KEY, ?int $timestamp = null, string $token = 'test-token'): array
    {
        $timestamp ??= time();

        return [
            'timestamp'     => (string) $timestamp,
            'token'         => $token,
            'signature'     => hash_hmac('sha256', $timestamp.$token, $key),
            'recipient'     => 'reply+u1d2-0123456789abcdef@reply.forum.test',
            'sender'        => 'john@example.com',
            'subject'       => 'Re: Olá',
            'body-plain'    => 'Mailgun body',
            'stripped-text' => 'Mailgun body',
            'Message-Id'    => '<mg-1@example.com>',
        ];
    }

    private function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    private function jsonRequest(string $token): ServerRequest
    {
        $body = (string) json_encode([
            'to'         => 'reply+u1d2-0123456789abcdef@reply.forum.test',
            'from'       => 'John <john@example.com>',
            'subject'    => 'Re: Olá',
            'body_text'  => 'JSON body',
            'message_id' => '<json-1@example.com>',
        ]);

        return self::request($body, ['Content-Type' => 'application/json', 'X-Mail-Reply-Token' => $token]);
    }

    public function test_valid_token_header_dispatches_job_and_returns_202(): void
    {
        $response = $this->controller()->handle($this->jsonRequest(self::WEBHOOK_SECRET));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['ok' => true, 'received' => true], $this->json($response));
        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(ProcessInboundEmailJob::class, $this->dispatched[0]);

        $payload = $this->dispatched[0]->payload;
        self::assertSame(['reply+u1d2-0123456789abcdef@reply.forum.test'], $payload['to']);
        self::assertSame('john@example.com', $payload['from']);
        self::assertSame('JSON body', $payload['body_text']);
        self::assertSame('<json-1@example.com>', $payload['message_id']);
    }

    public function test_wrong_token_header_is_unauthorized(): void
    {
        $response = $this->controller()->handle($this->jsonRequest('wrong-secret'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $this->dispatched);
    }

    public function test_empty_configured_secret_never_matches_an_empty_header(): void
    {
        $this->settings->values['ramon-mail-reply.webhook_secret'] = '';
        unset($this->settings->values['ramon-mail-reply.mailgun_signing_key']);

        self::assertSame(401, $this->controller()->handle($this->jsonRequest(''))->getStatusCode());
    }

    public function test_valid_mailgun_signature_dispatches_normalised_payload(): void
    {
        $response = $this->controller()->handle(self::request('', ['Content-Type' => 'multipart/form-data'], self::mailgunForm()));

        self::assertSame(202, $response->getStatusCode());
        self::assertCount(1, $this->dispatched);

        $payload = $this->dispatched[0]->payload;
        self::assertSame(['reply+u1d2-0123456789abcdef@reply.forum.test'], $payload['to']);
        self::assertSame('john@example.com', $payload['from']);
        self::assertSame('Mailgun body', $payload['stripped_text']);
        self::assertSame('<mg-1@example.com>', $payload['message_id']);
    }

    public function test_legacy_plaintext_signing_key_is_still_accepted(): void
    {
        $this->settings->values['ramon-mail-reply.mailgun_signing_key'] = self::SIGNING_KEY;

        $response = $this->controller()->handle(self::request('', [], self::mailgunForm()));

        self::assertSame(202, $response->getStatusCode());
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function staleOffsets(): array
    {
        return [
            'too old'       => [-301],
            'too far ahead' => [301],
        ];
    }

    #[DataProvider('staleOffsets')]
    public function test_mailgun_timestamp_outside_the_window_is_rejected(int $offset): void
    {
        $response = $this->controller()->handle(self::request('', [], self::mailgunForm(timestamp: time() + $offset)));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $this->dispatched);
    }

    public function test_replayed_mailgun_token_is_rejected(): void
    {
        $form = self::mailgunForm();
        $controller = $this->controller();

        self::assertSame(202, $controller->handle(self::request('', [], $form))->getStatusCode());
        self::assertSame(401, $controller->handle(self::request('', [], $form))->getStatusCode());
        self::assertCount(1, $this->dispatched);
    }

    public function test_wrong_mailgun_signature_is_rejected(): void
    {
        $response = $this->controller()->handle(self::request('', [], self::mailgunForm(key: 'some-other-key')));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_non_numeric_timestamp_is_rejected(): void
    {
        $form = self::mailgunForm();
        $form['timestamp'] = '1e3';

        self::assertSame(401, $this->controller()->handle(self::request('', [], $form))->getStatusCode());
    }

    public function test_signature_is_rejected_when_no_signing_key_is_configured(): void
    {
        unset($this->settings->values['ramon-mail-reply.mailgun_signing_key']);

        $response = $this->controller()->handle(self::request('', [], self::mailgunForm()));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_undecryptable_signing_key_fails_closed_and_is_reported(): void
    {
        $this->settings->values['ramon-mail-reply.mailgun_signing_key'] = Secrets::box('https://other.example.com')->encode(self::SIGNING_KEY);

        $response = $this->controller()->handle(self::request('', [], self::mailgunForm()));

        self::assertSame(401, $response->getStatusCode());
        self::assertNotEmpty($this->logger->errors());
    }

    public function test_endpoint_is_404_outside_webhook_mode_even_when_authorized(): void
    {
        $this->settings->values['ramon-mail-reply.delivery_mode'] = 'imap';
        $bus = Mockery::mock(BusDispatcher::class);
        $bus->shouldNotReceive('dispatch');

        $response = $this->controller($bus)->handle($this->jsonRequest(self::WEBHOOK_SECRET));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_oversized_body_is_rejected_with_413(): void
    {
        $stream = Mockery::mock(StreamInterface::class);
        $stream->shouldReceive('getSize')->andReturn(InboundEmailController::MAX_BODY_BYTES + 1);
        $bus = Mockery::mock(BusDispatcher::class);
        $bus->shouldNotReceive('dispatch');

        $request = self::request('', ['X-Mail-Reply-Token' => self::WEBHOOK_SECRET], null, $stream);

        self::assertSame(413, $this->controller($bus)->handle($request)->getStatusCode());
    }

    public function test_raw_rfc822_body_is_parsed_before_dispatch(): void
    {
        $raw = implode("\r\n", [
            'From: Ana <ana@example.com>',
            'To: reply+u3d4-0123456789abcdef@reply.forum.test',
            'Subject: =?UTF-8?B?UmU6IE9sw6E=?=',
            'Message-ID: <raw-1@example.com>',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            'Raw reply body',
        ]);

        $response = $this->controller()->handle(self::request($raw, [
            'Content-Type'       => 'message/rfc822',
            'X-Mail-Reply-Token' => self::WEBHOOK_SECRET,
        ]));

        self::assertSame(202, $response->getStatusCode());
        $payload = $this->dispatched[0]->payload;
        self::assertSame('ana@example.com', $payload['from']);
        self::assertSame('Re: Olá', $payload['subject']);
        self::assertSame('Raw reply body', $payload['body_text']);
        self::assertSame('', $payload['stripped_text']);
    }

    public function test_dispatch_failure_returns_500_without_leaking_details(): void
    {
        $bus = Mockery::mock(BusDispatcher::class);
        $bus->shouldReceive('dispatch')->andThrow(new \RuntimeException('redis down at 10.0.0.5'));

        $response = $this->controller($bus)->handle($this->jsonRequest(self::WEBHOOK_SECRET));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('redis', (string) $response->getBody());
        self::assertNotEmpty($this->logger->errors());
    }
}
