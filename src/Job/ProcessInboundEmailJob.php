<?php

namespace Ramon\MailReply\Job;

use Flarum\Foundation\ErrorHandling\LogReporter;
use Flarum\Queue\AbstractJob;
use Psr\Log\LoggerInterface;
use Ramon\MailReply\Inbound\InboundProcessor;
use Ramon\MailReply\Inbound\InboundRejected;
use Throwable;

/**
 * Processa um payload de webhook fora da janela de resposta do provedor.
 * Em fila real o controller responde na hora; no driver `sync` roda
 * inline. Recusas definitivas viram log `info`; falhas inesperadas são
 * reportadas e, em fila real, re-tentadas uma vez.
 */
class ProcessInboundEmailJob extends AbstractJob
{
    public int $tries = 2;
    public int $timeout = 120;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public array $payload,
    ) {
    }

    public function handle(InboundProcessor $processor, LogReporter $log, LoggerInterface $logger): void
    {
        try {
            $post = $processor->process($this->payload);

            $logger->info('[mail-reply] inbound reply posted', [
                'post_id' => (int) $post->id,
                'discussion_id' => (int) $post->discussion_id,
            ]);
        } catch (InboundRejected $e) {
            if (! $e->silent) {
                $logger->info('[mail-reply] inbound message rejected', ['reason' => $e->reason]);
            }
        } catch (Throwable $e) {
            $log->report($e);

            if ($this->job !== null && $this->attempts() < $this->tries) {
                $this->release(30);
            }
        }
    }
}
