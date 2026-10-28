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

namespace Ramon\MailReply\Tests\unit\Token;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramon\MailReply\Mail\ReplyAddressConfig;
use Ramon\MailReply\Service\SecretBox;
use Ramon\MailReply\Tests\Support\ArraySettings;
use Ramon\MailReply\Tests\Support\Secrets;
use Ramon\MailReply\Token\TokenService;
use Ramon\MailReply\Tests\Support\Database;

final class TokenServiceTest extends TestCase
{
    private const SECRET_KEY = 'ramon-mail-reply.secret';

    private ArraySettings $settings;
    private SecretBox $box;

    protected function setUp(): void
    {
        if (! SecretBox::isSupported()) {
            self::markTestSkipped('libsodium XChaCha20-Poly1305 is not available.');
        }

        $this->settings = new ArraySettings(['ramon-mail-reply.reply_address' => 'reply@reply.forum.test']);
        $this->box = Secrets::box();
    }

    private function service(): TokenService
    {
        return new TokenService(
            $this->settings,
            new ReplyAddressConfig($this->settings),
            new Repository(new ArrayStore()),
            $this->box,
            new NullLogger(),
        );
    }

    public function test_round_trip_without_post_id(): void
    {
        $tokens = $this->service();
        $address = $tokens->mintAddress(42, 123);

        self::assertNotNull($address);
        self::assertMatchesRegularExpression('/^reply\+u42d123-[a-f0-9]{16}@reply\.forum\.test$/', $address);
        self::assertSame(['user_id' => 42, 'discussion_id' => 123, 'post_id' => null], $tokens->parseAddress($address));
    }

    public function test_round_trip_with_post_id(): void
    {
        $tokens = $this->service();
        $address = $tokens->mintAddress(42, 123, 987);

        self::assertMatchesRegularExpression('/^reply\+u42d123p987-[a-f0-9]{16}@reply\.forum\.test$/', (string) $address);
        self::assertSame(['user_id' => 42, 'discussion_id' => 123, 'post_id' => 987], $tokens->parseAddress((string) $address));
    }

    public function test_zero_post_id_is_treated_as_no_post(): void
    {
        $tokens = $this->service();

        self::assertSame($tokens->mintAddress(1, 2), $tokens->mintAddress(1, 2, 0));
    }

    public function test_display_name_and_angle_brackets_are_accepted(): void
    {
        $tokens = $this->service();
        $address = (string) $tokens->mintAddress(5, 6, 7);

        self::assertSame(
            ['user_id' => 5, 'discussion_id' => 6, 'post_id' => 7],
            $tokens->parseAddress('"Fórum, Respostas" <'.$address.'>'),
        );
    }

