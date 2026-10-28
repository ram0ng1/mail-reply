<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Cenário da matriz de hospedagem, rodado na raiz do fórum de teste:
 *
 *   php scenario.php setup <hosting> <delivery> <out-dir>
 *   php scenario.php assert <out-dir>
 *
 * `setup` grava as configurações como o admin faria, cria as contas e a
 * discussão, cunha os aliases e escreve as mensagens (uma por cliente de
 * e-mail, mais os casos negativos) em <out-dir>/messages. `assert` confere
 * o que chegou ao banco e grava result.json + result.md.
 */

declare(strict_types=1);

use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Support\Carbon;
use Ramon\MailReply\Database\InboundEmail;
use Ramon\MailReply\Database\MailReplyPost;
use Ramon\MailReply\Inbound\InboundProcessor;
use Ramon\MailReply\Service\SecretBox;
use Ramon\MailReply\Token\TokenService;

$site = require getcwd().'/site.php';
$app = $site->bootApp();
$container = $app->getContainer();

// Valores só de teste, para o fórum descartável da matriz.
const WEBHOOK_SECRET = 'segredo-de-teste-da-matriz-de-hospedagem';
const MAILGUN_KEY = 'key-mailgun-signing-test';
const IMAP_PASSWORD = 'imap-secret';

$command = $argv[1] ?? '';

/** @var SettingsRepositoryInterface $settings */
$settings = $container->make(SettingsRepositoryInterface::class);

