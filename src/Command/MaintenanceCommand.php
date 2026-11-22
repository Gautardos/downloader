<?php

namespace App\Command;

use App\Service\Maintenance\MaintenanceRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:maintenance',
    description: 'Runs scheduled maintenance jobs such as the torrent database update and housekeeping.',
)]
class MaintenanceCommand extends Command
{
    private MaintenanceRunner $runner;

    public function __construct(MaintenanceRunner $runner)
    {
        parent::__construct();
        $this->runner = $runner;
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Run jobs even when the schedule is not due.');
        $this->addOption('job', null, InputOption::VALUE_REQUIRED, 'Run only this job key (torrent_db, log_rotation, integrity).');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show which jobs would run without executing them.');
        $this->addOption('loop', null, InputOption::VALUE_NONE, 'Keep running and wait for the next scheduled maintenance window.');
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Run a single cycle and exit (cron/tests).');
        $this->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds to wait between loop cycles.', '60');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        $loop = (bool) $input->getOption('loop') && !(bool) $input->getOption('once');
        $interval = max(10, (int) $input->getOption('interval'));
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');
        $job = $input->getOption('job');
        $job = is_string($job) && $job !== '' ? $job : null;

        do {
            $result = $this->runner->run([
                'force' => $force,
                'job' => $job,
                'dry_run' => $dryRun,
                'trigger' => $force ? 'manual' : 'schedule',
            ]);

            if (!empty($result['locked'])) {
                $output->writeln('<comment>' . ($result['message'] ?? 'Maintenance is already running.') . '</comment>');
            } elseif (!empty($result['dry_run'])) {
                $planned = $result['planned'] ?? [];
                $output->writeln($planned === []
                    ? '<comment>No maintenance job is due.</comment>'
                    : 'Dry run, jobs that would run: ' . implode(', ', $planned));
            } elseif (empty($result['success'])) {
                $output->writeln('<error>' . ($result['message'] ?? 'Maintenance run failed.') . '</error>');
                foreach ($result['runs'] ?? [] as $run) {
                    if (($run['status'] ?? '') === 'error') {
                        $output->writeln('<error>' . ($run['job'] ?? '?') . ': ' . ($run['error'] ?? 'unknown error') . '</error>');
                    }
                }
            } else {
                if (empty($result['runs'])) {
                    $output->writeln($result['message'] ?? 'No maintenance job is due.');
                } else {
                    foreach ($result['runs'] as $run) {
                        $output->writeln(sprintf(
                            '%s: %s in %.2fs',
                            $run['job'] ?? '?',
                            $run['status'] ?? 'unknown',
                            (float) ($run['duration'] ?? 0)
                        ));
                    }
                }
            }

            if (!$loop) {
                if (!empty($result['locked'])) {
                    return Command::SUCCESS;
                }

                return !empty($result['success']) ? Command::SUCCESS : Command::FAILURE;
            }

            sleep($interval);
        } while (true);
    }
}
