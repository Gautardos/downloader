<?php

namespace App\Command;

use App\Service\Automation\ReleaseIntegrityService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:release-verify-downloads',
    description: 'Checks successful automation downloads for missing or truncated files and can retry them.',
)]
class ReleaseVerifyDownloadsCommand extends Command
{
    private ReleaseIntegrityService $integrity;

    public function __construct(ReleaseIntegrityService $integrity)
    {
        parent::__construct();
        $this->integrity = $integrity;
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Reset invalid intents to pending and mark files missing.');
        $this->addOption('quarantine', null, InputOption::VALUE_NONE, 'Rename invalid files with an .invalid-<date> suffix before retrying.');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum successful intents to check.', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $input->getOption('limit');
        $result = $this->integrity->verifySuccessfulIntents(
            (bool) $input->getOption('apply'),
            (bool) $input->getOption('quarantine'),
            is_numeric($limit) ? (int) $limit : 200
        );
        $stats = $result['stats'] ?? [];

        $output->writeln(($input->getOption('apply') ? 'Verification applied' : 'Verification preview') . ':');
        $output->writeln('Checked: ' . ($stats['checked'] ?? 0));
        $output->writeln('Invalid: ' . ($stats['invalid'] ?? 0));
        $output->writeln('Retried: ' . ($stats['retried'] ?? 0));
        $output->writeln('Quarantined: ' . ($stats['quarantined'] ?? 0));
        $output->writeln('Skipped: ' . ($stats['skipped'] ?? 0));

        foreach (($stats['details'] ?? []) as $detail) {
            if (($detail['status'] ?? '') === 'ok') {
                continue;
            }
            $output->writeln(sprintf(
                ' - [%s] %s: %s%s',
                (string) ($detail['status'] ?? ''),
                (string) ($detail['title'] ?? ''),
                (string) ($detail['reason'] ?? ''),
                !empty($detail['quarantined_to']) ? ' -> ' . $detail['quarantined_to'] : ''
            ));
        }

        return Command::SUCCESS;
    }
}
