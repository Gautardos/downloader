<?php

namespace App\Command;

use App\Service\Automation\LibraryRenameService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:library-prune-empty-dirs',
    description: 'Removes empty folders left behind by library renames.'
)]
class LibraryPruneEmptyDirsCommand extends Command
{
    private LibraryRenameService $renameService;

    public function __construct(LibraryRenameService $renameService)
    {
        parent::__construct();
        $this->renameService = $renameService;
    }

    protected function configure(): void
    {
        $this->addOption('root', null, InputOption::VALUE_REQUIRED, 'Root key(s) to prune, comma separated (movies, series, anime). Defaults to all enabled roots.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List empty folders without deleting them.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rootOption = trim((string) $input->getOption('root'));
        $rootKeys = $rootOption !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $rootOption)), static fn (string $key): bool => $key !== ''))
            : null;
        $dryRun = (bool) $input->getOption('dry-run');
        $result = $this->renameService->pruneEmptyFolders($rootKeys, $dryRun);
        $stats = $result['stats'] ?? [];

        $output->writeln(($dryRun ? 'Empty folder preview' : 'Empty folder cleanup') . ' complete.');
        $output->writeln('Roots seen: ' . ($stats['roots_seen'] ?? 0));
        $output->writeln('Directories seen: ' . ($stats['dirs_seen'] ?? 0));
        $output->writeln('Candidates: ' . ($stats['candidates'] ?? 0));
        $output->writeln($dryRun ? 'Would delete: ' . ($stats['candidates'] ?? 0) : 'Deleted: ' . ($stats['deleted'] ?? 0));
        $output->writeln('Skipped: ' . ($stats['skipped'] ?? 0) . ' · failed: ' . ($stats['failed'] ?? 0));

        foreach (array_slice($result['items'] ?? [], 0, 20) as $item) {
            $output->writeln(' - [' . $item['root_key'] . '] ' . $item['path']);
        }
        if (count($result['items'] ?? []) > 20) {
            $output->writeln(' - … ' . (count($result['items']) - 20) . ' more');
        }
        foreach (array_slice($stats['warnings'] ?? [], 0, 10) as $warning) {
            $output->writeln('<comment>' . $warning . '</comment>');
        }

        return empty($result['success']) ? Command::FAILURE : Command::SUCCESS;
    }
}
