<?php

namespace App\Command;

use App\Service\Automation\ReleaseIntentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:release-intents-reconcile',
    description: 'Deletes actionable intents that are no longer requested by any enabled list source.'
)]
class ReleaseIntentReconcileCommand extends Command
{
    private ReleaseIntentService $intents;

    public function __construct(ReleaseIntentService $intents)
    {
        parent::__construct();
        $this->intents = $intents;
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be deleted without deleting anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $result = $this->intents->reconcileFromLists($dryRun);
        $stats = $result['stats'] ?? [];

        if (empty($result['success'])) {
            if (!empty($result['skipped'])) {
                $output->writeln('<comment>' . ($result['message'] ?? 'Intent reconciliation skipped.') . '</comment>');

                return Command::SUCCESS;
            }

            $output->writeln('<error>' . ($result['message'] ?? 'Intent reconciliation failed.') . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Enabled sources: %d · desired keys: %d · intents seen: %d',
            $stats['enabled_sources'] ?? 0,
            $stats['desired_keys'] ?? 0,
            $stats['intents_seen'] ?? 0
        ));
        $output->writeln(sprintf(
            'Kept desired: %d · protected: %d · skipped: %d',
            $stats['kept_desired'] ?? 0,
            $stats['kept_protected'] ?? 0,
            $stats['skipped'] ?? 0
        ));
        $output->writeln($dryRun
            ? 'Would delete: ' . (int) ($stats['would_delete'] ?? 0)
            : 'Deleted: ' . (int) ($stats['deleted'] ?? 0));

        foreach (array_slice($result['candidates'] ?? [], 0, 20) as $candidate) {
            $coordinates = '';
            if ($candidate['season'] !== null && $candidate['episode'] !== null) {
                $coordinates = sprintf(' S%02dE%02d', (int) $candidate['season'], (int) $candidate['episode']);
            }
            $output->writeln(sprintf(
                ' - #%d [%s] %s%s (%s)',
                (int) $candidate['id'],
                (string) $candidate['status'],
                (string) $candidate['title'],
                $coordinates,
                (string) $candidate['media_key']
            ));
        }
        if (count($result['candidates'] ?? []) > 20) {
            $output->writeln(' - … ' . (count($result['candidates']) - 20) . ' more');
        }
        foreach (array_slice($stats['warnings'] ?? [], 0, 10) as $warning) {
            $output->writeln('<comment>' . $warning . '</comment>');
        }

        return Command::SUCCESS;
    }
}
