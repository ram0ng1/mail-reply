<?php

namespace Ramon\MailReply\Inbound;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\SyncQueue;
use Ramon\MailReply\Job\FetchInboundEmailsJob;

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
     * Dispara uma cadeia nova quando não há heartbeat. Lock atômico +
     * double-check garantem um único bootstrap entre requests/workers
     * concorrentes. Devolve `true` quando despachou.
     */
    public function startIfStale(Dispatcher $bus): bool
    {
        if ($this->isAlive() || $this->queueIsSync()) {
            return false;
        }

        $lock = $this->cache->lock(self::BOOTSTRAP_LOCK_KEY, 30);

        if (! $lock->get()) {
            return false;
        }

        try {
            if ($this->isAlive()) {
                return false;
            }

            $loopId = bin2hex(random_bytes(8));
            $this->cache->forever(self::LOOP_ID_KEY, $loopId);
            $this->beat();

            $bus->dispatch(new FetchInboundEmailsJob($loopId));

            return true;
        } finally {
            $lock->release();
        }
    }
}
