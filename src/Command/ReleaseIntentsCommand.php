<?php

namespace App\Command;

use App\Service\Automation\ReleaseIntentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:release-intents',
    description: 'Queues missing movie intents and resolves release candidates.',
)]
class ReleaseIntentsCommand extends Command
{
    private ReleaseIntentService $intents;

    public function __construct(ReleaseIntentService $intents)
    {
        parent::__construct();
        $this->intents = $intents;
    }

    protected function configure(): void
    {
        $this->addOption('queue', null, InputOption::VALUE_NONE, 'Create intents from missing movie dry-run items.');
        $this->addOption('run', null, InputOption::VALUE_NONE, 'Process due intents.');
        $this->addOption('auto', null, InputOption::VALUE_NONE, 'Queue only sources with auto-download enabled.');
        $this->addOption('max', null, InputOption::VALUE_REQUIRED, 'Maximum intents to process.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queue = (bool) $input->getOption('queue');
        $run = (bool) $input->getOption('run');
        if (!$queue && !$run) {
            $queue = true;
            $run = true;
        }

        if ($queue) {
            $result = $this->intents->queueMissingMovies((bool) $input->getOption('auto'));
            $output->writeln((string) ($result['message'] ?? 'Intent queueing finished.'));
        }

        if ($run) {
            $max = $input->getOption('max');
            $result = $this->intents->processDueIntents(is_numeric($max) ? (int) $max : null);
            $stats = $result['stats'] ?? [];
            $output->writeln(sprintf(
                '%s seen=%d queued=%d no_source=%d error=%d queue_depth=%d',
                !empty($result['success']) ? 'Intent run finished.' : 'Intent run failed: ' . ($result['message'] ?? ''),
                $stats['intents_seen'] ?? 0,
                $stats['intents_queued'] ?? 0,
                $stats['intents_no_source'] ?? 0,
                $stats['intents_error'] ?? 0,
                $stats['queue_depth'] ?? 0
            ));
        }

        return Command::SUCCESS;
    }
}
