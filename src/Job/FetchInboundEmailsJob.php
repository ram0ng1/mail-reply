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
 *
 * O próximo tick é enfileirado antes do fetch: se o worker matar o
 * processo por timeout, a cadeia continua viva. Por isso `$tries = 1` —
 * um retry do mesmo tick criaria uma segunda cadeia com o mesmo id.
 */
class FetchInboundEmailsJob extends AbstractJob
{
    public const POLL_INTERVAL_SECONDS = 60;

    public int $tries = 1;

    /**
     * Abaixo do `retry_after` padrão (60s no fof/redis) para o job nunca
     * ser re-entregue enquanto ainda roda; o ciclo do ImapFetcher cabe aqui.
     */
    public int $timeout = 55;

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

        $bus->dispatch(
            (new self($this->loopId))->delay(Carbon::now()->addSeconds(self::POLL_INTERVAL_SECONDS)),
        );

        if ($fetcher->isConfigured()) {
            try {
                $fetcher->fetch();
            } catch (Throwable $e) {
                $logger->warning('[mail-reply] fetch loop iteration failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
