<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webgriffe\SyliusBackInStockNotificationPlugin\Restock\RestockProcessor;

#[AsCommand(
    name: 'webgriffe:back-in-stock-notification:alert',
    description: 'Notify pre-orders of restocked products, pass on expired holds and move sold-out ones back to waiting',
)]
final class AlertCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private RestockProcessor $restockProcessor,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Run it from cron every 5 minutes. For every product (or variant) with active pre-orders it expires lapsed holds, moves notified pre-orders of a sold-out target back to waiting and notifies waiting ones while there are free units, by the channel\'s restock mode (fastest or queue). Overlapping runs exit at once.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        try {
            $report = $this->restockProcessor->process(new \DateTimeImmutable());
        } finally {
            $this->release();
        }

        $output->writeln(sprintf('Notified: %d (with a hold: %d)', $report->getNotified(), $report->getHeld()));
        $output->writeln(sprintf('Expired holds: %d', $report->getExpired()));
        $output->writeln(sprintf('Back to waiting: %d', $report->getRewaited()));
        $output->writeln(sprintf('Failed targets: %d', $report->getFailedTargets()));

        if ($report->isAborted()) {
            $output->writeln('<error>Saving failed, the run stopped early (see the log).</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
