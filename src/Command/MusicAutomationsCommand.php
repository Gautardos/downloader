<?php

namespace App\Command;

use App\Service\LogRotator;
use App\Service\MusicAutomation\MusicAutomationRunner;
use App\Service\MusicAutomation\MusicAutomationStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:music-automations',
    description: 'Runs scheduled Spotify playlist automations.',
)]
class MusicAutomationsCommand extends Command
{
    public function __construct(
        private MusicAutomationStore $store,
        private MusicAutomationRunner $runner
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Run a single cycle and exit.');
        $this->addOption('loop', null, InputOption::VALUE_NONE, 'Keep running and process due rules.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Run every rule even when the schedule is not due.');
        $this->addOption('rule', null, InputOption::VALUE_REQUIRED, 'Run only this rule ID.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview found/new/skipped tracks without enqueueing.');
        $this->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between loop cycles.', '60');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        $lockFile = $this->store->getLockPath();
        if (!is_dir(dirname($lockFile))) {
            mkdir(dirname($lockFile), 0777, true);
        }
        $lock = @fopen($lockFile, 'c+');
        if (!$lock) {
            $output->writeln('<error>Unable to open the music automation worker lock.</error>');
            return Command::FAILURE;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('Another music automation worker is already running.');
            fclose($lock);
            return Command::SUCCESS;
        }

        $loop = (bool) $input->getOption('loop') && !(bool) $input->getOption('once');
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $interval = max(10, (int) $input->getOption('interval'));
        $ruleOption = $input->getOption('rule');
        $ruleId = is_numeric($ruleOption) ? (int) $ruleOption : null;

        $stale = $this->store->markStaleRuns();
        if ($stale > 0) {
            $this->log('Marked ' . $stale . ' stale music automation run(s) as error.');
        }

        do {
            $this->writeHeartbeat();
            $rules = $this->selectRules($force, $ruleId);
            if ($rules === []) {
                $output->writeln('No music automation rule is due.');
            }

            $hadError = false;
            foreach ($rules as $rule) {
                $this->writeHeartbeat();
                $result = $this->runner->runRule(
                    $rule,
                    $dryRun,
                    $force || $ruleId !== null,
                    $ruleId !== null ? 'manual' : 'schedule'
                );

                $line = sprintf(
                    '%s: %s (found %d, new %d, skipped %d, queued %d)',
                    (string) ($rule['name'] ?? 'Playlist'),
                    (string) ($result['status'] ?? 'unknown'),
                    (int) ($result['found'] ?? 0),
                    (int) ($result['new'] ?? 0),
                    (int) ($result['skipped'] ?? 0),
                    (int) ($result['queued'] ?? 0)
                );
                if (($result['status'] ?? '') === 'error') {
                    $hadError = true;
                    $output->writeln('<error>' . $line . ' — ' . (string) ($result['message'] ?? '') . '</error>');
                } else {
                    $output->writeln($line);
                }
            }

            if (!$loop) {
                return $hadError ? Command::FAILURE : Command::SUCCESS;
            }

            sleep($interval);
        } while (true);
    }

    private function selectRules(bool $force, ?int $ruleId): array
    {
        if ($ruleId !== null) {
            $rule = $this->store->getRule($ruleId);

            return $rule !== null ? [$rule] : [];
        }

        if ($force) {
            return $this->store->getRules(false);
        }

        return $this->store->getDueRules();
    }

    private function writeHeartbeat(): void
    {
        $path = $this->store->getHeartbeatPath();
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        @file_put_contents($path, (string) time(), LOCK_EX);
    }

    private function log(string $message): void
    {
        LogRotator::append($this->store->getLogPath(), '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");
    }
}
