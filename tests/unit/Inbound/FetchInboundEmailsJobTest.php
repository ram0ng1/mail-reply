<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Ramon\MailReply\Tests\unit\Inbound;

use Illuminate\Contracts\Bus\Dispatcher;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Ramon\MailReply\Inbound\FetchLoop;
use Ramon\MailReply\Inbound\ImapFetcher;
use Ramon\MailReply\Job\FetchInboundEmailsJob;
use Ramon\MailReply\Tests\Support\ArraySettings;
use Ramon\MailReply\Tests\Support\MemoryLogger;

final class FetchInboundEmailsJobTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_next_tick_is_queued_before_the_fetch_so_a_killed_worker_keeps_the_chain(): void
    {
        $order = [];

        $loop = Mockery::mock(FetchLoop::class);
        $loop->shouldReceive('owns')->with('loop-1')->andReturn(true);
        $loop->shouldReceive('queueIsSync')->andReturn(false);
        $loop->shouldReceive('beat')->once();

        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->once()->andReturnUsing(function (FetchInboundEmailsJob $next) use (&$order) {
            $order[] = 'dispatch:'.$next->loopId;
        });

        $fetcher = Mockery::mock(ImapFetcher::class);
        $fetcher->shouldReceive('isConfigured')->andReturn(true);
        $fetcher->shouldReceive('fetch')->once()->andReturnUsing(function () use (&$order) {
            $order[] = 'fetch';

            return ['processed' => 0, 'failed' => 0, 'skipped' => 0, 'locked' => false];
        });

        (new FetchInboundEmailsJob('loop-1'))->handle(
            new ArraySettings(['ramon-mail-reply.delivery_mode' => 'imap']),
            $fetcher,
            $loop,
            $bus,
            new MemoryLogger(),
        );

        self::assertSame(['dispatch:loop-1', 'fetch'], $order);
    }

    public function test_job_never_retries_and_times_out_before_queue_redelivery(): void
    {
        $job = new FetchInboundEmailsJob('loop-1');

        self::assertSame(1, $job->tries);
        self::assertLessThan(60, $job->timeout);
    }
}
