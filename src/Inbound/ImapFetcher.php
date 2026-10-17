<?php

namespace Ramon\MailReply\Inbound;

use Flarum\Foundation\ErrorHandling\LogReporter;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;
use Ramon\MailReply\Parser\Rfc822Parser;
use Ramon\MailReply\Service\SecretBox;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\ClientManager;

/**
 * Coleta respostas não lidas de uma caixa IMAP e as entrega ao
 * InboundProcessor no mesmo formato do webhook. `webklex/php-imap` é
 * dependência opcional (`suggest`), checada em tempo de execução.
 *
 * Destino de cada mensagem após o ciclo:
 *   - processada ou recusada em definitivo → apagada (ou só lida, conforme
 *     `imap_delete_after`);
 *   - sem alias válido do fórum → só marcada como lida, nunca apagada;
 *   - falha transitória ou de parsing → marcada como lida e mantida para
 *     inspeção manual.
 */
class ImapFetcher
{
    public const DEFAULT_LIMIT = 50;
    public const LAST_FETCH_KEY = 'mail-reply.last_fetch';
    private const FETCH_LOCK_KEY = 'mail-reply.fetch_lock';
    private const FETCH_LOCK_SECONDS = 300;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected InboundProcessor $processor,
        protected LogReporter $log,
        protected LoggerInterface $logger,
        protected SecretBox $secretBox,
        protected Repository $cache,
    ) {
    }

    public static function configuredIn(SettingsRepositoryInterface $settings): bool
    {
        foreach (['imap_host', 'imap_username', 'imap_password'] as $key) {
            if (trim((string) $settings->get('ramon-mail-reply.'.$key)) === '') {
                return false;
            }
        }

        return true;
    }

    public function isConfigured(): bool
    {
        return self::configuredIn($this->settings) && class_exists(ClientManager::class);
    }

    /**
     * Um único ciclo por vez entre o job em fila e o comando agendado —
     * o segundo a chegar devolve `locked: true` sem conectar.
     *
     * @return array{processed: int, failed: int, skipped: int, locked: bool}
     */
    public function fetch(int $limit = self::DEFAULT_LIMIT): array
    {
        $this->ensureLibraryAvailable();

        $config = $this->config();
        $this->assertConfigured($config);

        $lock = $this->cache->lock(self::FETCH_LOCK_KEY, self::FETCH_LOCK_SECONDS);

        if (! $lock->get()) {
            return ['processed' => 0, 'failed' => 0, 'skipped' => 0, 'locked' => true];
        }

        try {
            return $this->fetchLocked($config, $limit) + ['locked' => false];
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array{host: string, port: int, encryption: string, validate_cert: bool, username: string, password: string, mailbox: string, delete_after: bool} $config
     * @return array{processed: int, failed: int, skipped: int}
     */
    protected function fetchLocked(array $config, int $limit): array
    {
        $client = self::makeClient($config);
        $client->connect();

        try {
            $folder = $client->getFolder($config['mailbox']);

            if ($folder === null) {
                throw new RuntimeException(sprintf('IMAP mailbox "%s" not found.', $config['mailbox']));
            }

            $messages = $folder->query()->unseen()->setFetchOrder('asc')->limit($limit)->get();

            $counts = ['processed' => 0, 'failed' => 0, 'skipped' => 0];
            $deleted = false;

            foreach ($messages as $message) {
                $outcome = $this->handleMessage($message);
                $counts[$outcome === 'foreign' ? 'skipped' : $outcome]++;

                try {
                    if (in_array($outcome, ['processed', 'skipped'], true) && $config['delete_after']) {
                        $message->delete(false);
                        $deleted = true;
                    } else {
                        $message->setFlag('Seen');
                    }
                } catch (Throwable) {
                }
            }

            if ($deleted) {
                $client->expunge();
            }

            $this->cache->forever(self::LAST_FETCH_KEY, ['at' => time()] + $counts);

            if (array_sum($counts) > 0) {
                $this->logger->info('[mail-reply] IMAP fetch cycle complete', ['mailbox' => $config['mailbox']] + $counts);
            }

            return $counts;
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
            }
        }
    }

    /**
     * `foreign` = a mensagem não é resposta a um alias do fórum (caixa
     * compartilhada, spam, newsletter): fica só marcada como lida, nunca
     * apagada — não é nossa para descartar.
     *
     * @return 'processed'|'failed'|'skipped'|'foreign'
     */
    protected function handleMessage(object $message): string
    {
        try {
            $payload = $this->buildPayload($message);
        } catch (Throwable $e) {
            $this->log->report($e);

            return 'failed';
        }

        if ($payload['to'] === []) {
            return 'foreign';
        }

        try {
            $this->processor->process($payload);

            return 'processed';
        } catch (InboundRejected $e) {
            if ($e->reason === 'invalid_address') {
                return 'foreign';
            }

            if (! $e->silent) {
                $this->logger->info('[mail-reply] inbound message rejected', ['reason' => $e->reason]);
            }

            return 'skipped';
        } catch (Throwable $e) {
            $this->logger->warning('[mail-reply] inbound message failed; kept in mailbox', ['error' => $e->getMessage()]);
            $this->log->report($e);

            return 'failed';
        }
    }

    /**
     * @param array{host: string, port: int, encryption: string, validate_cert: bool, username: string, password: string} $config
     */
    public static function makeClient(array $config): \Webklex\PHPIMAP\Client
    {
        return (new ClientManager())->make([
            'host'           => $config['host'],
            'port'           => $config['port'],
            'encryption'     => $config['encryption'] === 'none' ? false : $config['encryption'],
            'validate_cert'  => $config['validate_cert'],
            'username'       => $config['username'],
            'password'       => $config['password'],
            'protocol'       => 'imap',
            'authentication' => null,
            'timeout'        => 30,
        ]);
    }

    /**
     * Senha IMAP persistida cifrada via SecretBox; valores legados em
     * plaintext ainda são aceitos e re-cifrados na primeira leitura.
     *
     * @return array{host: string, port: int, encryption: string, validate_cert: bool, username: string, password: string, mailbox: string, delete_after: bool}
     */
    protected function config(): array
    {
        $rawPassword = (string) $this->settings->get('ramon-mail-reply.imap_password');
        $password = $this->secretBox->decode($rawPassword);

        if ($rawPassword !== '' && ! SecretBox::isEncrypted($rawPassword)) {
            try {
                $this->settings->set('ramon-mail-reply.imap_password', $this->secretBox->encode($password));
            } catch (Throwable $e) {
                $this->logger->warning('[mail-reply] lazy re-encrypt of imap_password failed', ['reason' => $e->getMessage()]);
            }
        }

        return [
            'host'          => trim((string) $this->settings->get('ramon-mail-reply.imap_host')),
            'port'          => (int) ($this->settings->get('ramon-mail-reply.imap_port') ?: 993),
            'encryption'    => (string) ($this->settings->get('ramon-mail-reply.imap_encryption') ?: 'ssl'),
            'validate_cert' => (bool) ($this->settings->get('ramon-mail-reply.imap_validate_cert') ?? true),
            'username'      => trim((string) $this->settings->get('ramon-mail-reply.imap_username')),
            'password'      => $password,
            'mailbox'       => (string) ($this->settings->get('ramon-mail-reply.imap_mailbox') ?: 'INBOX'),
            'delete_after'  => (bool) ($this->settings->get('ramon-mail-reply.imap_delete_after') ?? true),
        ];
    }

    /**
     * @param array{host: string, username: string, password: string} $config
     */
    protected function assertConfigured(array $config): void
    {
        foreach (['host', 'username', 'password'] as $key) {
            if ($config[$key] === '') {
                throw new RuntimeException(sprintf('Mail-reply IMAP not configured: missing "%s".', $key));
            }
        }
    }

    protected function ensureLibraryAvailable(): void
    {
        if (! class_exists(ClientManager::class)) {
            throw new RuntimeException('webklex/php-imap is required for IMAP polling. Run: composer require webklex/php-imap');
        }
    }

    /**
     * Os headers vêm do bloco cru (`Header::$raw`) e passam pelo mesmo
     * parser do webhook — recipients, Message-ID e sinais de
     * auto-resposta ficam idênticos nos dois modos de entrega.
     *
     * @return array{to: array<int,string>, from: string, subject: string, body_text: string, body_html: string, message_id: string, headers: array<string, array<int,string>>}
     */
    protected function buildPayload(object $message): array
    {
        $headers = Rfc822Parser::parseHeaderBlock((string) ($message->getHeader()->raw ?? ''));

        return [
            'to'         => RecipientExtractor::fromHeaders($headers),
            'from'       => RecipientExtractor::firstAddress(Rfc822Parser::first($headers, 'from')),
            'subject'    => Rfc822Parser::decodeWords(Rfc822Parser::first($headers, 'subject')),
            'body_text'  => (string) $message->getTextBody(),
            'body_html'  => (string) $message->getHTMLBody(),
            'message_id' => trim(Rfc822Parser::first($headers, 'message-id')),
            'headers'    => $headers,
        ];
    }
}
