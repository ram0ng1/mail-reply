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

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Mail\MutateEmail;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Ramon\MailReply\Inbound\FetchLoop;
use Ramon\MailReply\Inbound\ImapFetcher;
use Ramon\MailReply\Mail\CombinedEmailMutator;
use Ramon\MailReply\Support\CacheLock;
use Throwable;

/**
 * Troca o listener `MutateEmail` do core pelo `CombinedEmailMutator`,
 * garante o `webhook_secret` no modo webhook e mantém o loop de polling
 * IMAP vivo quando há fila real. Com o driver `sync` o polling fica a
 * cargo do agendador (`mail-reply:fetch --if-idle` a cada minuto).
 */
class MailReplyServiceProvider extends AbstractServiceProvider
{
    private const WEBHOOK_SECRET_LOCK_KEY = 'mail-reply.webhook_secret_gen';

    public function register(): void
    {
        $this->container->bind(MutateEmail::class, CombinedEmailMutator::class);
    }

    /**
     * Roda em todo request: as checagens baratas (settings em memória)
     * vêm antes de qualquer acesso a cache ou fila.
     */
    public function boot(): void
    {
        try {
            /** @var SettingsRepositoryInterface $settings */
            $settings = $this->container->make(SettingsRepositoryInterface::class);
            $mode = (string) $settings->get('ramon-mail-reply.delivery_mode');

            if ($mode === 'webhook') {
                $this->ensureWebhookSecret($settings);
            } elseif ($mode === 'imap' && ImapFetcher::configuredIn($settings)) {
                $this->container->make(FetchLoop::class)
                    ->startIfStale($this->container->make(Dispatcher::class));
            }
        } catch (Throwable $e) {
            $this->warn('[mail-reply] boot bootstrap failed', $e);
        }
    }

    /**
     * Gera o segredo do webhook quando o admin ativou o modo sem
     * preenchê-lo — sem ele o endpoint recusaria tudo com 401. Lock
     * atômico + double-check contra workers concorrentes.
     */
    protected function ensureWebhookSecret(SettingsRepositoryInterface $settings): void
    {
        if ((string) $settings->get('ramon-mail-reply.webhook_secret') !== '') {
            return;
        }

        $lock = CacheLock::make($this->container->make(Repository::class), self::WEBHOOK_SECRET_LOCK_KEY, 5);

        if (! $lock->get()) {
            return;
        }

        try {
            // Direto do banco: a cópia em memória não vê o que outro
            // processo acabou de gravar.
            $stored = $this->container->make(ConnectionInterface::class)
                ->table('settings')->where('key', 'ramon-mail-reply.webhook_secret')->value('value');

            if ((string) $stored !== '') {
                return;
            }

            $settings->set('ramon-mail-reply.webhook_secret', bin2hex(random_bytes(32)));

            $this->container->make(LoggerInterface::class)
                ->warning('[mail-reply] webhook_secret was missing; auto-generated one — copy it from Admin → Mail Reply → Webhook into the MTA script.');
        } finally {
            $lock->release();
        }
    }

    protected function warn(string $message, Throwable $e): void
    {
        try {
            $this->container->make(LoggerInterface::class)->warning($message, ['error' => $e->getMessage()]);
        } catch (Throwable) {
        }
    }
}
