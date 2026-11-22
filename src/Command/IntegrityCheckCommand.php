<?php

namespace App\Command;

use App\Service\Integrity\FileIntegrityChecker;
use App\Service\Integrity\IntegrityRequeueService;
use App\Service\Integrity\VideoIntegrityService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:integrity-check',
    description: 'Runs a quick or deep integrity check on downloaded and library video files.',
)]
class IntegrityCheckCommand extends Command
{
    private VideoIntegrityService $integrity;
    private IntegrityRequeueService $requeue;

    public function __construct(VideoIntegrityService $integrity, IntegrityRequeueService $requeue)
    {
        parent::__construct();
        $this->integrity = $integrity;
        $this->requeue = $requeue;
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of files to check.', '200');
        $this->addOption('deep', null, InputOption::VALUE_NONE, 'Run ffprobe on each file when available.');
        $this->addOption('requeue', null, InputOption::VALUE_NONE, 'Requeue incomplete, corrupted or missing files with a known HTTP source.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be checked or requeued without changing anything.');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print a JSON payload on stdout.');
        $this->addOption('history-only', null, InputOption::VALUE_NONE, 'Only check video files from the download history.');
        $this->addOption('show', null, InputOption::VALUE_REQUIRED, 'Only check library files for this show folder.');
        $this->addOption('tmdb', null, InputOption::VALUE_REQUIRED, 'Only check library files for this TMDB ID.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limitOption = $input->getOption('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : 200;
        $deep = (bool) $input->getOption('deep');
        $dryRun = (bool) $input->getOption('dry-run');
        $requeue = (bool) $input->getOption('requeue');
        $json = (bool) $input->getOption('json');

        $filters = ['include_library' => !$input->getOption('history-only')];
        $show = trim((string) $input->getOption('show'));
        if ($show !== '') {
            $filters['show_folder'] = $show;
        }
        $tmdb = $input->getOption('tmdb');
        if (is_numeric($tmdb) && (int) $tmdb > 0) {
            $filters['tmdb_id'] = (int) $tmdb;
        }

        $result = $this->integrity->scan($limit, $deep, !$dryRun, $filters);
        $payload = [
            'success' => true,
            'dry_run' => $dryRun,
            'checked' => (int) ($result['checked'] ?? 0),
            'limit' => (int) ($result['limit'] ?? $limit),
            'deep' => $deep,
            'library_included' => (bool) ($result['library_included'] ?? false),
            'summary' => $result['summary'] ?? [],
        ];

        if ($requeue && $dryRun) {
            $preview = [
                'requested' => 0,
                'processed' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'skipped' => 0,
                'dry_run' => true,
                'results' => [],
            ];
            foreach (($result['checks'] ?? []) as $check) {
                if (!in_array($check['status'] ?? '', [
                    FileIntegrityChecker::STATUS_INCOMPLETE,
                    FileIntegrityChecker::STATUS_CORRUPTED,
                    FileIntegrityChecker::STATUS_MISSING,
                ], true)) {
                    continue;
                }
                $preview['requested']++;
                if ($preview['processed'] >= $limit) {
                    $preview['skipped']++;
                    continue;
                }
                $preview['processed']++;
                $candidate = $this->requeue->requeueCheck($check, true);
                $candidate['identifier'] = (string) ($check['check_key'] ?? '');
                $preview['results'][] = $candidate;
                if (!empty($candidate['success'])) {
                    $preview['succeeded']++;
                } else {
                    $preview['failed']++;
                }
            }
            $payload['requeue'] = $preview;
        } elseif ($requeue) {
            $payload['requeue'] = $this->requeue->requeueCandidates(false, $limit);
        }

        if ($json) {
            $output->writeln(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $summary = $payload['summary'];
        $output->writeln(($dryRun ? 'Integrity check preview' : 'Integrity check') . ':');
        $output->writeln('Checked: ' . $payload['checked']);
        $output->writeln('Healthy: ' . (int) ($summary['healthy'] ?? 0));
        $output->writeln('Incomplete: ' . (int) ($summary['incomplete'] ?? 0));
        $output->writeln('Corrupted: ' . (int) ($summary['corrupted'] ?? 0));
        $output->writeln('Missing: ' . (int) ($summary['missing'] ?? 0));
        $output->writeln('Outside root: ' . (int) ($summary['outside_root'] ?? 0));
        $output->writeln('Unsupported: ' . (int) ($summary['unsupported'] ?? 0));

        if ($requeue) {
            $requeueResult = $payload['requeue'];
            $output->writeln(($dryRun ? 'Would requeue: ' : 'Requeued: ') . (int) ($requeueResult['succeeded'] ?? 0));
            $output->writeln('Requeue failed: ' . (int) ($requeueResult['failed'] ?? 0));
            foreach (($requeueResult['results'] ?? []) as $entry) {
                if (empty($entry['success'])) {
                    $output->writeln(' - ' . (string) ($entry['identifier'] ?? '?') . ': ' . (string) ($entry['message'] ?? 'failed'));
                }
            }
        }

        return Command::SUCCESS;
    }
}
