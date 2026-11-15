<?php

namespace App\Command;

use App\Service\Automation\ListSyncService;
use App\Service\Automation\ReleaseIntentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:lists-sync',
    description: 'Fetch Scrob/MDBList lists and run a dry-run match against the NAS index.',
)]
class ListsSyncCommand extends Command
{
    private ListSyncService $listSync;
    private ReleaseIntentService $releaseIntents;

    public function __construct(ListSyncService $listSync, ReleaseIntentService $releaseIntents)
    {
        parent::__construct();
        $this->listSync = $listSync;
        $this->releaseIntents = $releaseIntents;
    }

    protected function configure(): void
    {
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Sync only this source id (scrob, mdblist).');
        $this->addOption('scheduled', null, InputOption::VALUE_NONE, 'Only sync sources that are due.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = $input->getOption('source');
        $sourceIds = null;
        if (is_string($source) && $source !== '') {
            $sourceIds = [$source];
        } elseif ((bool) $input->getOption('scheduled')) {
            $sourceIds = $this->listSync->dueSourceIds();
            if (empty($sourceIds)) {
                $output->writeln('No list source is due.');

                return Command::SUCCESS;
            }
        }

        $result = $this->listSync->sync($sourceIds);
        if (empty($result['success'])) {
            $output->writeln('<error>' . ($result['message'] ?? 'List sync failed.') . '</error>');

            return Command::FAILURE;
        }

        if (isset($result['message'])) {
            $output->writeln((string) $result['message']);
        }
        $stats = $result['stats'] ?? [];
        $output->writeln(sprintf(
            'Dry-run finished: %d present, %d missing, %d ambiguous, %d ignored.',
            $stats['present'] ?? 0,
            $stats['missing'] ?? 0,
            $stats['ambiguous'] ?? 0,
            $stats['ignored'] ?? 0
        ));

        if ((int) ($stats['sources'] ?? 0) > 0) {
            $reconcile = $this->releaseIntents->reconcileFromLists(false);
            if (!empty($reconcile['success'])) {
                $reconcileStats = $reconcile['stats'] ?? [];
                $output->writeln('Intent reconciliation: ' . ($reconcileStats['deleted'] ?? 0) . ' deleted, ' . ($reconcileStats['kept_desired'] ?? 0) . ' desired kept, ' . ($reconcileStats['kept_protected'] ?? 0) . ' protected.');
            } elseif (!empty($reconcile['skipped'])) {
                $output->writeln('<comment>' . ($reconcile['message'] ?? 'Intent reconciliation skipped.') . '</comment>');
            } else {
                $output->writeln('<error>' . ($reconcile['message'] ?? 'Intent reconciliation failed.') . '</error>');
            }
        }

        return Command::SUCCESS;
    }
}
