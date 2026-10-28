<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\MailReply;

use Flarum\Api\Endpoint;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\PostResource;
use Flarum\Extend;
use Flarum\Post\Post;
use Flarum\Settings\Event\Saving;
use Ramon\MailReply\Api\Controller\InboundEmailController;
use Ramon\MailReply\Api\Controller\StatusController;
use Ramon\MailReply\Api\Controller\TestImapController;
use Ramon\MailReply\Api\ExposeViaMailReply;
use Ramon\MailReply\Console\DiagnoseMailReplyCommand;
use Ramon\MailReply\Console\FetchInboundEmailCommand;
use Ramon\MailReply\Console\PruneInboundLogCommand;
use Ramon\MailReply\Console\ScheduleFetch;
use Ramon\MailReply\Console\SchedulePrune;
use Ramon\MailReply\Console\SimulateSendCommand;
use Ramon\MailReply\Database\MailReplyPost;
use Ramon\MailReply\Listener\EncryptSecretsOnSave;

return [
    (new Extend\Routes('api'))
        ->post('/mail-reply/inbound', 'mail-reply.inbound', InboundEmailController::class)
        ->post('/mail-reply/test-imap', 'mail-reply.test-imap', TestImapController::class)
        ->get('/mail-reply/status', 'mail-reply.status', StatusController::class),

    // O webhook é chamado por MTAs e scripts externos, sem sessão nem
    // token CSRF; a autenticação é feita no controller.
    (new Extend\Csrf())
        ->exemptRoute('mail-reply.inbound'),

    (new Extend\ServiceProvider())
        ->register(MailReplyServiceProvider::class),

    (new Extend\Console())
        ->command(FetchInboundEmailCommand::class)
        ->command(DiagnoseMailReplyCommand::class)
        ->command(SimulateSendCommand::class)
        ->command(PruneInboundLogCommand::class)
        ->schedule(FetchInboundEmailCommand::class, ScheduleFetch::class, ['--if-idle'])
        ->schedule(PruneInboundLogCommand::class, SchedulePrune::class),

    (new Extend\Event())
        ->listen(Saving::class, EncryptSecretsOnSave::class),

    (new Extend\Model(Post::class))
        ->hasOne('mailReplyMeta', MailReplyPost::class, 'post_id'),

    // Carrega `mailReplyMeta` em lote para o badge não custar uma query por post.
    (new Extend\ApiResource(PostResource::class))
        ->fields(ExposeViaMailReply::class)
        ->endpoint(
            [Endpoint\Index::class, Endpoint\Show::class, Endpoint\Create::class, Endpoint\Update::class],
            fn (Endpoint\Index|Endpoint\Show|Endpoint\Create|Endpoint\Update $endpoint) => $endpoint->eagerLoad(['mailReplyMeta'])
        ),

    (new Extend\ApiResource(DiscussionResource::class))
        ->endpoint(
            [Endpoint\Index::class, Endpoint\Show::class, Endpoint\Create::class],
            fn (Endpoint\Index|Endpoint\Show|Endpoint\Create $endpoint) => $endpoint->eagerLoadWhenIncluded([
                'firstPost' => ['firstPost.mailReplyMeta'],
                'lastPost' => ['lastPost.mailReplyMeta'],
                'mostRelevantPost' => ['mostRelevantPost.mailReplyMeta'],
            ])
        ),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    // `delivery_mode`: 'imap', 'webhook' ou 'disabled'. Só um modo fica ativo
    // por vez, para a mesma resposta não ser entregue duas vezes.
    (new Extend\Settings())
        ->serializeToForum('mailReply.showBadge', 'ramon-mail-reply.show_badge', fn ($value) => (bool) ($value ?? true))
        ->default('ramon-mail-reply.delivery_mode', 'imap')
        ->default('ramon-mail-reply.show_badge', true)
        ->default('ramon-mail-reply.imap_port', 993)
        ->default('ramon-mail-reply.imap_encryption', 'ssl')
        ->default('ramon-mail-reply.imap_mailbox', 'INBOX')
        ->default('ramon-mail-reply.imap_delete_after', true)
        ->default('ramon-mail-reply.imap_validate_cert', true)
        ->default('ramon-mail-reply.require_sender_match', true)
        ->default('ramon-mail-reply.max_replies_per_hour', 20)
        ->default('ramon-mail-reply.send_rejection_notices', true),

    (new Extend\Locales(__DIR__.'/locale')),
];
