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
use Ramon\MailReply\Parser\EmailParser;

final class EmailParserTest extends TestCase
{
    private EmailParser $parser;

    protected function setUp(): void
    {
        $this->parser = new EmailParser();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function quoteHeaders(): array
    {
        return [
            'gmail en'        => ['On Mon, Jan 5, 2026 at 10:00 AM John Doe <john@example.com> wrote:'],
            'gmail pt-BR'     => ['Em seg., 5 de jan. de 2026 às 10:00, João Silva <joao@example.com> escreveu:'],
            'gmail de'        => ['Am Mo., 5. Jan. 2026 um 10:00 Uhr schrieb Hans Müller <hans@example.com>:'],
            'gmail fr'        => ['Le lun. 5 janv. 2026 à 10:00, Pierre Dupont <pierre@example.com> a écrit :'],
            'gmail ja'        => ['2026年1月5日(月) 10:00 山田太郎 <taro@example.com>:'],
            'apple en'        => ['On 5 Jan 2026, at 10:00, John Doe <john@example.com> wrote:'],
            'apple pt-BR'     => ['Em 5 de jan. de 2026, à(s) 10:00, João Silva <joao@example.com> escreveu:'],
            'outlook en'      => ['From: John Doe <john@example.com>'],
            'outlook pt-BR'   => ['De: João Silva <joao@example.com>'],
            'outlook de'      => ['Von: Hans Müller <hans@example.com>'],
            'outlook ja'      => ['差出人: 山田太郎 <taro@example.com>'],
            'original msg'    => ["-----Original Message-----\nFrom: John Doe <john@example.com>"],
            'mensagem orig.'  => ["-----Mensagem original-----\nDe: João Silva <joao@example.com>"],
            'gmail forward'   => ["---------- Forwarded message ---------\nFrom: John Doe <john@example.com>"],
        ];
    }

    #[DataProvider('quoteHeaders')]
    public function test_truncates_at_localized_quote_header(string $header): void
    {
        $body = "Obrigado, funcionou perfeitamente.\n\n$header\nSent: Monday\n> Mensagem antiga citada\n> com segunda linha\n";

        self::assertSame('Obrigado, funcionou perfeitamente.', $this->parser->extractReply($body));
    }

    public function test_multiline_reply_is_kept_intact_before_quote(): void
    {
        $body = "First paragraph.\n\nSecond paragraph with a list:\n- one\n- two\n\nOn Mon, Jan 5, 2026 at 10:00 AM X <x@example.com> wrote:\n> old";

        self::assertSame("First paragraph.\n\nSecond paragraph with a list:\n- one\n- two", $this->parser->extractReply($body));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function signatures(): array
    {
        return [
            'rfc dash-dash'    => ["Sounds good.\n\n-- \nJohn Doe\nACME Corp"],
            'sent from iphone' => ["Sounds good.\n\nSent from my iPhone"],
        ];
    }

    #[DataProvider('signatures')]
    public function test_strips_signatures(string $body): void
    {
        self::assertSame('Sounds good.', $this->parser->extractReply($body));
    }

    public function test_keeps_signature_when_stripping_is_disabled(): void
    {
        $reply = $this->parser->extractReply("Sounds good.\n\n-- \nJohn Doe", false);

        self::assertStringStartsWith('Sounds good.', $reply);
        self::assertStringContainsString('John Doe', $reply);
    }

    public function test_quote_lines_are_dropped_when_signatures_are_kept(): void
    {
        $reply = $this->parser->extractReply("Reply line\n> quoted\n> quoted again\nTail", false);

        self::assertSame("Reply line\nTail", $reply);
    }

    public function test_html_only_body_is_converted_to_text(): void
    {
        $html = '<html><body><div dir="ltr">Hello <b>there</b> &amp; welcome<br></div><p>Second line</p>'
            .'<div class="gmail_quote"><div dir="ltr" class="gmail_attr">On Mon, Jan 5, 2026 at 10:00 AM X &lt;x@example.com&gt; wrote:<br></div>'
            .'<blockquote class="gmail_quote">old stuff</blockquote></div></body></html>';

        $reply = $this->parser->extractReply($html);

        self::assertStringContainsString('Hello there & welcome', $reply);
        self::assertStringContainsString('Second line', $reply);
        self::assertStringNotContainsString('<b>', $reply);
        self::assertStringNotContainsString('wrote:', $reply);
        self::assertStringNotContainsString('old stuff', $reply);
    }

    public function test_leaked_mime_headers_and_boundaries_are_removed(): void
    {
        $body = implode("\n", [
            '--000000000000abcdef',
            'Content-Type: text/plain; charset="UTF-8"',
            '',
            'Actual reply text',
            '',
            '--000000000000abcdef',
            'Content-Type: text/html; charset="UTF-8"',
            '',
            '--000000000000abcdef--',
        ]);

        self::assertSame('Actual reply text', $this->parser->extractReply($body));
    }

    public function test_leaked_quoted_printable_is_decoded(): void
    {
        $body = "Content-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: quoted-printable\n\nA=C3=A7=C3=A3o feita=\n com sucesso";

        self::assertSame('Ação feita com sucesso', $this->parser->extractReply($body));
    }

    public function test_empty_or_blank_input_returns_empty_string(): void
    {
        self::assertSame('', $this->parser->extractReply(''));
        self::assertSame('', $this->parser->extractReply("  \n\t\n"));
    }

    public function test_body_made_only_of_a_quote_header_returns_empty(): void
    {
        self::assertSame('', $this->parser->extractReply("On Mon, Jan 5, 2026 at 10:00 AM X <x@example.com> wrote:\n> old"));
    }

    public function test_never_returns_empty_for_readable_content_that_parsers_would_wipe(): void
    {
        $reply = $this->parser->extractReply("> only quoted line\n> another quoted line");

        self::assertNotSame('', $reply);
        self::assertStringContainsString('only quoted line', $reply);
    }

    public function test_plain_sentence_with_colon_is_not_treated_as_quote_header(): void
    {
        self::assertSame("Here is the list:\nfirst item", $this->parser->extractReply("Here is the list:\nfirst item"));
    }

    public function test_dashed_original_message_marker_survives_mime_cleanup(): void
    {
        $body = "Thanks\n\n-----Original Message-----\nFrom: john@example.com\nSent: Monday, January 5, 2026 10:00 AM\nSubject: Re: hello\n\nOld message body";

        self::assertSame('Thanks', $this->parser->extractReply($body));
    }

    public function test_forwarded_marker_without_angle_address_truncates(): void
    {
        $body = "See below\n\n---------- Forwarded message ---------\nFrom: john@example.com\n\nForwarded body";

        self::assertSame('See below', $this->parser->extractReply($body));
    }

    public function test_html_block_quote_header_is_detected_and_blockquote_dropped(): void
    {
        $html = '<div>Hello</div><div>On 5 Jan 2026, at 10:00, X &lt;x@example.com&gt; wrote:</div><blockquote>OLD QUOTED</blockquote>';

        self::assertSame('Hello', $this->parser->extractReply($html));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function htmlQuoteContainers(): array
    {
        return [
            'gmail'       => ['<div dir="ltr">New text</div><br><div class="gmail_quote"><div class="gmail_attr">Em seg., 5 de jan. escreveu:</div><blockquote class="gmail_quote">OLD</blockquote></div>'],
            'outlook'     => ['<div>New text</div><hr><div id="divRplyFwdMsg"><b>From:</b> X</div><div>OLD</div>'],
            'apple'       => ['<html><body><div>New text</div><div><br><blockquote type="cite">OLD</blockquote></div></body></html>'],
            'thunderbird' => ['<p>New text</p><div class="moz-cite-prefix">On 1/5/26 X wrote:</div><blockquote type="cite">OLD</blockquote>'],
        ];
    }

    #[DataProvider('htmlQuoteContainers')]
    public function test_html_quote_containers_are_removed(string $html): void
    {
        self::assertSame('New text', $this->parser->extractReply($html));
    }

    public function test_mime_boundary_lines_are_still_stripped(): void
    {
        $body = "--=_Part_123_ABC\nContent-Type: text/plain; charset=utf-8\n\nActual reply\n--=_Part_123_ABC--";

        self::assertSame('Actual reply', $this->parser->extractReply($body));
    }

    public function test_gmail_attribution_wrapped_in_two_lines_is_cut(): void
    {
        $body = "Valeu pela dica.\n\nEm ter., 28 de out. de 2026 às 15:00, Fórum <noreply@forum.test>\nescreveu:\n\n> Bob respondeu.\n";

        self::assertSame('Valeu pela dica.', $this->parser->extractReply($body));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function mobileSignatures(): array
    {
        return [
            'iphone pt-BR' => ['Enviado do meu iPhone'],
            'iphone en' => ['Sent from my iPhone'],
            'android es' => ['Enviado desde mi Android'],
            'outlook app' => ['Obter o Outlook para Android'],
            'ipad fr' => ['Envoyé de mon iPad'],
            'de' => ['Von meinem iPhone gesendet'],
        ];
    }

    #[DataProvider('mobileSignatures')]
    public function test_mobile_signature_is_dropped(string $signature): void
    {
        self::assertSame('Ótimo, obrigado!', $this->parser->extractReply("Ótimo, obrigado!\n\n$signature\n"));
    }

    public function test_line_with_year_and_colon_inside_the_reply_is_kept(): void
    {
        $body = "Hi all, here are the 2025 results:\n- A: 10\n- B: 20";

        self::assertSame($body, $this->parser->extractReply($body));
        self::assertSame("Ok, agreed for 2026:\nplan", $this->parser->extractReply("Ok, agreed for 2026:\nplan"));
    }

    public function test_named_field_with_address_alone_is_not_an_outlook_header(): void
    {
        self::assertSame("Thanks!\nContact: Bob <bob@x.com>\nMore text", $this->parser->extractReply("Thanks!\nContact: Bob <bob@x.com>\nMore text"));
    }

    public function test_attribution_without_address_needs_quoted_lines_below(): void
    {
        self::assertSame('Funciona.', $this->parser->extractReply("Funciona.\n\nEm 28/10/2026 15:00, Fórum escreveu:\n> antigo\n"));
    }
}