    public function test_signature_hex_is_case_insensitive(): void
    {
        $tokens = $this->service();
        $address = (string) $tokens->mintAddress(42, 123, 9);
        [$local, $domain] = explode('@', $address);
        [$prefix, $tag] = explode('+', $local);
        [$ids, $sig] = explode('-', $tag);

        self::assertSame(
            ['user_id' => 42, 'discussion_id' => 123, 'post_id' => 9],
            $tokens->parseAddress($prefix.'+'.$ids.'-'.strtoupper($sig).'@'.$domain),
        );
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $tokens = $this->service();
        $address = (string) $tokens->mintAddress(42, 123);
        $at = strpos($address, '@');
        $lastSigChar = $address[$at - 1];
        $tampered = substr($address, 0, $at - 1).($lastSigChar === '0' ? '1' : '0').substr($address, $at);

        self::assertNull($tokens->parseAddress($tampered));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function forgedIdentifiers(): array
    {
        return [
            'other user'       => ['u42d123', 'u43d123'],
            'other discussion' => ['u42d123', 'u42d124'],
            'added post id'    => ['u42d123', 'u42d123p1'],
        ];
    }

    #[DataProvider('forgedIdentifiers')]
    public function test_reusing_a_signature_for_other_identifiers_is_rejected(string $from, string $to): void
    {
        $tokens = $this->service();
        $forged = str_replace('+'.$from.'-', '+'.$to.'-', (string) $tokens->mintAddress(42, 123));

        self::assertNull($tokens->parseAddress($forged));
    }

    public function test_dropping_the_post_id_from_a_signed_alias_is_rejected(): void
    {
        $tokens = $this->service();
        $forged = str_replace('p987-', '-', (string) $tokens->mintAddress(42, 123, 987));

        self::assertNull($tokens->parseAddress($forged));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedAddresses(): array
    {
        return [
            'no plus'         => ['reply@reply.forum.test'],
            'no at'           => ['reply+u1d2-0123456789abcdef'],
            'short sig'       => ['reply+u1d2-0123@reply.forum.test'],
            'non-hex sig'     => ['reply+u1d2-0123456789abcdeg@reply.forum.test'],
            'missing ids'     => ['reply+-0123456789abcdef@reply.forum.test'],
            'garbage'         => ['not an address'],
        ];
    }

    #[DataProvider('malformedAddresses')]
    public function test_malformed_addresses_are_rejected(string $address): void
    {
        self::assertNull($this->service()->parseAddress($address));
    }

    public function test_wrong_local_part_is_rejected_even_with_a_valid_tag(): void
    {
        $tokens = $this->service();
        $address = (string) $tokens->mintAddress(42, 123);

        self::assertNull($tokens->parseAddress(str_replace('reply+', 'other+', $address)));
        self::assertNull($tokens->parseAddress(str_replace('reply+', 'Reply+', $address)));
    }

    public function test_mint_returns_null_without_a_configured_reply_address(): void
    {
        $this->settings->values = [];
        $tokens = $this->service();

        self::assertNull($tokens->mintAddress(1, 2));
        self::assertNull($tokens->parseAddress('reply+u1d2-0123456789abcdef@reply.forum.test'));
    }

    public function test_configured_plus_tag_is_ignored_in_the_local_part(): void
    {
        $this->settings->values['ramon-mail-reply.reply_address'] = 'inbox+forum@reply.forum.test';
        $tokens = $this->service();

        $address = (string) $tokens->mintAddress(1, 2);

        self::assertStringStartsWith('inbox+u1d2-', $address);
        self::assertNotNull($tokens->parseAddress($address));
    }

    public function test_generated_secret_is_persisted_encrypted_and_stable_across_instances(): void
    {
        $first = $this->service()->mintAddress(42, 123);

        $stored = (string) $this->settings->get(self::SECRET_KEY);
        self::assertTrue(SecretBox::isEncrypted($stored));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->box->decode($stored));

        $writes = $this->settings->writes;
        self::assertSame($first, $this->service()->mintAddress(42, 123));
        self::assertSame($writes, $this->settings->writes);
    }

    public function test_legacy_plaintext_secret_keeps_aliases_valid_and_is_re_encrypted(): void
    {
        $this->settings->values[self::SECRET_KEY] = 'legacy-plaintext-key';

        $tag = $this->service()->tag(42, 123);

        $expectedSig = substr(hash_hmac('sha256', 'u42d123', 'legacy-plaintext-key'), 0, 16);
        self::assertSame('u42d123-'.$expectedSig, $tag);

        $stored = (string) $this->settings->get(self::SECRET_KEY);
        self::assertTrue(SecretBox::isEncrypted($stored));
        self::assertSame('legacy-plaintext-key', $this->box->decode($stored));
        self::assertSame($tag, $this->service()->tag(42, 123));
    }

    public function test_aliases_from_a_different_secret_are_rejected(): void
    {
        $this->settings->values[self::SECRET_KEY] = $this->box->encode('key-a');
        $address = (string) $this->service()->mintAddress(42, 123);

        $this->settings->values[self::SECRET_KEY] = $this->box->encode('key-b');

        self::assertNull($this->service()->parseAddress($address));
    }

    public function test_key_written_by_another_worker_is_read_from_the_database(): void
    {
        $db = Database::use('sqlite');
        if ($db === null) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }
        $db->getSchemaBuilder()->dropIfExists('settings');
        $db->getSchemaBuilder()->create('settings', function ($table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
        });

        // Outro worker gerou a chave depois que este carregou as settings.
        $other = bin2hex(random_bytes(32));
        $db->table('settings')->insert(['key' => self::SECRET_KEY, 'value' => $this->box->encode($other)]);

        $tokens = new TokenService(
            $this->settings,
            new ReplyAddressConfig($this->settings),
            new Repository(new ArrayStore()),
            $this->box,
            new NullLogger(),
            $db,
        );
        $address = (string) $tokens->mintAddress(1, 2);

        self::assertArrayNotHasKey(self::SECRET_KEY, $this->settings->values);
        self::assertSame(substr(hash_hmac('sha256', 'u1d2', $other), 0, 16), substr($address, strpos($address, '-') + 1, 16));
    }
}
