<?php

namespace Ramon\MailReply\Console;

use Carbon\Carbon;
use Ramon\MailReply\Database\InboundEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Remove registros antigos de `mail_reply_inbound`. Depois de 90 dias
 * nenhum provedor re-entrega a mensagem, então o hash não serve mais
 * para dedupe. Agendado diariamente.
 */
#[AsCommand(name: 'mail-reply:prune', description: 'Delete inbound email log entries older than the retention window.')]
class PruneInboundLogCommand extends Command
{
    public const DEFAULT_DAYS = 90;

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retention window in days.', (string) self::DEFAULT_DAYS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = max(1, (int) $input->getOption('days'));

        $deleted = InboundEmail::query()
            ->where('created_at', '<', Carbon::now()->subDays($days))
            ->delete();

        $output->writeln(sprintf('mail-reply:prune — deleted %d entries older than %d days', $deleted, $days));

        return Command::SUCCESS;
    }
}
