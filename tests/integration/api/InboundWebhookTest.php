<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\MailReply\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\Foundation\ValidationException;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Saving;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Ramon\MailReply\Database\InboundEmail;
use Ramon\MailReply\Token\TokenService;
use RuntimeException;

/**
 * O webhook de ponta a ponta: autenticação, o post criado e o que acontece
 * depois dele, com a fila `sync` dos testes (a de quem hospeda sem worker).
 */
class InboundWebhookTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const SECRET = 'segredo-do-webhook-de-teste';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'flarum-mentions', 'ramon-mail-reply');

        $this->setting('ramon-mail-reply.delivery_mode', 'webhook');
        $this->setting('ramon-mail-reply.webhook_secret', self::SECRET);
        $this->setting('ramon-mail-reply.reply_address', 'reply@forum.test');
        $this->setting('mail_driver', 'log');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Discussion::class => [
                ['id' => 1, 'title' => 'Discussão', 'user_id' => 1, 'created_at' => Carbon::now(), 'first_post_id' => 1, 'last_post_number' => 2, 'comment_count' => 2],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'user_id' => 1, 'number' => 1, 'type' => 'comment', 'content' => '<t><p>Primeiro</p></t>', 'created_at' => Carbon::now()],
                ['id' => 2, 'discussion_id' => 1, 'user_id' => 1, 'number' => 2, 'type' => 'comment', 'content' => '<t><p>Segundo</p></t>', 'created_at' => Carbon::now()],
            ],
        ]);
    }

    private function alias(?int $postId = null): string
    {
        return $this->app()->getContainer()->make(TokenService::class)->mintAddress(2, 1, $postId);
    }

    private function message(string $to, string $body, string $from = 'normal@machine.local', string $id = 'msg-1@client.test'): string
    {
        return "From: Normal <$from>\r\nTo: $to\r\nSubject: Re: Discussão\r\nMessage-ID: <$id>\r\n"
            ."Content-Type: text/plain; charset=UTF-8\r\n\r\n$body\r\n";
    }

    private function deliver(string $raw, string $token = self::SECRET): ResponseInterface
    {
        $request = $this->request('POST', '/api/mail-reply/inbound')
            ->withHeader('Content-Type', 'message/rfc822')
            ->withBody((new StreamFactory())->createStream($raw));

        return $this->send($token === '' ? $request : $request->withHeader('X-Mail-Reply-Token', $token));
    }

    public function test_sem_token_ou_com_token_errado_e_401(): void
    {
        $this->assertSame(401, $this->deliver($this->message($this->alias(), 'Oi'), '')->getStatusCode());
        $this->assertSame(401, $this->deliver($this->message($this->alias(), 'Oi'), 'errado')->getStatusCode());
    }

    public function test_fora_do_modo_webhook_e_404(): void
    {
        $this->setting('ramon-mail-reply.delivery_mode', 'imap');

        $this->assertSame(404, $this->deliver($this->message('reply+x@forum.test', 'Oi'))->getStatusCode());
    }

    public function test_resposta_vira_post_citando_o_post_e_marca_a_discussao_como_lida(): void
    {
        $response = $this->deliver($this->message($this->alias(2), "Resposta pelo e-mail.\n\nOn Tue, 28 Oct 2026, Admin <admin@forum.test> wrote:\n> Segundo"));

        $this->assertSame(202, $response->getStatusCode(), (string) $response->getBody());

        $post = CommentPost::query()->where('discussion_id', 1)->where('user_id', 2)->firstOrFail();
        $this->assertSame(3, (int) $post->number);
        $this->assertStringStartsWith('@"admin"#p2', $post->content);
        $this->assertStringContainsString('Resposta pelo e-mail.', $post->content);
        $this->assertNotNull($post->mailReplyMeta);

        // O core marca a discussão como lida para quem responde; sem isso o
        // flarum/subscriptions deixa de avisar dos posts seguintes.
        $read = $this->database()->table('discussion_user')->where('discussion_id', 1)->where('user_id', 2)->value('last_read_post_number');
        $this->assertSame(3, (int) $read);
    }

    public function test_falha_inesperada_devolve_500_para_o_mta_tentar_de_novo(): void
    {
        $this->extend((new Extend\Event())->listen(Saving::class, function () {
            throw new RuntimeException('banco fora do ar');
        }));

        $response = $this->deliver($this->message($this->alias(), 'Resposta'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(0, CommentPost::query()->where('user_id', 2)->count());
        // Sem registro: a reentrega do MTA processa a mensagem de novo.
        $this->assertSame(0, InboundEmail::query()->count());
    }

    public function test_recusa_de_outra_extensao_vira_rejeicao_registrada(): void
    {
        $this->extend((new Extend\Event())->listen(Saving::class, function () {
            throw new ValidationException(['content' => 'Palavra proibida.']);
        }));

        $response = $this->deliver($this->message($this->alias(), 'Resposta'));

        $this->assertSame(202, $response->getStatusCode());
        $row = InboundEmail::query()->firstOrFail();
        $this->assertSame(InboundEmail::STATUS_REJECTED, $row->status);
        $this->assertSame('validation', $row->reason);
    }

    public function test_limite_por_hora_vazio_usa_o_padrao(): void
    {
        $this->setting('ramon-mail-reply.max_replies_per_hour', '');

        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = ['message_hash' => hash('sha256', "old-$i"), 'user_id' => 2, 'discussion_id' => 1, 'status' => 'processed', 'created_at' => Carbon::now()];
        }
        $this->database()->table('mail_reply_inbound')->insert($rows);

        $this->deliver($this->message($this->alias(), 'Mais uma'));

        $row = InboundEmail::query()->whereNotIn('message_hash', array_column($rows, 'message_hash'))->firstOrFail();
        $this->assertSame('rate_limited', $row->reason);
    }

    public function test_mailgun_confere_o_from_do_cabecalho_e_nao_o_envelope(): void
    {
        // Valor legado em texto puro: o SecretBox aceita e recifra depois.
        $this->setting('ramon-mail-reply.mailgun_signing_key', 'chave-mailgun');

        $timestamp = (string) time();
        $token = bin2hex(random_bytes(16));
        $body = http_build_query([
            'recipient' => $this->alias(),
            // Envelope reescrito por SRS num encaminhamento: não é o remetente.
            'sender' => 'SRS0=abcd=XY=machine.local=normal@relay.example',
            'from' => 'Normal <normal@machine.local>',
            'subject' => 'Re: Discussão',
            'body-plain' => 'Resposta pelo Mailgun',
            'Message-Id' => '<mailgun-1@client.test>',
            'timestamp' => $timestamp,
            'token' => $token,
            'signature' => hash_hmac('sha256', $timestamp.$token, 'chave-mailgun'),
        ]);
        parse_str($body, $parsed);

        $response = $this->send($this->request('POST', '/api/mail-reply/inbound')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody($parsed)
            ->withBody((new StreamFactory())->createStream($body)));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame(InboundEmail::STATUS_PROCESSED, InboundEmail::query()->value('status'));
    }

    public function test_falha_de_dmarc_acusada_pelo_servidor_recusa(): void
    {
        $raw = "Authentication-Results: mx.forum.test; spf=fail smtp.mailfrom=evil.test; dmarc=fail (p=reject) header.from=machine.local\r\n"
            // Cabeçalho mais abaixo, posto por quem enviou: não vale.
            ."Authentication-Results: evil.test; dmarc=pass\r\n"
            .$this->message($this->alias(), 'Resposta forjada');

        $this->assertSame(202, $this->deliver($raw)->getStatusCode());
        $this->assertSame('sender_unauthenticated', InboundEmail::query()->value('reason'));
        $this->assertSame(0, CommentPost::query()->where('user_id', 2)->count());
    }

    public function test_status_so_para_admin(): void
    {
        $this->assertSame(403, $this->send($this->request('GET', '/api/mail-reply/status', ['authenticatedAs' => 2]))->getStatusCode());

        $response = $this->send($this->request('GET', '/api/mail-reply/status', ['authenticatedAs' => 1]));
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('webhook', $data['mode']);
        $this->assertArrayNotHasKey('webhook_secret', $data);
    }
}
