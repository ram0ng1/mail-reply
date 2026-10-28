<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\MailReply\Token;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Ramon\MailReply\Mail\ReplyAddressConfig;
use Ramon\MailReply\Service\SecretBox;
use Ramon\MailReply\Support\CacheLock;
use Throwable;

/**
 * Gera e valida aliases de resposta assinados com HMAC, sem estado no banco:
 *
 *     {localPart}+u{userId}d{discussionId}[p{postId}]-{sig}
 *
 * O alias é estável, então a mesma notificação pode ser respondida várias vezes.
 */
class TokenService
{
    private const SIG_LENGTH = 16;

    protected ?string $key = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ReplyAddressConfig $replyAddress,
        protected Repository $cache,
        protected SecretBox $secretBox,
        protected LoggerInterface $logger,
        protected ?ConnectionInterface $db = null,
    ) {
    }

    public function mintAddress(int $userId, int $discussionId, ?int $postId = null): ?string
    {
        $parts = $this->replyAddress->parts();

        if ($parts === null) {
            return null;
        }

        $tag = $this->tag($userId, $discussionId, $postId);

        return sprintf('%s+%s@%s', $parts['local'], $tag, $parts['domain']);
    }

    /**
     * Tag determinística — local part após o `+`.
     */
    public function tag(int $userId, int $discussionId, ?int $postId = null): string
    {
        $payload = $this->payload($userId, $discussionId, $postId);
        $sig = substr($this->sign($payload), 0, self::SIG_LENGTH);

        if ($postId !== null && $postId > 0) {
            return sprintf('u%dd%dp%d-%s', $userId, $discussionId, $postId, $sig);
        }

        return sprintf('u%dd%d-%s', $userId, $discussionId, $sig);
    }

    /**
     * Devolve null para prefixo errado, assinatura inválida ou formato corrompido.
     *
     * @return array{user_id: int, discussion_id: int, post_id: ?int}|null
     */
    public function parseAddress(string $address): ?array
    {
        if (preg_match('/<([^>]+)>/', $address, $m)) {
            $address = $m[1];
        }

        if (! preg_match('/^([^@]+)@/', $address, $m)) {
            return null;
        }

        $local = $m[1];

        if (! str_contains($local, '+')) {
            return null;
        }

        [$prefix, $rest] = explode('+', $local, 2);

        $expectedLocal = $this->replyAddress->parts()['local'] ?? null;

        if ($expectedLocal === null || ! hash_equals($expectedLocal, $prefix)) {
            return null;
        }

        if (preg_match('/^u(\d+)d(\d+)p(\d+)-([a-f0-9]{'.self::SIG_LENGTH.'})$/i', $rest, $m)) {
            return $this->verify(
                (int) $m[1],
                (int) $m[2],
                (int) $m[3],
                strtolower($m[4]),
            );
        }

        if (preg_match('/^u(\d+)d(\d+)-([a-f0-9]{'.self::SIG_LENGTH.'})$/i', $rest, $m)) {
            return $this->verify(
                (int) $m[1],
                (int) $m[2],
                null,
                strtolower($m[3]),
            );
        }

        return null;
    }

    /**
     * @return array{user_id: int, discussion_id: int, post_id: ?int}|null
     */
    protected function verify(int $userId, int $discussionId, ?int $postId, string $providedSig): ?array
    {
        $expectedSig = substr($this->sign($this->payload($userId, $discussionId, $postId)), 0, self::SIG_LENGTH);

        if (! hash_equals($expectedSig, $providedSig)) {
            return null;
        }

        return [
            'user_id' => $userId,
            'discussion_id' => $discussionId,
            'post_id' => $postId,
        ];
    }

    protected function payload(int $userId, int $discussionId, ?int $postId = null): string
    {
        if ($postId !== null && $postId > 0) {
            return 'u'.$userId.'d'.$discussionId.'p'.$postId;
        }

        return 'u'.$userId.'d'.$discussionId;
    }

    /**
     * A chave é gerada na primeira chamada e guardada nas settings, estável
     * entre processos sem depender de `.env`.
     */
    protected function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->key ??= $this->resolveKey());
    }

    /**
     * A geração fica sob lock: dois workers simultâneos gravariam chaves
     * diferentes e a segunda invalidaria os aliases já enviados.
     */
    protected function resolveKey(): string
    {
        $stored = (string) $this->settings->get('ramon-mail-reply.secret');

        if ($stored !== '') {
            return $this->decryptOrMigrate($stored, 'secret');
        }

        $lock = CacheLock::make($this->cache, 'mail-reply.secret_gen', 5);

        try {
            try {
                $lock->block(5);
            } catch (LockTimeoutException $e) {
                // Outro processo segurou o lock até o fim: a chave dele já
                // deve estar no banco.
                if (($stored = $this->storedSecret()) !== '') {
                    return $this->decryptOrMigrate($stored, 'secret');
                }

                throw $e;
            }

            $stored = $this->storedSecret();

            if ($stored !== '') {
                return $this->decryptOrMigrate($stored, 'secret');
            }

            $generated = bin2hex(random_bytes(32));
            $this->settings->set('ramon-mail-reply.secret', $this->secretBox->encode($generated));

            return $generated;
        } finally {
            try {
                $lock->release();
            } catch (Throwable $e) {
            }
        }
    }

    /**
     * Lê a chave direto do banco: o repositório de settings guarda tudo em
     * memória no começo do processo e não veria a chave que outro worker
     * acabou de gravar.
     */
    protected function storedSecret(): string
    {
        if ($this->db === null) {
            return (string) $this->settings->get('ramon-mail-reply.secret');
        }

        return (string) $this->db->table('settings')->where('key', 'ramon-mail-reply.secret')->value('value');
    }

    /**
     * Texto puro legado é regravado cifrado na leitura, sem comando de migração.
     */
    protected function decryptOrMigrate(string $stored, string $settingSuffix): string
    {
        try {
            $plain = $this->secretBox->decode($stored);
        } catch (Throwable $e) {
            $this->logger->error('[mail-reply] failed to decrypt secret', [
                'setting' => 'ramon-mail-reply.'.$settingSuffix,
                'reason' => $e->getMessage(),
            ]);
            throw $e;
        }

        if ($this->secretBox->needsMigration($stored)) {
            try {
                $this->settings->set('ramon-mail-reply.'.$settingSuffix, $this->secretBox->encode($plain));
                $this->logger->info('[mail-reply] migrated plaintext secret to encrypted form', [
                    'setting' => 'ramon-mail-reply.'.$settingSuffix,
                ]);
            } catch (Throwable $e) {
                $this->logger->warning('[mail-reply] lazy re-encrypt failed; continuing with plaintext', [
                    'setting' => 'ramon-mail-reply.'.$settingSuffix,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return $plain;
    }
}
