<?php

namespace Ramon\MailReply\Job;

use Carbon\Carbon;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Psr\Log\LoggerInterface;
use Ramon\MailReply\Inbound\FetchLoop;
use Ramon\MailReply\Inbound\ImapFetcher;
use Throwable;

/**
 * Ciclo auto-re-enfileirável do IMAP fetch dentro do `queue:work`, sem
 * cron. Cada cadeia carrega um `loopId`; só a cadeia cujo id bate com o
 * registrado em cache se re-enfileira, então um bootstrap concorrente
 * nunca multiplica cadeias. Com o driver `sync` o delay é ignorado pelo
 * Laravel — o job não se re-enfileira nesse caso (o agendador cobre).
 */
class FetchInboundEmailsJob extends AbstractJob
{
    public const POLL_INTERVAL_SECONDS = 60;

    public function __construct(
        public string $loopId = '',
    ) {
    }

    public function handle(
        SettingsRepositoryInterface $settings,
        ImapFetcher $fetcher,
        FetchLoop $loop,
        Dispatcher $bus,
        LoggerInterface $logger,
    ): void {
        if ((string) $settings->get('ramon-mail-reply.delivery_mode') !== 'imap') {
            $loop->stop();
            $logger->info('[mail-reply] fetch loop stopping: delivery mode is no longer imap');

            return;
        }

        if (! $loop->owns($this->loopId) || $loop->queueIsSync()) {
            return;
        }

        $loop->beat();

        if ($fetcher->isConfigured()) {
            try {
                $fetcher->fetch();
            } catch (Throwable $e) {
                $logger->warning('[mail-reply] fetch loop iteration failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $bus->dispatch(
            (new self($this->loopId))->delay(Carbon::now()->addSeconds(self::POLL_INTERVAL_SECONDS)),
        );
    }
}
