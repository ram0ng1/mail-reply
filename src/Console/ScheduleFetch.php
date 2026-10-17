<?php

namespace Ramon\MailReply\Console;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Console\Scheduling\Event;
use Ramon\MailReply\Inbound\ImapFetcher;

/**
 * Agenda o polling IMAP a cada minuto para fóruns com fila `sync`, onde o
 * loop em fila não roda. O `when()` evita disparar o processo quando o
 * modo não é IMAP ou faltam credenciais; `--if-idle` cede a vez ao loop
 * em fila quando ele está vivo.
 */
class ScheduleFetch
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    public function __invoke(Event $event): void
    {
        $event->everyMinute()
            ->withoutOverlapping(5)
            ->when(fn () => (string) $this->settings->get('ramon-mail-reply.delivery_mode') === 'imap'
                && ImapFetcher::configuredIn($this->settings));
    }
}
