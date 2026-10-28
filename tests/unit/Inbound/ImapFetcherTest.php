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

namespace Ramon\MailReply\Tests\unit\Inbound;

use Flarum\Foundation\ErrorHandling\LogReporter;
use Flarum\Post\CommentPost;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Ramon\MailReply\Inbound\ImapFetcher;
use Ramon\MailReply\Inbound\InboundProcessor;
use Ramon\MailReply\Parser\Rfc822Parser;
use Ramon\MailReply\Tests\Support\ArraySettings;
use Ramon\MailReply\Tests\Support\MemoryLogger;
use Ramon\MailReply\Tests\Support\Secrets;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Folder;

/**
 * Roda o ImapProtocol real do webklex contra um socket com as respostas do
 * servidor já roteirizadas — valida os comandos enviados e o parsing de
 * `BODY[]` sem servidor IMAP de verdade.
 */
final class ImapFetcherTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var resource */
    private $server;

    private ImapProtocol $protocol;

    private MemoryLogger $logger;

    private Repository $cache;

    /** @var list<array<string, mixed>> */
    private array $processed = [];

    protected function setUp(): void
    {
        $domain = PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX;
        [$clientEnd, $serverEnd] = stream_socket_pair($domain, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        stream_set_timeout($clientEnd, 2);
        $this->server = $serverEnd;

        $this->protocol = new ImapProtocol(new Config());
        $this->protocol->stream = $clientEnd;
    }

    protected function tearDown(): void
    {
        fclose($this->server);
    }

    private function serverSays(string $lines): void
    {
        fwrite($this->server, str_replace("\n", "\r\n", $lines));
    }

    private function commandsSent(): string
    {
        stream_set_blocking($this->server, false);

        return (string) stream_get_contents($this->server);
    }

    private function fetcher(bool $processorThrows = false): ImapFetcher
    {
        $processor = Mockery::mock(InboundProcessor::class);
        $processor->shouldReceive('process')->andReturnUsing(function (array $payload) use ($processorThrows) {
            if ($processorThrows) {
                throw new \RuntimeException('boom');
            }

            $this->processed[] = $payload;

            return new CommentPost();
        });

        $folder = Mockery::mock(Folder::class);
        $folder->path = 'INBOX';

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('connect')->once();
        $client->shouldReceive('getFolder')->with('INBOX')->andReturn($folder);
        $client->shouldReceive('openFolder')->with('INBOX')->andReturn([]);
        $client->shouldReceive('getConnection')->andReturn($this->protocol);
        $client->shouldReceive('disconnect')->once();

        $logger = $this->logger = new MemoryLogger();
        $box = Secrets::box();
        $settings = new ArraySettings([
            'ramon-mail-reply.imap_host'     => 'imap.example.com',
            'ramon-mail-reply.imap_username' => 'replies@example.com',
            'ramon-mail-reply.imap_password' => $box->encode('secret'),
        ]);

        return new class($settings, $processor, new LogReporter($logger), $logger, $box, $this->cache = new Repository(new ArrayStore()), new Rfc822Parser(), $client) extends ImapFetcher {
            public function __construct($settings, $processor, $log, $logger, $secretBox, $cache, $parser, private Client $fake)
            {
                parent::__construct($settings, $processor, $log, $logger, $secretBox, $cache, $parser);
            }

            protected function client(array $config): Client
            {
                return $this->fake;
            }
        };
    }

    private static function rawMessage(): string
    {
        return "From: Alice <alice@example.com>\r\n"
            ."To: reply+abc@forum.example.com\r\n"
            ."Subject: Re: Hello\r\n"
            ."Message-ID: <m1@example.com>\r\n"
            ."Content-Type: text/plain; charset=utf-8\r\n"
            ."\r\n"
            ."Thanks for the answer!\r\n";
    }

    public function test_peeks_bodies_deletes_processed_and_keeps_oversized_unread(): void
    {
        $raw = self::rawMessage();
        $big = ImapFetcher::MAX_MESSAGE_BYTES + 1;

        $this->serverSays("* SEARCH 7 3\nTAG1 OK SEARCH completed\n");
        $this->serverSays("* 1 FETCH (UID 3 RFC822.SIZE ".strlen($raw).")\n* 2 FETCH (UID 7 RFC822.SIZE $big)\nTAG2 OK FETCH completed\n");
        fwrite($this->server, "* 1 FETCH (UID 3 BODY[] {".strlen($raw)."}\r\n".$raw.")\r\nTAG3 OK FETCH completed\r\n");
        $this->serverSays("TAG4 OK STORE completed\nTAG5 OK STORE completed\n* 1 EXPUNGE\nTAG6 OK EXPUNGE completed\n");

        $result = $this->fetcher()->fetch();

        self::assertSame(['processed' => 1, 'failed' => 1, 'skipped' => 0, 'locked' => false], $result, implode("
", array_column($this->logger->records, 'message')));
        self::assertCount(1, $this->processed);
        self::assertSame('alice@example.com', $this->processed[0]['from']);
        self::assertSame(['reply+abc@forum.example.com'], $this->processed[0]['to']);
        self::assertStringContainsString('Thanks for the answer!', $this->processed[0]['body_text']);

        $sent = $this->commandsSent();
        self::assertStringContainsString('TAG1 UID SEARCH UNSEEN', $sent);
        self::assertStringContainsString('TAG3 UID FETCH 3:3 (UID BODY.PEEK[])', $sent);
        self::assertStringContainsString('TAG4 UID STORE 3 +FLAGS.SILENT (\Deleted)', $sent);
        self::assertStringContainsString('TAG5 UID STORE 7 +FLAGS.SILENT (\Seen)', $sent);
        self::assertStringContainsString('TAG6 EXPUNGE', $sent);
        self::assertStringNotContainsString('UID FETCH 7:7', $sent);
        self::assertStringNotContainsString('NOOP', $sent);
    }

    public function test_failed_flag_update_ends_cycle_without_touching_remaining_messages(): void
    {
        $raw = self::rawMessage();

        $this->serverSays("* SEARCH 3 7\nTAG1 OK SEARCH completed\n");
        $this->serverSays("* 1 FETCH (UID 3 RFC822.SIZE 10)\n* 2 FETCH (UID 7 RFC822.SIZE 10)\nTAG2 OK FETCH completed\n");
        fwrite($this->server, "* 1 FETCH (UID 3 BODY[] {".strlen($raw)."}\r\n".$raw.")\r\nTAG3 OK FETCH completed\r\n");
        $this->serverSays("TAG4 NO STORE failed\n");

        $result = $this->fetcher()->fetch();

        self::assertSame(['processed' => 1, 'failed' => 0, 'skipped' => 0, 'locked' => false], $result);
        self::assertStringNotContainsString('UID FETCH 7:7', $this->commandsSent());
    }

    public function test_transient_failure_keeps_the_message_unread_for_the_next_cycle(): void
    {
        $raw = self::rawMessage();

        $this->serverSays("* SEARCH 3\nTAG1 OK SEARCH completed\n");
        $this->serverSays("* 1 FETCH (UID 3 RFC822.SIZE 10)\nTAG2 OK FETCH completed\n");
        fwrite($this->server, "* 1 FETCH (UID 3 BODY[] {".strlen($raw)."}\r\n".$raw.")\r\nTAG3 OK FETCH completed\r\n");

        $result = $this->fetcher(processorThrows: true)->fetch();

        self::assertSame(['processed' => 0, 'failed' => 1, 'skipped' => 0, 'locked' => false], $result);
        self::assertStringNotContainsString('UID STORE 3', $this->commandsSent());
    }

    public function test_message_is_flagged_seen_after_the_last_attempt(): void
    {
        $raw = self::rawMessage();

        $this->serverSays("* SEARCH 3\nTAG1 OK SEARCH completed\n");
        $this->serverSays("* 1 FETCH (UID 3 RFC822.SIZE 10)\nTAG2 OK FETCH completed\n");
        fwrite($this->server, "* 1 FETCH (UID 3 BODY[] {".strlen($raw)."}\r\n".$raw.")\r\nTAG3 OK FETCH completed\r\n");
        $this->serverSays("TAG4 OK STORE completed\n");

        $fetcher = $this->fetcher(processorThrows: true);
        $this->cache->put('mail-reply.imap_attempts.'.md5('INBOX').'.3', ImapFetcher::MAX_ATTEMPTS - 1, 60);
        $fetcher->fetch();

        self::assertStringContainsString('TAG4 UID STORE 3 +FLAGS.SILENT (\Seen)', $this->commandsSent());
    }

    /**
     * @return array<string, array{0: string, 1: string|false}>
     */
    public static function encryptions(): array
    {
        return [
            'ssl (993)' => ['ssl', 'ssl'],
            'STARTTLS (143)' => ['tls', 'starttls'],
            'sem criptografia' => ['none', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('encryptions')]
    public function test_admin_encryption_maps_to_the_webklex_name(string $setting, string|false $expected): void
    {
        $client = ImapFetcher::makeClient([
            'host' => 'imap.example.com', 'port' => 143, 'encryption' => $setting, 'validate_cert' => true,
            'username' => 'u', 'password' => 'p',
        ]);

        self::assertSame($expected === false ? '' : $expected, (string) ($client->encryption ?: ''));
    }
}
