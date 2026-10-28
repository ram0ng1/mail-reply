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

namespace Ramon\MailReply\Tests\unit\Parser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramon\MailReply\Parser\Rfc822Parser;

final class Rfc822ParserTest extends TestCase
{
    private Rfc822Parser $parser;

    protected function setUp(): void
    {
        $this->parser = new Rfc822Parser();
    }

    /**
     * @param list<string> $lines
     */
    private static function crlf(array $lines): string
    {
        return implode("\r\n", $lines);
    }

    public function test_apple_mail_multipart_with_mixed_case_boundary_and_preamble(): void
    {
        $boundary = 'Apple-Mail=_3F1A2B4C-ABcd-EfGh-9876-5A4B3C2D1E0F';
        $raw = self::crlf([
            'From: "Doe, John" <john@example.com>',
            'To: reply+u1d2-0123456789abcdef@reply.example.com',
            'Subject: Re: Hello',
            'Message-Id: <ABC-123@mail.example.com>',
            'Mime-Version: 1.0 (Mac OS X Mail 16.0)',
            'Content-Type: multipart/alternative;',
            "\tboundary=\"$boundary\"",
            '',
            'This is a multi-part message in MIME format.',
            '',
            "--$boundary",
            'Content-Transfer-Encoding: 7bit',
            'Content-Type: text/plain;',
            "\tcharset=us-ascii",
            '',
            'Thanks, that fixed it.',
            '',
            "--$boundary",
            'Content-Transfer-Encoding: 7bit',
            'Content-Type: text/html;',
            "\tcharset=us-ascii",
            '',
            '<html><body><div>Thanks, that fixed it.</div></body></html>',
            "--$boundary--",
            '',
        ]);

        $parsed = $this->parser->parse($raw);

        self::assertSame('Thanks, that fixed it.', $parsed['body_text']);
        self::assertStringNotContainsString('multi-part message', $parsed['body_text']);
        self::assertStringContainsString('<div>Thanks, that fixed it.</div>', $parsed['body_html']);
        self::assertSame('john@example.com', $parsed['from']);
        self::assertSame('<ABC-123@mail.example.com>', $parsed['message_id']);
        self::assertSame('Re: Hello', $parsed['subject']);
    }

    public function test_boundary_lookup_is_case_sensitive(): void
    {
        $raw = self::crlf([
            'Content-Type: multipart/alternative; boundary="AbC"',
            '',
            '--abc',
            'Content-Type: text/plain',
            '',
            'wrong boundary part',
            '--AbC',
            'Content-Type: text/plain',
            '',
            'right part',
            '--AbC--',
        ]);

        self::assertSame('right part', $this->parser->parse($raw)['body_text']);
    }

    public function test_nested_multipart_skips_attachments_and_finds_inner_alternative(): void
    {
        $raw = self::crlf([
            'From: ana@example.com',
            'Content-Type: multipart/mixed; boundary=outer',
            '',
            '--outer',
            'Content-Type: text/plain; name="notes.txt"',
            'Content-Disposition: attachment; filename="notes.txt"',
            '',
            'ATTACHMENT TEXT MUST NOT BE THE BODY',
            '--outer',
            'Content-Type: multipart/alternative; boundary="inner"',
            '',
            '--inner',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            'Inner plain reply',
            '--inner',
            'Content-Type: text/html; charset=UTF-8',
            '',
            '<p>Inner html reply</p>',
            '--inner--',
            '--outer',
            'Content-Type: application/pdf; name="doc.pdf"',
            'Content-Disposition: attachment; filename="doc.pdf"',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode('%PDF-1.4 fake'),
            '--outer--',
        ]);

        $parsed = $this->parser->parse($raw);

        self::assertSame('Inner plain reply', $parsed['body_text']);
        self::assertSame('<p>Inner html reply</p>', $parsed['body_html']);
        self::assertStringNotContainsString('ATTACHMENT', $parsed['body_text']);
        self::assertStringNotContainsString('PDF', $parsed['body_text'].$parsed['body_html']);
    }

