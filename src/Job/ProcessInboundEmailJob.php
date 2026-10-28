<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\MailReply\Job;

use Flarum\Queue\AbstractJob;
use Psr\Log\LoggerInterface;
use Ramon\MailReply\Inbound\InboundProcessor;
use Ramon\MailReply\Inbound\InboundRejected;

/**
 * Processa um payload de webhook fora da janela de resposta do provedor.
 * Recusas definitivas viram log `info`. Qualquer outra falha sobe: na fila
 * real o worker tenta de novo; no driver `sync` o controller responde 500 e
 * o MTA reentrega (o script de pipe sai com TEMPFAIL).
 */
class ProcessInboundEmailJob extends AbstractJob
{
    public int $tries = 2;

    public int $backoff = 30;

    /**
     * Abaixo do `retry_after` de 60s das filas (fof/redis, database): o job
     * nunca é reentregue enquanto ainda roda.
     */
    public int $timeout = 55;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public array $payload,
    ) {
    }

    public function handle(InboundProcessor $processor, LoggerInterface $logger): void
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
        }
    }
}
