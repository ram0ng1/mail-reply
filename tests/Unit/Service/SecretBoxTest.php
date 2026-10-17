<?php

declare(strict_types=1);

namespace Ramon\MailReply\Tests\Unit\Service;

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

        self::assertStringStartsWith('mp1:', $a);
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
        $tampered = 'mp1:'.rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        $this->expectException(RuntimeException::class);
        $box->decode($tampered);
    }

    public function test_truncated_payload_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('truncated');

        Secrets::box()->decode('mp1:AAAA');
    }

    public function test_value_from_another_install_cannot_be_decrypted(): void
    {
        $encoded = Secrets::box('https://forum-a.example.com')->encode('secret');

        $this->expectException(RuntimeException::class);
        Secrets::box('https://forum-b.example.com')->decode($encoded);
    }

    public function test_different_base_path_also_changes_the_key(): void
    {
        $encoded = Secrets::box('https://forum.example.com', '/srv/a')->encode('secret');

        $this->expectException(RuntimeException::class);
        Secrets::box('https://forum.example.com', '/srv/b')->decode($encoded);
    }

    public function test_missing_url_refuses_to_encrypt(): void
    {
        $this->expectException(RuntimeException::class);

        Secrets::box('')->encode('secret');
    }
}
