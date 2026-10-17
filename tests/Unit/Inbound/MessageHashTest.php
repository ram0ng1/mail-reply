<?php

declare(strict_types=1);

namespace Ramon\MailReply\Tests\Unit\Inbound;

use PHPUnit\Framework\TestCase;
use Ramon\MailReply\Inbound\InboundProcessor;

final class MessageHashTest extends TestCase
{
    public function test_hash_is_sha256_hex(): void
    {
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', InboundProcessor::messageHash(['message_id' => '<a@b>']));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', InboundProcessor::messageHash([]));
    }

    public function test_message_id_is_normalized_for_brackets_case_and_whitespace(): void
    {
        $expected = InboundProcessor::messageHash(['message_id' => 'abc-123@mail.example.com']);

        self::assertSame($expected, InboundProcessor::messageHash(['message_id' => '<ABC-123@Mail.Example.com>']));
        self::assertSame($expected, InboundProcessor::messageHash(['message_id' => "  <abc-123@mail.example.com>\r\n"]));
    }

    public function test_message_id_wins_over_content(): void
    {
        $a = InboundProcessor::messageHash(['message_id' => '<x@y>', 'body_text' => 'one']);
        $b = InboundProcessor::messageHash(['message_id' => '<x@y>', 'body_text' => 'two']);

        self::assertSame($a, $b);
        self::assertNotSame($a, InboundProcessor::messageHash(['message_id' => '<z@y>', 'body_text' => 'one']));
    }

    public function test_without_message_id_hash_depends_on_sender_subject_and_bodies(): void
    {
        $base = ['from' => 'John@Example.com', 'subject' => 'Re: x', 'body_text' => 'hello', 'body_html' => ''];
        $hash = InboundProcessor::messageHash($base);

        self::assertSame($hash, InboundProcessor::messageHash(['from' => ' john@example.com '] + $base));
        self::assertNotSame($hash, InboundProcessor::messageHash(['subject' => 'Re: y'] + $base));
        self::assertNotSame($hash, InboundProcessor::messageHash(['body_text' => 'hello!'] + $base));
        self::assertNotSame($hash, InboundProcessor::messageHash(['body_html' => '<p>hello</p>'] + $base));
        self::assertNotSame($hash, InboundProcessor::messageHash(['from' => 'other@example.com'] + $base));
    }

    public function test_blank_message_id_falls_back_to_content_hash(): void
    {
        $payload = ['from' => 'a@b.c', 'body_text' => 'x'];

        self::assertSame(
            InboundProcessor::messageHash($payload),
            InboundProcessor::messageHash(['message_id' => ' <> '] + $payload),
        );
    }
}