    public function test_base64_utf8_part_is_decoded(): void
    {
        $encoded = chunk_split(base64_encode("Olá, mundo! Ação concluída. 日本語"), 20, "\r\n");
        $raw = self::crlf([
            'Content-Type: multipart/alternative; boundary="b1"',
            '',
            '--b1',
            'Content-Type: text/plain; charset="UTF-8"',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim($encoded),
            '--b1--',
        ]);

        self::assertSame('Olá, mundo! Ação concluída. 日本語', $this->parser->parse($raw)['body_text']);
    }

    public function test_quoted_printable_iso_8859_1_is_converted_to_utf8(): void
    {
        $raw = self::crlf([
            'Content-Type: text/plain; charset=ISO-8859-1',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            'Ol=E1, a not=EDcia est=E1 =F3tima e a linha =',
            'continua aqui.',
        ]);

        $body = $this->parser->parse($raw)['body_text'];

        self::assertTrue(mb_check_encoding($body, 'UTF-8'));
        self::assertSame('Olá, a notícia está ótima e a linha continua aqui.', $body);
    }

    public function test_windows_1252_smart_quotes_and_euro_are_converted(): void
    {
        $raw = self::crlf([
            'Content-Type: multipart/alternative; boundary=w',
            '',
            '--w',
            'Content-Type: text/plain; charset=windows-1252',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode("\x93Pre\xE7o\x94 \x80 10 \x96 ok"),
            '--w--',
        ]);

        self::assertSame("\u{201C}Pre\u{00E7}o\u{201D} \u{20AC} 10 \u{2013} ok", $this->parser->parse($raw)['body_text']);
    }

    public function test_body_without_declared_charset_that_is_not_utf8_is_treated_as_windows_1252(): void
    {
        $raw = "Content-Type: text/plain\n\nCaf\xE9 com a\xE7\xFAcar";

        self::assertSame('Café com açúcar', $this->parser->parse($raw)['body_text']);
    }

