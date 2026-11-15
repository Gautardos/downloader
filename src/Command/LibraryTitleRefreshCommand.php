<?php

namespace App\Command;

use App\Service\Automation\LibraryTitleRefreshService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:library-title-refresh',
    description: 'Refresh movie and series titles from TMDB in the configured title language.',
)]
class LibraryTitleRefreshCommand extends Command
{
    private LibraryTitleRefreshService $service;

    public function __construct(LibraryTitleRefreshService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of TMDB items to refresh.');
        $this->addOption('language', null, InputOption::VALUE_REQUIRED, 'TMDB language for titles (default: en-US).');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview title changes without updating the library.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $input->getOption('limit');
        $language = $input->getOption('language');
        $result = $this->service->refresh(
            is_numeric($limit) ? (int) $limit : null,
            is_string($language) && $language !== '' ? $language : null,
            null,
            !$input->getOption('dry-run')
        );
        $state = $result['state'] ?? [];
        if (!empty($result['success'])) {
            $output->writeln(sprintf(
                'Title refresh finished%s: %d item(s) processed, %d entr%s updated, %d skipped.',
                !empty($state['dry_run']) ? ' (dry-run)' : '',
                (int) ($state['processed'] ?? 0),
                (int) ($state['updated'] ?? 0),
                (int) ($state['updated'] ?? 0) === 1 ? 'y' : 'ies',
                (int) ($state['skipped'] ?? 0)
            ));

            return Command::SUCCESS;
        }

        $output->writeln('<error>' . ($result['message'] ?? 'Title refresh failed.') . '</error>');

        return Command::FAILURE;
    }
}
