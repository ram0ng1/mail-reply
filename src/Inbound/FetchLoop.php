<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\MailReply\Inbound;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\SyncQueue;
use Ramon\MailReply\Job\FetchInboundEmailsJob;
use Ramon\MailReply\Support\CacheLock;

/**
 * Estado do loop de polling IMAP em fila real: heartbeat (TTL 180s) e o
 * id da cadeia ativa. `start()` registra um id novo antes de despachar,
 * o que aposenta qualquer cadeia antiga no próximo tick dela.
 */
class FetchLoop
{
    public const HEARTBEAT_KEY = 'mail-reply.loop_heartbeat';
    public const LOOP_ID_KEY = 'mail-reply.loop_id';
    public const HEARTBEAT_TTL = 180;
    public const BOOTSTRAP_KEY = 'mail-reply.loop_bootstrapped';
    private const BOOTSTRAP_LOCK_KEY = 'mail-reply.bootstrap_lock';

    public function __construct(
        protected Repository $cache,
        protected Container $container,
    ) {
    }

    /**
     * @phpstan-impure
     */
    public function isAlive(): bool
    {
        return $this->cache->has(self::HEARTBEAT_KEY);
    }

    public function owns(string $loopId): bool
    {
        return $loopId !== '' && $this->cache->get(self::LOOP_ID_KEY) === $loopId;
    }

    public function beat(): void
    {
        $this->cache->put(self::HEARTBEAT_KEY, time(), self::HEARTBEAT_TTL);
    }

    public function stop(): void
    {
        $this->cache->forget(self::HEARTBEAT_KEY);
        $this->cache->forget(self::LOOP_ID_KEY);
        $this->cache->forget(self::BOOTSTRAP_KEY);
    }

    /**
     * O `SyncQueue` executa `later()` na hora: um job que se re-enfileira
     * viraria recursão infinita dentro do request. Extensões de fila
     * (Redis, database) trocam o binding `flarum.queue.connection`.
     */
    public function queueIsSync(): bool
    {
        return $this->container->make('flarum.queue.connection') instanceof SyncQueue;
    }

    /**
     * Dispara uma cadeia nova quando nenhuma está viva e devolve `true` se
     * despachou. Só o job escreve o heartbeat; o bootstrap usa outra chave,
     * senão uma fila sem worker pareceria viva e o `--if-idle` nunca rodaria.
     * Lock com nova checagem garante um único bootstrap por vez.
     */
    public function startIfStale(Dispatcher $bus): bool
    {
        if ($this->isAlive() || $this->queueIsSync() || $this->cache->has(self::BOOTSTRAP_KEY)) {
            return false;
        }

        $lock = CacheLock::make($this->cache, self::BOOTSTRAP_LOCK_KEY, 30);

        if (! $lock->get()) {
            return false;
        }

        try {
            if ($this->isAlive() || $this->cache->has(self::BOOTSTRAP_KEY)) {
                return false;
            }

            $loopId = bin2hex(random_bytes(8));
            $this->cache->forever(self::LOOP_ID_KEY, $loopId);
            $this->cache->put(self::BOOTSTRAP_KEY, time(), self::HEARTBEAT_TTL);

            $bus->dispatch(new FetchInboundEmailsJob($loopId));

            return true;
        } finally {
            $lock->release();
        }
    }
}
