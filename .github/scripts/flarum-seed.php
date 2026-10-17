<?php

/**
 * Popula o Flarum de teste do performance.yml: 30 discussões com 1 post de
 * abertura + 2 respostas cada, sendo as respostas marcadas como recebidas por
 * e-mail (row em `mail_reply_posts`) para que o badge "via e-mail" do forum.js
 * e o eager-load de `mailReplyMeta` entrem na medição. Roda na raiz do Flarum.
 */

declare(strict_types=1);

use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Illuminate\Support\Carbon;
use Ramon\MailReply\Database\MailReplyPost;

/** O site.php do skeleton já carrega o vendor/autoload.php. */
$site = require __DIR__.'/site.php';
$site->bootApp();

$admin = User::query()->where('username', 'admin')->firstOrFail();
$markViaMail = class_exists(MailReplyPost::class);

for ($i = 1; $i <= 30; $i++) {
    $title = sprintf('Discussion %02d — benchmark seed', $i);
    if (Discussion::query()->where('title', $title)->exists()) {
        continue;
    }

    $discussion = Discussion::start($title, $admin);
    $discussion->save();

    for ($n = 0; $n < 3; $n++) {
        $post = new CommentPost();
        $post->discussion_id = $discussion->id;
        $post->user_id = $admin->id;
        $post->created_at = Carbon::now();
        $post->setContentAttribute(
            $n === 0
                ? "Conteúdo de teste número $i para benchmark.\n\nlorem ipsum dolor sit amet."
                : "Resposta $n enviada por e-mail na discussão $i.\n\n> trecho citado removido pelo parser",
            $admin
        );
        $post->save();

        if ($n === 0) {
            $discussion->setFirstPost($post);
        } elseif ($markViaMail) {
            $meta = new MailReplyPost();
            $meta->post_id = (int) $post->id;
            $meta->save();
        }
    }

    $discussion->refreshLastPost();
    $discussion->refreshCommentCount();
    $discussion->refreshParticipantCount();
    $discussion->save();
}

echo "Seed concluído.\n";
