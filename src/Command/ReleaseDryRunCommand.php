<?php

namespace App\Command;

use App\Service\Automation\ReleaseDryRunService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:release-dry-run',
    description: 'Resolves missing movies and previews release candidates without downloading.',
)]
class ReleaseDryRunCommand extends Command
{
    private ReleaseDryRunService $dryRun;

    public function __construct(ReleaseDryRunService $dryRun)
    {
        parent::__construct();
        $this->dryRun = $dryRun;
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum missing movies to preview.');
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Only preview one source id.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $input->getOption('limit');
        $source = $input->getOption('source');
        $result = $this->dryRun->run(
            is_numeric($limit) ? (int) $limit : null,
            is_string($source) && $source !== '' ? $source : null
        );
        $state = $result['state'] ?? [];
        if (!empty($result['success'])) {
            $output->writeln(sprintf(
                'Release dry-run finished: %d item(s), %d with a selected release, %d without source.',
                (int) ($state['total'] ?? 0),
                (int) ($state['ok'] ?? 0),
                (int) ($state['no_source'] ?? 0)
            ));

            return Command::SUCCESS;
        }

        $output->writeln('<error>' . ($result['message'] ?? 'Release dry-run failed.') . '</error>');

        return Command::FAILURE;
    }
}
