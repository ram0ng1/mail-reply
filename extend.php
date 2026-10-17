<?php

namespace Ramon\MailReply;

use Flarum\Api\Endpoint;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\PostResource;
use Flarum\Extend;
use Flarum\Post\Post;
use Flarum\Settings\Event\Saving;
use Illuminate\Console\Scheduling\Event;
use Ramon\MailReply\Api\Controller\InboundEmailController;
use Ramon\MailReply\Api\Controller\StatusController;
use Ramon\MailReply\Api\Controller\TestImapController;
use Ramon\MailReply\Api\ExposeViaMailReply;
use Ramon\MailReply\Console\DiagnoseMailReplyCommand;
use Ramon\MailReply\Console\FetchInboundEmailCommand;
use Ramon\MailReply\Console\PruneInboundLogCommand;
use Ramon\MailReply\Console\ScheduleFetch;
use Ramon\MailReply\Console\SimulateSendCommand;
use Ramon\MailReply\Database\MailReplyPost;
use Ramon\MailReply\Listener\EncryptSecretsOnSave;

return [
    (new Extend\Routes('api'))
        ->post('/mail-reply/inbound', 'mail-reply.inbound', InboundEmailController::class)
        ->post('/mail-reply/test-imap', 'mail-reply.test-imap', TestImapController::class)
        ->get('/mail-reply/status', 'mail-reply.status', StatusController::class),

    /**
     * Webhook recebe POST de MTAs/scripts pipe externos sem cookie nem
     * CSRF token. A autenticação real é o header X-Mail-Reply-Token
     * verificado por hash_equals no controller.
     */
    (new Extend\Csrf())
        ->exemptRoute('mail-reply.inbound'),

    (new Extend\ServiceProvider())
        ->register(MailReplyServiceProvider::class),

    (new Extend\Console())
        ->command(FetchInboundEmailCommand::class)
        ->command(DiagnoseMailReplyCommand::class)
        ->command(SimulateSendCommand::class)
        ->command(PruneInboundLogCommand::class)
        ->schedule('mail-reply:fetch', ScheduleFetch::class, ['--if-idle'])
        ->schedule('mail-reply:prune', function (Event $event): void {
            $event->daily();
        }),

    (new Extend\Event())
        ->listen(Saving::class, EncryptSecretsOnSave::class),

    (new Extend\Model(Post::class))
        ->hasOne('mailReplyMeta', MailReplyPost::class, 'post_id'),

    /**
     * O badge lê `mailReplyMeta`; carregamos a relação em lote em todo
     * endpoint que serializa posts para evitar uma query por post.
     */
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

    /**
     * `delivery_mode` aceita 'imap' (poller dentro do queue:work), 'webhook'
     * (endpoint público + script PHP no MTA) ou 'disabled'. Apenas um ativo
     * por vez — o controller e o ServiceProvider checam o valor pra decidir
     * se aceitam POST ou se despacham o job de polling.
     */
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