if ($command === 'setup') {
    [$hosting, $delivery, $out] = [$argv[2], $argv[3], rtrim($argv[4], '/')];
    $profile = profile(__DIR__.'/profiles/'.$hosting.'.env');
    $box = $container->make(SecretBox::class);

    $values = [
        'reply_address' => 'reply@forum.test',
        'delivery_mode' => str_starts_with($delivery, 'imap') ? 'imap' : 'webhook',
        'webhook_secret' => WEBHOOK_SECRET,
        'mailgun_signing_key' => $box->encode(MAILGUN_KEY),
        'imap_host' => 'imap.forum.test',
        'imap_port' => (string) $profile['IMAP_PORT'],
        'imap_encryption' => (string) $profile['IMAP_ENCRYPTION'],
        'imap_validate_cert' => (string) $profile['IMAP_VALIDATE_CERT'],
        'imap_username' => 'reply@forum.test',
        'imap_password' => $box->encode(IMAP_PASSWORD),
        'imap_mailbox' => 'INBOX',
        'imap_delete_after' => '1',
        'require_sender_match' => '1',
        'send_rejection_notices' => '0',
    ];

    foreach ($values as $key => $value) {
        $settings->set('ramon-mail-reply.'.$key, $value);
    }
    $settings->set('mail_driver', 'log');

    $alice = user('alice', 'alice@client.test');
    $bob = user('bob', 'bob@client.test');

    $discussion = Discussion::start('Resposta por e-mail na hospedagem '.$hosting, $bob);
    $discussion->save();
    $first = post($discussion, $bob, 'Abrindo a discussão para testar a resposta por e-mail.');
    $discussion->setFirstPost($first);
    $second = post($discussion, $bob, 'Teste a resposta por e-mail.');
    $discussion->refreshLastPost();
    $discussion->refreshCommentCount();
    $discussion->save();

    /** @var TokenService $tokens */
    $tokens = $container->make(TokenService::class);
    $toDiscussion = $tokens->mintAddress((int) $alice->id, (int) $discussion->id);
    $toPost = $tokens->mintAddress((int) $alice->id, (int) $discussion->id, (int) $second->id);
    $forged = substr($toDiscussion, 0, strpos($toDiscussion, '@') - 1).'0@forum.test';
    if ($forged === $toDiscussion) {
        $forged = substr($toDiscussion, 0, strpos($toDiscussion, '@') - 1).'1@forum.test';
    }

    $mention = '@"bob"#p'.$second->id;
    $cases = [
        ['id' => 'gmail', 'fixture' => 'gmail', 'to' => $toDiscussion, 'from' => 'alice@client.test',
            'expect' => 'processed', 'text' => 'Valeu pela dica, funcionou aqui na hospedagem.'],
        ['id' => 'outlook', 'fixture' => 'outlook', 'to' => $toPost, 'from' => 'alice@client.test',
            'expect' => 'processed', 'text' => $mention."\n\nTestei pelo Outlook e chegou certinho."],
        ['id' => 'apple-mail', 'fixture' => 'apple-mail', 'to' => $toDiscussion, 'from' => 'alice@client.test',
            'expect' => 'processed', 'text' => 'Respondendo do iPhone: ótimo, obrigado!'],
        ['id' => 'thunderbird', 'fixture' => 'thunderbird', 'to' => $toDiscussion, 'from' => 'alice@client.test',
            'expect' => 'processed', 'text' => "Thunderbird também funciona.\nSegunda linha da resposta."],
        ['id' => 'auto-reply', 'fixture' => 'auto-reply', 'to' => $toDiscussion, 'from' => 'alice@client.test',
            'expect' => 'ignored', 'reason' => 'auto_submitted'],
        ['id' => 'spoofed-sender', 'fixture' => 'spoofed-sender', 'to' => $toDiscussion, 'from' => 'mallory@evil.test',
            'expect' => 'rejected', 'reason' => 'sender_mismatch'],
        ['id' => 'forged-alias', 'fixture' => 'forged-alias', 'to' => $forged, 'from' => 'alice@client.test',
            'expect' => 'rejected', 'reason' => 'invalid_address'],
        // Mesma Message-ID do gmail: a reentrega não pode virar um segundo post.
        ['id' => 'duplicate', 'fixture' => 'gmail', 'to' => $toDiscussion, 'from' => 'alice@client.test',
            'expect' => 'duplicate', 'same_as' => 'gmail'],
    ];

    @mkdir($out.'/messages', 0777, true);
    $date = Carbon::now()->toRfc2822String();
    foreach ($cases as $i => $case) {
        $messageId = ($case['same_as'] ?? $case['id']).'.'.$hosting.'.'.$delivery.'@client.test';
        $cases[$i]['message_id'] = $messageId;
        $raw = strtr((string) file_get_contents(__DIR__.'/fixtures/'.$case['fixture'].'.eml'), [
            '{{TO}}' => $case['to'],
            '{{FROM}}' => $case['from'],
            '{{MESSAGE_ID}}' => $messageId,
            '{{DATE}}' => $date,
            '{{TITLE}}' => $discussion->title,
        ]);
        // Mensagens trafegam com CRLF no SMTP.
        file_put_contents($out.'/messages/'.sprintf('%02d', $i).'-'.$case['id'].'.eml', str_replace("\n", "\r\n", $raw));
    }

    file_put_contents($out.'/manifest.json', json_encode([
        'hosting' => $hosting,
        'label' => $profile['HOSTING_LABEL'],
        'delivery' => $delivery,
        'discussion_id' => (int) $discussion->id,
        'alice_id' => (int) $alice->id,
        'posts_before' => (int) Post::query()->where('discussion_id', $discussion->id)->count(),
        'webhook_secret' => WEBHOOK_SECRET,
        'mailgun_key' => MAILGUN_KEY,
        'cases' => $cases,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    echo "setup ok: discussion #{$discussion->id}, ".count($cases)." mensagens\n";
    exit(0);
}

if ($command === 'count') {
    echo InboundEmail::query()->count(), "\n";
    exit(0);
}

if ($command === 'assert') {
    $out = rtrim($argv[2], '/');
    $manifest = json_decode((string) file_get_contents($out.'/manifest.json'), true);
    $checks = [];

    foreach ($manifest['cases'] as $case) {
        $hash = InboundProcessor::messageHash(['message_id' => $case['message_id']]);
        /** @var InboundEmail|null $row */
        $row = InboundEmail::query()->where('message_hash', $hash)->first();
        $ok = false;
        $detail = $row ? $row->status.($row->reason ? ' ('.$row->reason.')' : '') : 'sem registro';

        if ($case['expect'] === 'processed') {
            $post = $row?->post_id ? CommentPost::query()->find($row->post_id) : null;
            $content = $post ? trim(str_replace("\r\n", "\n", (string) $post->content)) : null;
            $ok = $row?->status === 'processed'
                && $post !== null
                && (int) $post->user_id === (int) User::query()->where('username', 'alice')->value('id')
                && $content === $case['text']
                && MailReplyPost::query()->whereKey($post->id)->exists();
            if (! $ok && $content !== null) {
                $detail .= ' · conteúdo: '.json_encode($content, JSON_UNESCAPED_UNICODE);
            }
        } elseif ($case['expect'] === 'duplicate') {
            $ok = InboundEmail::query()->where('message_hash', $hash)->count() === 1;
            $detail = 'um registro para a Message-ID';
        } else {
            $ok = $row?->status === $case['expect'] && $row->reason === $case['reason'];
        }

        $checks[] = ['case' => $case['id'], 'ok' => $ok, 'detail' => $detail];
    }

    $postsAfter = (int) Post::query()->where('discussion_id', $manifest['discussion_id'])->count();
    $expectedPosts = $manifest['posts_before'] + count(array_filter($manifest['cases'], fn ($c) => $c['expect'] === 'processed'));
    $checks[] = ['case' => 'posts', 'ok' => $postsAfter === $expectedPosts, 'detail' => "$postsAfter posts (esperado $expectedPosts)"];

    // Quem responde por e-mail leu a discussão até a própria resposta; sem
    // isso o flarum/subscriptions para de avisar dos posts seguintes.
    $lastOwn = (int) CommentPost::query()->where('discussion_id', $manifest['discussion_id'])
        ->where('user_id', $manifest['alice_id'])->max('number');
    $read = (int) $container->make('db')->table('discussion_user')
        ->where('discussion_id', $manifest['discussion_id'])->where('user_id', $manifest['alice_id'])
        ->value('last_read_post_number');
    $checks[] = ['case' => 'lido-ate-a-resposta', 'ok' => $lastOwn > 0 && $read >= $lastOwn, 'detail' => "lido até #$read, última resposta #$lastOwn"];

    foreach (json_decode((string) @file_get_contents($out.'/extra-checks.json'), true) ?: [] as $extra) {
        $checks[] = $extra;
    }

    $passed = count(array_filter($checks, fn ($c) => $c['ok']));
    $result = [
        'hosting' => $manifest['hosting'],
        'label' => $manifest['label'],
        'delivery' => $manifest['delivery'],
        'passed' => $passed,
        'total' => count($checks),
        'ok' => $passed === count($checks),
        'checks' => $checks,
    ];
    file_put_contents($out.'/result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $md = sprintf("### %s × %s — %d/%d\n\n| Caso | | Detalhe |\n| --- | --- | --- |\n", $manifest['label'], $manifest['delivery'], $passed, count($checks));
    foreach ($checks as $c) {
        $md .= sprintf("| %s | %s | %s |\n", $c['case'], $c['ok'] ? '✅' : '❌', str_replace('|', '\|', $c['detail']));
    }
    file_put_contents($out.'/result.md', $md);
    echo $md;
    exit($result['ok'] ? 0 : 1);
}

fwrite(STDERR, "uso: scenario.php setup|assert|count ...\n");
exit(2);

/**
 * Lê o .env do perfil (KEY=valor, # comenta), o mesmo que o bash faz `source`.
 *
 * @return array<string, string>
 */
function profile(string $file): array
{
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $m)) {
            $out[$m[1]] = trim($m[2], '"');
        }
    }

    return $out;
}

function user(string $username, string $email): User
{
    $user = User::query()->where('username', $username)->first() ?? new User();
    $user->username = $username;
    $user->email = $email;
    $user->is_email_confirmed = true;
    $user->password = 'secret-'.$username;
    $user->joined_at ??= Carbon::now();
    $user->save();
    $user->groups()->syncWithoutDetaching([3]);

    return $user;
}

function post(Discussion $discussion, User $user, string $content): CommentPost
{
    $post = new CommentPost();
    $post->discussion_id = $discussion->id;
    $post->user_id = $user->id;
    $post->created_at = Carbon::now();
    $post->setContentAttribute($content, $user);
    $post->save();

    return $post;
}
