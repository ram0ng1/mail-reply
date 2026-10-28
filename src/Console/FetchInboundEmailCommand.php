<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\MailReply\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Ramon\MailReply\Inbound\FetchLoop;
use Ramon\MailReply\Inbound\ImapFetcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Lê a caixa de respostas via IMAP. Com fila real o `FetchInboundEmailsJob`
 * já faz isso; o comando serve ao agendador, a diagnósticos e a quem
 * prefere rodá-lo sob um supervisor.
 *
 *     php flarum mail-reply:fetch --limit=10
 *     php flarum mail-reply:fetch --loop --interval=60
 */
#[AsCommand(name: 'mail-reply:fetch', description: 'Fetch unseen replies from the configured IMAP inbox and post them to Flarum.')]
class FetchInboundEmailCommand extends AbstractCommand
{
    public function __construct(
        protected ImapFetcher $fetcher,
        protected SettingsRepositoryInterface $settings,
        protected FetchLoop $loop,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of messages to fetch per run.', '50');
        $this->addOption('verbose-status', null, InputOption::VALUE_NONE, 'Always print a status line (default: silent when IMAP not configured, useful for cron).');
        $this->addOption('loop', null, InputOption::VALUE_NONE, 'Run in a polling loop instead of returning after one fetch. Use with --interval.');
        $this->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds to wait between iterations when --loop is set.', '60');
        $this->addOption('if-idle', null, InputOption::VALUE_NONE, 'Skip when the queue-driven fetch loop is alive (used by the scheduler).');
    }

    /**
     * Para rodar sob systemd/supervisord quando não há cron. Uma falha num
     * ciclo não derruba o loop.
     */
    protected function fire(): int
    {
        [$input, $output] = [$this->input, $this->output];

        if ($input->getOption('if-idle') && $this->loop->isAlive()) {
            return Command::SUCCESS;
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $verbose = (bool) $input->getOption('verbose-status');
        $loop = (bool) $input->getOption('loop');
        $interval = max(5, (int) $input->getOption('interval'));

        if (! $loop) {
            return $this->runOnce($output, $limit, $verbose);
        }

        $output->writeln(sprintf('<info>mail-reply:fetch — loop mode, interval=%ds</info>', $interval));

        do {
            try {
                $this->runOnce($output, $limit, $verbose);
            } catch (Throwable $e) {
                $output->writeln('<error>loop iteration failed: '.$e->getMessage().'</error>');
            }

            sleep($interval);
        } while ($this->keepLooping());

        return Command::SUCCESS;
    }

    /**
     * O `--loop` só termina quando o supervisor mata o processo.
     */
    protected function keepLooping(): bool
    {
        return ! connection_aborted();
    }

    /**
     * Fora do modo IMAP, ou sem IMAP configurado, sai em silêncio e com
     * sucesso: senão o cron mandaria e-mail ao operador a cada execução.
     */
    protected function runOnce(OutputInterface $output, int $limit, bool $verbose): int
    {
        $mode = (string) $this->settings->get('ramon-mail-reply.delivery_mode');

        if ($mode !== 'imap') {
            if ($verbose) {
                $output->writeln(sprintf('<comment>mail-reply:fetch — delivery_mode=\'%s\' (skipping, not in IMAP mode)</comment>', $mode));
            }
            return Command::SUCCESS;
        }

        if (! ImapFetcher::configuredIn($this->settings)) {
            if ($verbose) {
                $output->writeln('<comment>mail-reply:fetch — IMAP not configured (skipping silently)</comment>');
            }
            return Command::SUCCESS;
        }

        try {
            $result = $this->fetcher->fetch($limit);
        } catch (Throwable $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        if ($result['locked']) {
            if ($verbose) {
                $output->writeln('<comment>mail-reply:fetch — another fetch is running (skipping)</comment>');
            }

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '[%s] mail-reply:fetch — processed=%d failed=%d skipped=%d',
            date('H:i:s'),
            $result['processed'],
            $result['failed'],
            $result['skipped'],
        ));

        return Command::SUCCESS;
    }
}
