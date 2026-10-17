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
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\IMAP;

/**
 * Coleta respostas não lidas de uma caixa IMAP e as entrega ao
 * InboundProcessor no mesmo formato do webhook. `webklex/php-imap` é
 * dependência opcional (`suggest`), checada em tempo de execução.
 *
 * Usa só o protocolo de baixo nível (`UID SEARCH`, `BODY.PEEK[]`,
 * `UID STORE`): o `Query::get()` da biblioteca faz NOOP + STORE extras por
 * mensagem e estoura o timeout do worker em caixas com várias pendentes.
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
    public const MAX_MESSAGE_BYTES = 10 * 1024 * 1024;

    /**
     * Maior que o pior ciclo (orçamento + um read travado), para o lock nunca
     * expirar no meio; curto o bastante para um worker morto não bloquear
     * a caixa por muito tempo.
     */
    private const FETCH_LOCK_SECONDS = 90;

    /**
     * Mensagens restantes ficam não lidas para o próximo ciclo — mantém o
     * ciclo bem abaixo do `$timeout` do FetchInboundEmailsJob.
     */
    private const TIME_BUDGET_SECONDS = 25;

    private const SOCKET_TIMEOUT_SECONDS = 15;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected InboundProcessor $processor,
        protected LogReporter $log,
        protected LoggerInterface $logger,
        protected SecretBox $secretBox,
        protected Repository $cache,
        protected Rfc822Parser $parser,
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
     * Uma falha ao marcar/apagar aborta o ciclo: a conexão provavelmente
     * caiu, e o dedupe por Message-ID do InboundProcessor torna seguro
     * reprocessar no próximo ciclo o que ficou sem flag.
     *
     * @param array{host: string, port: int, encryption: string, validate_cert: bool, username: string, password: string, mailbox: string, delete_after: bool} $config
     * @return array{processed: int, failed: int, skipped: int}
     */
    protected function fetchLocked(array $config, int $limit): array
    {
        $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;
        $client = $this->client($config);
        $client->connect();

        try {
            $folder = $client->getFolder($config['mailbox']);

            if ($folder === null) {
                throw new RuntimeException(sprintf('IMAP mailbox "%s" not found.', $config['mailbox']));
            }

            $client->openFolder($folder->path);
            $connection = $client->getConnection();

            if (! $connection instanceof ImapProtocol) {
                throw new RuntimeException('Unexpected IMAP protocol implementation: '.get_class($connection));
            }

            $uids = $this->unseenUids($connection, $limit);
            $sizes = $uids === [] ? [] : $this->messageSizes($connection, $uids);

            $counts = ['processed' => 0, 'failed' => 0, 'skipped' => 0];
            $deleted = false;

            foreach ($uids as $uid) {
                if (microtime(true) >= $deadline) {
                    break;
                }

                $outcome = $this->handleUid($connection, $uid, $sizes[$uid] ?? 0);
                $counts[$outcome === 'foreign' ? 'skipped' : $outcome]++;

                try {
                    if (in_array($outcome, ['processed', 'skipped'], true) && $config['delete_after']) {
                        $connection->store(['\Deleted'], $uid, null, '+', true, IMAP::ST_UID)->validate();
                        $deleted = true;
                    } else {
                        $connection->store(['\Seen'], $uid, null, '+', true, IMAP::ST_UID)->validate();
                    }
                } catch (Throwable $e) {
                    $this->logger->warning('[mail-reply] IMAP flag update failed; ending cycle early', ['error' => $e->getMessage()]);

                    break;
                }
            }

            if ($deleted) {
                try {
                    $connection->expunge();
                } catch (Throwable $e) {
                    $this->logger->warning('[mail-reply] IMAP expunge failed', ['error' => $e->getMessage()]);
                }
            }

            $this->cache->forever(self::LAST_FETCH_KEY, ['at' => time()] + $counts);

            if (array_sum($counts) > 0) {
                $this->logger->info('[mail-reply] IMAP fetch cycle complete', ['mailbox' => $config['mailbox'], 'pending' => count($uids)] + $counts);
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
     * @return array<int, int> UIDs em ordem crescente (mais antigas primeiro)
     */
    protected function unseenUids(ImapProtocol $connection, int $limit): array
    {
        $result = $connection->search(['UNSEEN'], IMAP::ST_UID)->validatedData();

        $uids = array_values(array_unique(array_map('intval', array_filter(
            is_array($result) ? $result : [],
            fn ($id) => is_scalar($id) && ctype_digit((string) $id),
        ))));
        sort($uids);

        return array_slice($uids, 0, max(1, $limit));
    }

    /**
     * @param array<int, int> $uids
     * @return array<int, int>
     */
    protected function messageSizes(ImapProtocol $connection, array $uids): array
    {
        $rows = $connection->sizes($uids, IMAP::ST_UID)->validatedData();
        $sizes = [];

        foreach (is_array($rows) ? $rows : [] as $uid => $size) {
            if (is_scalar($size)) {
                $sizes[(int) $uid] = (int) $size;
            }
        }

        return $sizes;
    }

    /**
     * `BODY.PEEK[]` não marca a mensagem como lida — quem decide a flag é o
     * fetchLocked, conforme o resultado.
     *
     * @return 'processed'|'failed'|'skipped'|'foreign'
     */
    protected function handleUid(ImapProtocol $connection, int $uid, int $size): string
    {
        if ($size > self::MAX_MESSAGE_BYTES) {
            $this->logger->warning('[mail-reply] inbound message too large; kept in mailbox', ['uid' => $uid, 'bytes' => $size]);

            return 'failed';
        }

        try {
            $rows = $connection->fetch(['UID', 'BODY.PEEK[]'], [$uid], null, IMAP::ST_UID)->validatedData();
            $raw = is_array($rows) ? ($rows[$uid]['BODY[]'] ?? null) : null;

            if (! is_string($raw) || $raw === '') {
                throw new RuntimeException(sprintf('IMAP message UID %d returned no body.', $uid));
            }

            $payload = $this->parser->parse($raw);
        } catch (Throwable $e) {
            $this->log->report($e);

            return 'failed';
        }

        return $this->handlePayload($payload);
    }

    /**
     * `foreign` = a mensagem não é resposta a um alias do fórum (caixa
     * compartilhada, spam, newsletter): fica só marcada como lida, nunca
     * apagada — não é nossa para descartar.
     *
     * @param array{to: array<int,string>, from: string, subject: string, body_text: string, body_html: string, message_id: string, headers: array<string, array<int,string>>} $payload
     * @return 'processed'|'failed'|'skipped'|'foreign'
     */
    protected function handlePayload(array $payload): string
    {
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
    protected function client(array $config): \Webklex\PHPIMAP\Client
    {
        return self::makeClient($config);
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
            'timeout'        => self::SOCKET_TIMEOUT_SECONDS,
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
}
