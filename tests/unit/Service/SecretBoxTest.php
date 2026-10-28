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

namespace Ramon\MailReply\Tests\unit\Service;

use PHPUnit\Framework\TestCase;
use Ramon\MailReply\Service\SecretBox;
use Ramon\MailReply\Tests\Support\Secrets;
use RuntimeException;

final class SecretBoxTest extends TestCase
{
    protected function setUp(): void
    {
        if (! SecretBox::isSupported()) {
            self::markTestSkipped('libsodium XChaCha20-Poly1305 is not available.');
        }
    }

    public function test_round_trip_with_prefix_and_random_nonce(): void
    {
        $box = Secrets::box();

        $a = $box->encode('s3cr3t-imap-pässword');
        $b = $box->encode('s3cr3t-imap-pässword');

        self::assertStringStartsWith('mp2:', $a);
        self::assertTrue(SecretBox::isEncrypted($a));
        self::assertNotSame($a, $b);
        self::assertStringNotContainsString('s3cr3t', $a);
        self::assertSame('s3cr3t-imap-pässword', $box->decode($a));
        self::assertSame('s3cr3t-imap-pässword', $box->decode($b));
    }

    public function test_round_trip_of_empty_and_binary_values(): void
    {
        $box = Secrets::box();
        $binary = random_bytes(64);

        self::assertSame('', $box->decode($box->encode('')));
        self::assertSame($binary, $box->decode($box->encode($binary)));
    }

    public function test_legacy_plaintext_and_empty_values_pass_through(): void
    {
        $box = Secrets::box();

        self::assertSame('legacy-plain-secret', $box->decode('legacy-plain-secret'));
        self::assertSame('', $box->decode(''));
        self::assertFalse(SecretBox::isEncrypted('legacy-plain-secret'));
    }

    public function test_tampered_ciphertext_is_rejected(): void
    {
        $box = Secrets::box();
        $encoded = $box->encode('top secret');

        $raw = base64_decode(strtr(substr($encoded, 4), '-_', '+/'));
        $raw[30] = chr(ord($raw[30]) ^ 0x01);
        $tampered = 'mp2:'.rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        $this->expectException(RuntimeException::class);
        $box->decode($tampered);
    }

    public function test_truncated_payload_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('truncated');

        $box = Secrets::box();
        $box->encode('cria a chave');
        $box->decode('mp2:AAAA');
    }

    public function test_key_lives_in_storage_and_a_database_dump_alone_is_not_enough(): void
    {
        $storage = Secrets::storage();
        $encoded = Secrets::box('https://forum.example.com', '/srv/flarum', $storage)->encode('secret');

        self::assertFileExists($storage.'/mail-reply/secret.key');
        self::assertSame('0600', substr(sprintf('%o', fileperms($storage.'/mail-reply/secret.key')), -4));

        // Mesma URL e pasta (o que se adivinha de fora), outra chave.
        $this->expectException(RuntimeException::class);
        Secrets::box('https://forum.example.com', '/srv/flarum')->decode($encoded);
    }

    public function test_changing_url_or_base_path_keeps_values_readable(): void
    {
        $storage = Secrets::storage();
        $encoded = Secrets::box('http://forum.example.com', '/home/a/flarum', $storage)->encode('secret');

        self::assertSame('secret', Secrets::box('https://www.forum.example.com', '/home2/a/flarum', $storage)->decode($encoded));
    }

    public function test_legacy_mp1_values_stay_readable_and_ask_for_migration(): void
    {
        $legacy = self::legacyEncode('https://forum.example.com', '/srv/flarum', 'secret');
        $box = Secrets::box('https://forum.example.com', '/srv/flarum');

        self::assertTrue(SecretBox::isEncrypted($legacy));
        self::assertSame('secret', $box->decode($legacy));
        self::assertTrue($box->needsMigration($legacy));
        self::assertTrue($box->needsMigration('plain'));
        self::assertFalse($box->needsMigration($box->encode('secret')));
        self::assertFalse($box->needsMigration(''));
    }

    public function test_read_only_storage_falls_back_to_the_legacy_format(): void
    {
        $box = Secrets::box('https://forum.example.com', '/srv/flarum', '/proc/mail-reply-no-storage');
        $encoded = $box->encode('secret');

        self::assertStringStartsWith('mp1:', $encoded);
        self::assertSame('secret', $box->decode($encoded));
        self::assertFalse($box->needsMigration($encoded));
    }

    /**
     * O `mp1` das versões anteriores, montado aqui para não depender do
     * código que está sendo testado.
     */
    private static function legacyEncode(string $url, string $base, string $plain): string
    {
        $key = hash('sha256', 'ramon-mail-reply|v1|'.$url.'|'.$base, true);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return 'mp1:'.rtrim(strtr(base64_encode($nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, '', $nonce, $key)), '+/', '-_'), '=');
    }

    public function test_missing_url_only_matters_for_the_legacy_format(): void
    {
        self::assertSame('secret', ($box = Secrets::box(''))->decode($box->encode('secret')));

        $this->expectException(RuntimeException::class);
        Secrets::box('', '/srv/flarum', '/proc/mail-reply-no-storage')->encode('secret');
    }
}
