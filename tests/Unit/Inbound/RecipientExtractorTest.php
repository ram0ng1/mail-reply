<?php

declare(strict_types=1);

namespace Ramon\MailReply\Tests\Unit\Inbound;

use PHPUnit\Framework\TestCase;
use Ramon\MailReply\Inbound\RecipientExtractor;

final class RecipientExtractorTest extends TestCase
{
    public function test_envelope_headers_come_before_visible_to_and_cc(): void
    {
        $headers = [
            'to'              => ['Forum <noreply@forum.test>'],
            'cc'              => ['cc@example.com'],
            'x-original-to'   => ['reply+u1d2-aaaaaaaaaaaaaaaa@reply.forum.test'],
            'x-forwarded-to'  => ['fwd@example.com'],
        ];

        self::assertSame([
            'reply+u1d2-aaaaaaaaaaaaaaaa@reply.forum.test',
            'fwd@example.com',
            'noreply@forum.test',
            'cc@example.com',
        ], RecipientExtractor::fromHeaders($headers));
    }

    public function test_received_for_clause_is_used_last(): void
    {
        $headers = [
            'to'       => ['visible@example.com'],
            'received' => [
                'from a by b with ESMTP id 123 for <hidden+tag@reply.forum.test>; Mon, 5 Jan 2026 10:00:00 +0000',
                'from c by d with LMTP for bare@reply.forum.test; Mon, 5 Jan 2026 10:00:00 +0000',
                'from e by f with SMTP id 99',
            ],
        ];

        self::assertSame(
            ['visible@example.com', 'hidden+tag@reply.forum.test', 'bare@reply.forum.test'],
            RecipientExtractor::fromHeaders($headers),
        );
    }

    public function test_duplicates_are_removed_and_scalar_header_values_are_accepted(): void
    {
        $headers = [
            'delivered-to' => 'same@example.com',
            'to'           => 'Same <same@example.com>, other@example.com',
        ];

        self::assertSame(['same@example.com', 'other@example.com'], RecipientExtractor::fromHeaders($headers));
    }

    public function test_split_addresses_respects_commas_inside_quotes(): void
    {
        self::assertSame(
            ['john@example.com', 'jane@example.com', 'plain@example.com'],
            RecipientExtractor::splitAddresses('"Doe, John" <john@example.com>, "Roe, Jane" <jane@example.com>,plain@example.com'),
        );
    }

    public function test_first_address_prefers_angle_brackets_and_handles_bare_or_missing(): void
    {
        self::assertSame('a+tag@x.example', RecipientExtractor::firstAddress('Display a@decoy.example <a+tag@x.example>'));
        self::assertSame('bare.name=x@sub.example.org', RecipientExtractor::firstAddress('bare.name=x@sub.example.org'));
        self::assertSame('', RecipientExtractor::firstAddress('undisclosed-recipients:;'));
        self::assertSame([], RecipientExtractor::fromHeaders([]));
    }
}
