<?php

declare(strict_types=1);

namespace Ramon\MailReply\Tests\Unit\Inbound;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\SyncQueue;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Ramon\MailReply\Inbound\FetchLoop;
use Ramon\MailReply\Job\FetchInboundEmailsJob;

final class FetchLoopTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private Repository $cache;
    private Container $container;

    protected function setUp(): void
    {
        $this->cache = new Repository(new ArrayStore());
        $this->container = new Container();
    }

    private function loop(): FetchLoop
    {
        return new FetchLoop($this->cache, $this->container);
    }

    private function bindQueue(object $queue): void
    {
        $this->container->instance('flarum.queue.connection', $queue);
    }

    public function test_owns_only_the_registered_loop_id(): void
    {
        $loop = $this->loop();

        self::assertFalse($loop->owns('abc'));

        $this->cache->forever(FetchLoop::LOOP_ID_KEY, 'abc');

        self::assertTrue($loop->owns('abc'));
        self::assertFalse($loop->owns('def'));
        self::assertFalse($loop->owns(''));
    }

    public function test_empty_loop_id_never_owns_even_when_cache_holds_empty_string(): void
    {
        $this->cache->forever(FetchLoop::LOOP_ID_KEY, '');

        self::assertFalse($this->loop()->owns(''));
    }

    public function test_queue_is_sync_detects_the_sync_connection(): void
    {
        $this->bindQueue(new SyncQueue());

        self::assertTrue($this->loop()->queueIsSync());
    }

    public function test_queue_is_sync_is_false_for_real_queue_drivers(): void
    {
        $this->bindQueue(Mockery::mock(Queue::class));

        self::assertFalse($this->loop()->queueIsSync());
    }

    public function test_beat_and_stop_toggle_liveness(): void
    {
        $loop = $this->loop();
        $this->cache->forever(FetchLoop::LOOP_ID_KEY, 'abc');

        self::assertFalse($loop->isAlive());

        $loop->beat();
        self::assertTrue($loop->isAlive());

        $loop->stop();
        self::assertFalse($loop->isAlive());
        self::assertFalse($loop->owns('abc'));
    }

    public function test_start_if_stale_never_dispatches_on_sync_queue(): void
    {
        $this->bindQueue(new SyncQueue());
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldNotReceive('dispatch');

        self::assertFalse($this->loop()->startIfStale($bus));
        self::assertFalse($this->loop()->isAlive());
    }

    public function test_start_if_stale_dispatches_one_chain_owned_by_the_new_loop_id(): void
    {
        $this->bindQueue(Mockery::mock(Queue::class));
        $dispatched = [];
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->once()->andReturnUsing(function ($job) use (&$dispatched) {
            $dispatched[] = $job;

            return null;
        });

        $loop = $this->loop();

        self::assertTrue($loop->startIfStale($bus));
        self::assertFalse($loop->startIfStale($bus));

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(FetchInboundEmailsJob::class, $dispatched[0]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $dispatched[0]->loopId);
        self::assertTrue($loop->owns($dispatched[0]->loopId));
        self::assertTrue($loop->isAlive());
    }

    public function test_start_if_stale_is_a_no_op_while_heartbeat_is_alive(): void
    {
        $this->bindQueue(Mockery::mock(Queue::class));
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldNotReceive('dispatch');

        $loop = $this->loop();
        $loop->beat();

        self::assertFalse($loop->startIfStale($bus));
    }
}
