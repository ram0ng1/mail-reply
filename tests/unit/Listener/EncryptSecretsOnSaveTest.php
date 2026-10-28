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

namespace Ramon\MailReply\Tests\unit\Listener;

use Flarum\Settings\Event\Saving;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramon\MailReply\Listener\EncryptSecretsOnSave;
use Ramon\MailReply\Service\SecretBox;
use Ramon\MailReply\Tests\Support\Secrets;

final class EncryptSecretsOnSaveTest extends TestCase
{
    private SecretBox $box;
    private EncryptSecretsOnSave $listener;

    protected function setUp(): void
    {
        if (! SecretBox::isSupported()) {
            self::markTestSkipped('libsodium XChaCha20-Poly1305 is not available.');
        }

        $this->box = Secrets::box();
        $this->listener = new EncryptSecretsOnSave($this->box);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function secretKeys(): array
    {
        return [
            'imap password'       => ['ramon-mail-reply.imap_password'],
            'mailgun signing key' => ['ramon-mail-reply.mailgun_signing_key'],
        ];
    }

    #[DataProvider('secretKeys')]
    public function test_plaintext_secret_is_encrypted_in_the_saved_payload(string $key): void
    {
        $settings = [
            $key                         => 'hunter2',
            'ramon-mail-reply.imap_host' => 'imap.example.com',
        ];

        $this->listener->handle(new Saving($settings));

        self::assertTrue(SecretBox::isEncrypted($settings[$key]));
        self::assertSame('hunter2', $this->box->decode($settings[$key]));
        self::assertSame('imap.example.com', $settings['ramon-mail-reply.imap_host']);
    }

    #[DataProvider('secretKeys')]
    public function test_already_encrypted_value_is_not_double_encrypted(string $key): void
    {
        $encrypted = $this->box->encode('hunter2');
        $settings = [$key => $encrypted];

        $this->listener->handle(new Saving($settings));

        self::assertSame($encrypted, $settings[$key]);
    }

    public function test_non_secret_settings_stay_plaintext(): void
    {
        $settings = ['ramon-mail-reply.webhook_mode' => 'x', 'ramon-mail-reply.reply_address' => 'reply@forum.test'];

        $this->listener->handle(new Saving($settings));

        self::assertSame(['ramon-mail-reply.webhook_mode' => 'x', 'ramon-mail-reply.reply_address' => 'reply@forum.test'], $settings);
    }

    public function test_empty_absent_or_non_string_values_are_left_untouched(): void
    {
        $empty = ['ramon-mail-reply.imap_password' => ''];
        $absent = ['other.key' => 'value'];
        $nonString = ['ramon-mail-reply.mailgun_signing_key' => null];

        $this->listener->handle(new Saving($empty));
        $this->listener->handle(new Saving($absent));
        $this->listener->handle(new Saving($nonString));

        self::assertSame(['ramon-mail-reply.imap_password' => ''], $empty);
        self::assertSame(['other.key' => 'value'], $absent);
        self::assertSame(['ramon-mail-reply.mailgun_signing_key' => null], $nonString);
    }
}
