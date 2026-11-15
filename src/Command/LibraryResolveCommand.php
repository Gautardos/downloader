<?php

namespace App\Command;

use App\Service\Automation\LibraryMetadataResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:library-resolve',
    description: 'Resolve library entries to TMDB and update their canonical media keys.',
)]
class LibraryResolveCommand extends Command
{
    private LibraryMetadataResolver $resolver;

    public function __construct(LibraryMetadataResolver $resolver)
    {
        parent::__construct();
        $this->resolver = $resolver;
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of unresolved groups to process.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $input->getOption('limit');
        $limit = is_numeric($limit) ? max(1, (int) $limit) : null;

        try {
            $result = $this->resolver->resolve($limit);
        } catch (\Throwable $e) {
            $output->writeln('<error>Metadata resolution failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (empty($result['success'])) {
            $output->writeln('<comment>' . ($result['message'] ?? 'Metadata resolution did not run.') . '</comment>');
            return Command::SUCCESS;
        }

        $stats = $result['stats'] ?? [];
        $output->writeln('Metadata resolution complete.');
        $output->writeln('Groups seen: ' . ($stats['groups_seen'] ?? 0));
        $output->writeln('Groups resolved: ' . ($stats['groups_resolved'] ?? 0));
        $output->writeln('Entries resolved: ' . ($stats['entries_resolved'] ?? 0));
        $output->writeln('Groups skipped: ' . ($stats['groups_skipped'] ?? 0));
        $output->writeln('TMDB lookups: ' . ($stats['lookups'] ?? 0));
        $output->writeln('Duration: ' . ($stats['duration'] ?? 0) . 's');

        if (!empty($stats['skipped_reasons'])) {
            $output->writeln('<comment>Skipped reasons:</comment>');
            foreach ($stats['skipped_reasons'] as $reason => $count) {
                $output->writeln(' - ' . $reason . ': ' . $count);
            }
        }

        return Command::SUCCESS;
    }
}
