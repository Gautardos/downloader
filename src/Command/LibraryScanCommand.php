<?php

namespace App\Command;

use App\Service\Automation\LibraryIndexService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:library-scan',
    description: 'Scan the configured NAS library roots and update the automation index.',
)]
class LibraryScanCommand extends Command
{
    private LibraryIndexService $libraryIndex;

    public function __construct(LibraryIndexService $libraryIndex)
    {
        parent::__construct();
        $this->libraryIndex = $libraryIndex;
    }

    protected function configure(): void
    {
        $this->addOption('root', null, InputOption::VALUE_REQUIRED, 'Scan only this root key (movies, series, anime).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $input->getOption('root');
        $rootKeys = $root ? [(string) $root] : null;

        try {
            $result = $this->libraryIndex->scan($rootKeys);
        } catch (\Throwable $e) {
            $output->writeln('<error>Library scan failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (empty($result['success'])) {
            $output->writeln('<comment>' . ($result['message'] ?? 'Library scan did not run.') . '</comment>');
            return Command::SUCCESS;
        }

        $stats = $result['stats'] ?? [];
        $output->writeln('Library scan #' . ($result['run_id'] ?? '?') . ' complete.');
        $output->writeln('Files seen: ' . ($stats['files_seen'] ?? 0));
        $output->writeln('Inserted: ' . ($stats['files_indexed'] ?? 0));
        $output->writeln('Updated: ' . ($stats['files_updated'] ?? 0));
        $output->writeln('Missing: ' . ($stats['files_missing'] ?? 0));
        $output->writeln('Skipped: ' . ($stats['files_skipped'] ?? 0));
        $output->writeln('Duration: ' . ($stats['duration'] ?? 0) . 's');

        if (!empty($stats['errors'])) {
            $output->writeln('<comment>Warnings:</comment>');
            foreach (array_slice($stats['errors'], 0, 20) as $error) {
                $output->writeln(' - ' . $error);
            }
        }

        return Command::SUCCESS;
    }
}
