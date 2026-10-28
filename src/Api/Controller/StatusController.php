<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\MailReply\Api\Controller;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\MailReply\Database\InboundEmail;
use Ramon\MailReply\Inbound\FetchLoop;
use Ramon\MailReply\Inbound\ImapFetcher;
use Ramon\MailReply\Mail\ReplyAddressConfig;
use Ramon\MailReply\Service\SecretBox;
use Throwable;
use Webklex\PHPIMAP\ClientManager;

/**
 * Painel de saúde do admin: modo ativo, o que falta configurar, estado
 * do polling e as últimas mensagens recebidas. Somente administradores;
 * nunca devolve segredos, só se estão preenchidos.
 */
class StatusController implements RequestHandlerInterface
{
    private const RECENT_LIMIT = 15;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ReplyAddressConfig $replyAddress,
        protected FetchLoop $loop,
        protected Repository $cache,
        protected SecretBox $secretBox,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $filled = fn (string $key): bool => trim((string) $this->settings->get('ramon-mail-reply.'.$key)) !== '';

        return new JsonResponse([
            'mode' => (string) ($this->settings->get('ramon-mail-reply.delivery_mode') ?: 'imap'),
            'configured' => [
                'reply_address' => $this->replyAddress->parts() !== null,
                'imap' => ImapFetcher::configuredIn($this->settings),
                'imap_library' => class_exists(ClientManager::class),
                'webhook_secret' => $filled('webhook_secret'),
                'mailgun_signing_key' => $filled('mailgun_signing_key'),
                'secrets_readable' => $this->secretsReadable(),
            ],
            'polling' => [
                'queue' => $this->loop->queueIsSync() ? 'sync' : 'async',
                'loop_alive' => $this->loop->isAlive(),
                'last_fetch' => $this->cache->get(ImapFetcher::LAST_FETCH_KEY),
            ],
            'stats' => [
                'day' => $this->countsSince(Carbon::now()->subDay()),
                'week' => $this->countsSince(Carbon::now()->subWeek()),
            ],
            'recent' => $this->recent(),
        ]);
    }

    /**
     * Falso quando um segredo salvo não abre mais (arquivo de chave perdido,
     * ou valor antigo depois de mudar a URL): o admin precisa digitá-lo de
     * novo.
     */
    protected function secretsReadable(): bool
    {
        foreach (['secret', 'imap_password', 'mailgun_signing_key'] as $key) {
            try {
                $this->secretBox->decode((string) $this->settings->get('ramon-mail-reply.'.$key));
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, int>
     */
    protected function countsSince(Carbon $since): array
    {
        $counts = InboundEmail::query()
            ->where('created_at', '>=', $since)
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status');

        return [
            InboundEmail::STATUS_PROCESSED => (int) ($counts[InboundEmail::STATUS_PROCESSED] ?? 0),
            InboundEmail::STATUS_REJECTED => (int) ($counts[InboundEmail::STATUS_REJECTED] ?? 0),
            InboundEmail::STATUS_IGNORED => (int) ($counts[InboundEmail::STATUS_IGNORED] ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function recent(): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, InboundEmail> $rows */
        $rows = InboundEmail::query()
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get();

        $users = User::query()->whereIn('id', $rows->pluck('user_id')->filter()->unique())->get(['id', 'username'])->keyBy('id');
        $discussions = Discussion::query()->whereIn('id', $rows->pluck('discussion_id')->filter()->unique())->get(['id', 'title', 'slug'])->keyBy('id');
        $posts = Post::query()->whereIn('id', $rows->pluck('post_id')->filter()->unique())->get(['id', 'number'])->keyBy('id');

        return $rows->map(fn (InboundEmail $row) => [
            'id' => $row->id,
            'status' => $row->status,
            'reason' => $row->reason,
            'createdAt' => $row->created_at->toIso8601String(),
            'user' => ($u = $users->get($row->user_id)) ? ['id' => $u->id, 'username' => $u->username] : null,
            'discussion' => ($d = $discussions->get($row->discussion_id)) ? ['id' => $d->id, 'title' => $d->title, 'slug' => $d->slug] : null,
            'postNumber' => $posts->get($row->post_id)?->number,
        ])->values()->all();
    }
}
