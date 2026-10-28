<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\MailReply\Service;

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use RuntimeException;

/**
 * Cifra os segredos guardados na tabela `settings` (chave HMAC dos aliases,
 * senha IMAP, signing key do Mailgun) com XChaCha20-Poly1305.
 *
 *     mp2:base64url(nonce(24) || ciphertext+tag)
 *
 * A chave do `mp2` é aleatória e fica em `storage/mail-reply/secret.key`,
 * fora do banco: um dump da tabela não basta para abrir os valores, e mudar
 * a URL (http → https, www) ou a pasta do fórum não os invalida. Sem como
 * criar o arquivo (storage só de leitura), cai no `mp1`, cuja chave deriva
 * da URL e da pasta — o formato das versões anteriores, que continua
 * legível e é migrado pelos leitores (`needsMigration()`).
 *
 * Sem libsodium nada é gravado: texto puro seria justamente o que esta
 * classe evita.
 */
class SecretBox
{
    private const PREFIX = 'mp2:';
    private const LEGACY_PREFIX = 'mp1:';
    private const KEY_FILE = 'mail-reply/secret.key';

    protected ?string $fileKey = null;

    public function __construct(
        protected Config $config,
        protected Paths $paths,
    ) {
    }

    public static function isSupported(): bool
    {
        return function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
            && defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES');
    }

    public function encode(string $plaintext): string
    {
        if (! self::isSupported()) {
            throw new RuntimeException(
                'libsodium AEAD XChaCha20-Poly1305 is required to persist mail-reply secrets. '
                .'Install sodium extension or upgrade PHP to a build that ships it.'
            );
        }

        $key = $this->fileKey(true);
        [$prefix, $key] = $key !== null ? [self::PREFIX, $key] : [self::LEGACY_PREFIX, $this->legacyKey()];

        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, '', $nonce, $key);

        return $prefix.$this->b64UrlEncode($nonce.$ciphertext);
    }

    /**
     * Valor sem prefixo é texto puro legado e volta intacto; quem lê é que
     * regrava cifrado. Lança `RuntimeException` sem libsodium, com payload
     * truncado ou quando a verificação AEAD falha (chave errada, adulteração).
     */
    public function decode(string $value): string
    {
        if ($value === '' || ! self::isEncrypted($value)) {
            return $value;
        }

        if (! self::isSupported()) {
            throw new RuntimeException('Encrypted setting found but libsodium AEAD is not available on this host.');
        }

        $legacy = str_starts_with($value, self::LEGACY_PREFIX);
        $key = $legacy ? $this->legacyKey() : $this->fileKey(false);

        if ($key === null) {
            throw new RuntimeException('The mail-reply key file (storage/'.self::KEY_FILE.') is missing; re-enter the secrets in the admin panel.');
        }

        $payload = $this->b64UrlDecode(substr($value, 4));
        $nlen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        if (strlen($payload) < $nlen + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new RuntimeException('Encrypted mail-reply secret is truncated.');
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($payload, $nlen), '', substr($payload, 0, $nlen), $key);

        if ($plaintext === false) {
            throw new RuntimeException('Failed to decrypt mail-reply secret — wrong key or tampered payload.');
        }

        return $plaintext;
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX) || str_starts_with($value, self::LEGACY_PREFIX);
    }

    /**
     * Texto puro ou `mp1`: o leitor deve regravar com `encode()`.
     */
    public function needsMigration(string $value): bool
    {
        if ($value === '' || ! self::isSupported()) {
            return false;
        }

        return ! str_starts_with($value, self::PREFIX) && $this->fileKey(true) !== null;
    }

    /**
     * Lê a chave do arquivo ou, com `$create`, gera uma nova. O `x` do
     * fopen garante que dois processos concorrentes não gravem chaves
     * diferentes: o segundo perde a corrida e lê a do primeiro.
     */
    protected function fileKey(bool $create): ?string
    {
        if ($this->fileKey !== null) {
            return $this->fileKey;
        }

        $path = rtrim((string) $this->paths->storage, '/').'/'.self::KEY_FILE;

        if (! is_file($path) && $create) {
            $dir = dirname($path);

            if (! is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }

            $handle = @fopen($path, 'x');

            if ($handle !== false) {
                fwrite($handle, bin2hex(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)));
                fclose($handle);
                @chmod($path, 0600);
            }
        }

        $hex = is_file($path) ? trim((string) @file_get_contents($path)) : ''; /* caminho fixo em storage, não é URL; nosemgrep: flarum-v2-server-side-fetch */

        if (strlen($hex) !== 2 * SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES || ! ctype_xdigit($hex)) {
            return null;
        }

        return $this->fileKey = (string) hex2bin($hex);
    }

    /**
     * Chave do `mp1`: muda junto com `url` ou `paths.base` do config.php.
     */
    protected function legacyKey(): string
    {
        $url = (string) ($this->config['url'] ?? '');
        $base = (string) $this->paths->base;

        if ($url === '' || $base === '') {
            throw new RuntimeException(
                'mail-reply SecretBox needs `url` and `paths.base` in config.php to derive its envelope key.'
            );
        }

        return hash('sha256', 'ramon-mail-reply|v1|'.$url.'|'.$base, true);
    }

    protected function b64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    protected function b64UrlDecode(string $encoded): string
    {
        $pad = strlen($encoded) % 4;
        if ($pad) {
            $encoded .= str_repeat('=', 4 - $pad);
        }

        return (string) base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}