    public function test_invalid_utf8_in_declared_utf8_part_is_scrubbed(): void
    {
        $raw = "Content-Type: text/plain; charset=utf-8\n\nok \xFF end";

        $body = $this->parser->parse($raw)['body_text'];

        self::assertTrue(mb_check_encoding($body, 'UTF-8'));
        self::assertStringStartsWith('ok ', $body);
        self::assertStringEndsWith(' end', $body);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function encodedSubjects(): array
    {
        return [
            'utf8 base64'        => ['=?UTF-8?B?UmU6IE9sw6EgbXVuZG8=?=', 'Re: Olá mundo'],
            'iso-8859-1 Q'       => ['=?ISO-8859-1?Q?Re:_Not=EDcia_nova?=', 'Re: Notícia nova'],
            'mixed plain + word' => ['Re: =?UTF-8?Q?a=C3=A7=C3=A3o?= pendente', 'Re: ação pendente'],
            'plain ascii'        => ['Re: plain subject', 'Re: plain subject'],
        ];
    }

    #[DataProvider('encodedSubjects')]
    public function test_encoded_word_subject_is_decoded(string $header, string $expected): void
    {
        $parsed = $this->parser->parse("Subject: $header\nContent-Type: text/plain\n\nbody");

        self::assertSame($expected, $parsed['subject']);
    }

    public function test_folded_encoded_subject_is_joined(): void
    {
        $raw = self::crlf([
            'Subject: =?UTF-8?B?UmU6IE9sw6E=?=',
            ' =?UTF-8?B?IG11bmRv?=',
            'Content-Type: text/plain',
            '',
            'x',
        ]);

        self::assertSame('Re: Olá mundo', $this->parser->parse($raw)['subject']);
    }

    public function test_envelope_and_received_headers_are_collected_in_priority_order(): void
    {
        $raw = self::crlf([
            'Received: from mx.example.net by mail.forum.test; Mon, 5 Jan 2026 10:00:00 +0000',
            'Received: from relay.example.net (relay [10.0.0.1])',
            "\tby mx.example.net with ESMTPS",
            "\tfor <reply+u7d9-aaaaaaaaaaaaaaaa@reply.forum.test>; Mon, 5 Jan 2026 09:59:59 +0000",
            'Envelope-To: reply+u7d9-aaaaaaaaaaaaaaaa@reply.forum.test',
            'Delivered-To: inbox@reply.forum.test',
            'To: "Forum" <noreply@forum.test>',
            'Cc: Someone <cc@example.com>, other@example.com',
            'From: user@example.com',
            'Content-Type: text/plain',
            '',
            'hi',
        ]);

        $parsed = $this->parser->parse($raw);

        self::assertSame([
            'reply+u7d9-aaaaaaaaaaaaaaaa@reply.forum.test',
            'inbox@reply.forum.test',
            'noreply@forum.test',
            'cc@example.com',
            'other@example.com',
        ], $parsed['to']);
        self::assertCount(2, $parsed['headers']['received']);
        self::assertStringContainsString('for <reply+u7d9-aaaaaaaaaaaaaaaa@reply.forum.test>', $parsed['headers']['received'][1]);
    }

    public function test_single_part_html_goes_to_body_html(): void
    {
        $parsed = $this->parser->parse("Content-Type: text/html; charset=utf-8\n\n<p>Oi</p>");

        self::assertSame('', $parsed['body_text']);
        self::assertSame('<p>Oi</p>', $parsed['body_html']);
    }

    public function test_multipart_without_boundary_parameter_detects_boundary_from_body(): void
    {
        $raw = implode("\n", [
            'Content-Type: multipart/alternative',
            '',
            '--detected123',
            'Content-Type: text/plain',
            '',
            'detected body',
            '--detected123--',
        ]);

        self::assertSame('detected body', $this->parser->parse($raw)['body_text']);
    }

    public function test_message_without_headers_separator_still_returns_structure(): void
    {
        $parsed = $this->parser->parse('From: a@example.com');

        self::assertSame('a@example.com', $parsed['from']);
        self::assertSame('', $parsed['body_text']);
        self::assertSame([], $parsed['to']);
    }

    public function test_parse_header_block_keeps_repeated_headers_and_lowercases_names(): void
    {
        $headers = Rfc822Parser::parseHeaderBlock("X-Test: one\nX-TEST: two\nSubject: long\n  continued");

        self::assertSame(['one', 'two'], $headers['x-test']);
        self::assertSame(['long continued'], $headers['subject']);
        self::assertSame('one', Rfc822Parser::first($headers, 'x-test'));
        self::assertSame('', Rfc822Parser::first($headers, 'missing'));
        self::assertSame('scalar', Rfc822Parser::first(['k' => 'scalar'], 'k'));
    }

    public function test_part_without_headers_keeps_its_first_paragraph(): void
    {
        $raw = "From: a@example.com\nTo: b@example.com\nContent-Type: multipart/alternative; boundary=\"b1\"\n\n"
            ."--b1\n\nPlain reply text\n\nsecond para\n"
            ."--b1\nContent-Type: text/html\n\n<p>Plain reply text</p>\n"
            ."--b1--\n";

        self::assertSame("Plain reply text\n\nsecond para", (new Rfc822Parser())->parse($raw)['body_text']);
    }

    public function test_multipart_without_text_parts_does_not_fall_back_to_raw_mime(): void
    {
        $raw = "From: a@example.com\nTo: b@example.com\nContent-Type: multipart/mixed; boundary=\"b2\"\n\n"
            ."--b2\nContent-Type: image/png\nContent-Transfer-Encoding: base64\n\niVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==\n"
            ."--b2--\n";

        $parsed = (new Rfc822Parser())->parse($raw);

        self::assertSame('', $parsed['body_text']);
        self::assertSame('', $parsed['body_html']);
    }

    public function test_exim_mbox_from_line_and_envelope_headers(): void
    {
        $raw = "From alice@client.test Wed Oct 28 16:32:36 2026\nReturn-path: <alice@client.test>\n"
            ."Envelope-to: reply+u2d1-abcdef0123456789@forum.test\nFrom: Alice <alice@client.test>\n"
            ."To: \"Forum\" <reply+u2d1-abcdef0123456789@forum.test>\nMessage-ID: <m@client.test>\nSubject: Re: x\n\nOi\n";

        $parsed = (new Rfc822Parser())->parse($raw);

        self::assertSame('alice@client.test', $parsed['from']);
        self::assertSame('<m@client.test>', $parsed['message_id']);
        self::assertSame(['reply+u2d1-abcdef0123456789@forum.test'], $parsed['to']);
    }
}
