<?php

namespace App\Command;

use App\Service\Automation\AutomationStore;
use App\Service\Automation\LibraryRenameService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:library-rename-undo',
    description: 'Previews or reverts the file moves of a bulk rename run.',
)]
class LibraryRenameUndoCommand extends Command
{
    private AutomationStore $store;
    private LibraryRenameService $renameService;

    public function __construct(AutomationStore $store, LibraryRenameService $renameService)
    {
        parent::__construct();
        $this->store = $store;
        $this->renameService = $renameService;
    }

    protected function configure(): void
    {
        $this->addOption('run', null, InputOption::VALUE_REQUIRED, 'Rename run ID to undo (default: last run with renames).');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Actually move files back. Without this flag, only preview.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runId = $input->getOption('run');
        if (!is_numeric($runId) || (int) $runId <= 0) {
            $runId = null;
            foreach ($this->store->getRecentRenameRuns(20) as $run) {
                if ((int) ($run['entries_renamed'] ?? 0) > 0) {
                    $runId = (int) $run['id'];
                    break;
                }
            }
        }
        if ($runId === null) {
            $output->writeln('<comment>No rename run with file moves found.</comment>');
            return Command::SUCCESS;
        }

        $result = $this->renameService->undo((int) $runId, (bool) $input->getOption('apply'));
        if (empty($result['success'])) {
            $output->writeln('<error>' . ($result['message'] ?? 'Undo failed.') . '</error>');
            return Command::FAILURE;
        }

        $stats = $result['stats'] ?? [];
        $output->writeln(($input->getOption('apply') ? 'Undo applied' : 'Undo preview') . ' for rename run #' . $runId);
        $output->writeln('Seen: ' . ($stats['entries_seen'] ?? 0));
        $output->writeln('Reverted: ' . ($stats['entries_reverted'] ?? 0));
        $output->writeln('Skipped: ' . ($stats['entries_skipped'] ?? 0));
        foreach (($stats['details'] ?? []) as $detail) {
            if (($detail['status'] ?? '') === 'skip') {
                continue;
            }
            $output->writeln(sprintf(
                ' - [%s] %s -> %s%s',
                (string) ($detail['status'] ?? ''),
                (string) ($detail['from'] ?? ''),
                (string) ($detail['to'] ?? ''),
                !empty($detail['error']) ? ' (' . $detail['error'] . ')' : ''
            ));
        }

        return Command::SUCCESS;
    }
}
