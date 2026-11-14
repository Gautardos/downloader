<?php

namespace App\Command;

use App\Service\Automation\AutomationStore;
use App\Service\Automation\LibraryIndexService;
use App\Service\Automation\LibraryMetadataResolver;
use App\Service\Automation\ListSyncService;
use App\Service\Automation\ReleaseIntentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:automation-worker',
    description: 'Runs scheduled automation tasks such as NAS library scans.',
)]
class AutomationWorkerCommand extends Command
{
    private AutomationStore $store;
    private LibraryIndexService $libraryIndex;
    private LibraryMetadataResolver $metadataResolver;
    private ListSyncService $listSync;
    private ReleaseIntentService $releaseIntents;

    public function __construct(
        AutomationStore $store,
        LibraryIndexService $libraryIndex,
        LibraryMetadataResolver $metadataResolver,
        ListSyncService $listSync,
        ReleaseIntentService $releaseIntents
    ) {
        parent::__construct();
        $this->store = $store;
        $this->libraryIndex = $libraryIndex;
        $this->metadataResolver = $metadataResolver;
        $this->listSync = $listSync;
        $this->releaseIntents = $releaseIntents;
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Run a single cycle and exit.');
        $this->addOption('force-scan', null, InputOption::VALUE_NONE, 'Force a library scan in this cycle.');
        $this->addOption('root', null, InputOption::VALUE_REQUIRED, 'Scan only this root key (movies, series, anime).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lockFile = $this->store->getStorageDir() . DIRECTORY_SEPARATOR . 'automation_worker.lock';
        if (!is_dir(dirname($lockFile))) {
            mkdir(dirname($lockFile), 0777, true);
        }

        $lock = @fopen($lockFile, 'c+');
        if (!$lock) {
            $error = error_get_last();
            $output->writeln('<error>Unable to open automation worker lock: ' . ($error['message'] ?? $lockFile) . '</error>');
            return Command::FAILURE;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('Another automation worker is already running.');
            fclose($lock);
            return Command::SUCCESS;
        }

        $logFile = $this->store->getStorageDir() . DIRECTORY_SEPARATOR . 'automation_worker.log';
        $log = static function (string $message) use ($logFile, $output): void {
            $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
            @file_put_contents($logFile, $line, FILE_APPEND);
            $output->writeln($message);
        };

        $forceScan = (bool) $input->getOption('force-scan');
        $once = (bool) $input->getOption('once');
        $cliRoot = $input->getOption('root');
        $cliRoot = is_string($cliRoot) && $cliRoot !== '' ? $cliRoot : null;

        $stale = $this->store->markStaleScans();
        if ($stale > 0) {
            $log('Marked ' . $stale . ' stale scan(s) as error.');
        }
        $staleResolutions = $this->store->markStaleResolutionRuns();
        if ($staleResolutions > 0) {
            $log('Marked ' . $staleResolutions . ' stale resolution(s) as error.');
        }
        $staleLists = $this->store->markStaleListRuns();
        if ($staleLists > 0) {
            $log('Marked ' . $staleLists . ' stale list run(s) as error.');
        }
        $staleIntents = $this->store->markStaleIntentRuns();
        if ($staleIntents > 0) {
            $log('Marked ' . $staleIntents . ' stale intent run(s) as error.');
        }
        $staleResolving = $this->store->markStaleResolvingIntents(30);
        if ($staleResolving > 0) {
            $log('Reset ' . $staleResolving . ' stale resolving intent(s).');
        }

        $log('Automation worker started.');
        $lastLockError = null;

        do {
            $this->store->setWorkerHeartbeat();

            $lockError = $this->libraryIndex->getLockError();
            if ($lockError !== null && $lockError !== $lastLockError) {
                $log('Scanner lock error: ' . $lockError);
                $lastLockError = $lockError;
            } elseif ($lockError === null) {
                $lastLockError = null;
            }

            if ($forceScan || $this->libraryIndex->shouldRunScheduled()) {
                $forceScan = false;
                $requestedRoot = $this->store->getSetting('library_scan_requested_root', null);
                $rootKey = $cliRoot ?: (is_string($requestedRoot) && $requestedRoot !== '' ? $requestedRoot : null);
                $rootKeys = $rootKey ? [$rootKey] : null;
                $result = null;

                $log('Starting scheduled library scan' . ($rootKey ? ' for root ' . $rootKey : '') . '.');
                try {
                    $result = $this->libraryIndex->scan(
                        $rootKeys,
                        null,
                        fn () => $this->store->setWorkerHeartbeat()
                    );
                    if (!empty($result['success'])) {
                        $this->store->setSetting('library_scan_requested', false);
                        $this->store->setSetting('library_scan_requested_root', null);
                        $stats = $result['stats'] ?? [];
                        $log(sprintf(
                            'Library scan #%s done: %d seen, %d inserted, %d updated, %d missing, %d warning(s).',
                            $result['run_id'] ?? '?',
                            $stats['files_seen'] ?? 0,
                            $stats['files_indexed'] ?? 0,
                            $stats['files_updated'] ?? 0,
                            $stats['files_missing'] ?? 0,
                            count($stats['warnings'] ?? [])
                        ));
                    } else {
                        $message = (string) ($result['message'] ?? 'unknown reason');
                        $log('Library scan skipped: ' . $message);
                        if (!str_contains($message, 'already running')) {
                            $this->store->setSetting('library_scan_requested', false);
                            $this->store->setSetting('library_scan_requested_root', null);
                        }
                    }
                } catch (\Throwable $e) {
                    $this->store->setSetting('library_scan_requested', false);
                    $this->store->setSetting('library_scan_requested_root', null);
                    $log('Library scan failed: ' . $e->getMessage());
                } finally {
                    $this->store->setWorkerHeartbeat();
                }
            }

            if ((bool) $this->store->getSetting('library_resolve_requested', false)) {
                $log('Starting metadata resolution.');
                try {
                    $result = $this->metadataResolver->resolve(
                        null,
                        null,
                        fn () => $this->store->setWorkerHeartbeat()
                    );
                    if (!empty($result['success'])) {
                        $this->store->setSetting('library_resolve_requested', false);
                        $stats = $result['stats'] ?? [];
                        $log(sprintf(
                            'Metadata resolution done: %d groups resolved, %d entries updated, %d skipped.',
                            $stats['groups_resolved'] ?? 0,
                            $stats['entries_resolved'] ?? 0,
                            $stats['groups_skipped'] ?? 0
                        ));
                    } else {
                        $message = (string) ($result['message'] ?? 'unknown reason');
                        $log('Metadata resolution skipped: ' . $message);
                        if (!str_contains($message, 'already running')) {
                            $this->store->setSetting('library_resolve_requested', false);
                        }
                    }
                } catch (\Throwable $e) {
                    $this->store->setSetting('library_resolve_requested', false);
                    $log('Metadata resolution failed: ' . $e->getMessage());
                } finally {
                    $this->store->setWorkerHeartbeat();
                }
            }

            if ($this->listSync->shouldRunScheduled()) {
                $requestedSource = $this->listSync->getRequestedSourceId();
                $sourceIds = $requestedSource !== null ? [$requestedSource] : $this->listSync->dueSourceIds();
                $log('Starting list dry-run' . ($sourceIds ? ' for ' . implode(', ', $sourceIds) : '') . '.');
                try {
                    $result = $this->listSync->sync(
                        $sourceIds ?: null,
                        null,
                        fn () => $this->store->setWorkerHeartbeat()
                    );
                    if (!empty($result['success'])) {
                        $stats = $result['stats'] ?? [];
                        $log(sprintf(
                            'List dry-run done: %d items seen, %d present, %d missing, %d ambiguous, %d ignored.',
                            $stats['items_seen'] ?? 0,
                            $stats['present'] ?? 0,
                            $stats['missing'] ?? 0,
                            $stats['ambiguous'] ?? 0,
                            $stats['ignored'] ?? 0
                        ));
                    } else {
                        $log('List dry-run skipped: ' . ($result['message'] ?? 'unknown reason'));
                    }
                } catch (\Throwable $e) {
                    $log('List dry-run failed: ' . $e->getMessage());
                } finally {
                    $this->store->setWorkerHeartbeat();
                }
            }

            try {
                $queuedIntents = $this->releaseIntents->autoQueueDue(300);
                if ($queuedIntents > 0) {
                    $log('Queued ' . $queuedIntents . ' missing movie intent(s) from auto-download sources.');
                }
            } catch (\Throwable $e) {
                $log('Intent queueing failed: ' . $e->getMessage());
            }

            if ($this->releaseIntents->shouldRunAutomatically()) {
                $log('Starting movie intent run.');
                try {
                    $result = $this->releaseIntents->processDueIntents(null, fn () => $this->store->setWorkerHeartbeat());
                    if (!empty($result['success'])) {
                        $stats = $result['stats'] ?? [];
                        $log(sprintf(
                            'Movie intent run done: %d seen, %d queued, %d no source, %d error, queue depth %d.',
                            $stats['intents_seen'] ?? 0,
                            $stats['intents_queued'] ?? 0,
                            $stats['intents_no_source'] ?? 0,
                            $stats['intents_error'] ?? 0,
                            $stats['queue_depth'] ?? 0
                        ));
                    } else {
                        $log('Movie intent run skipped: ' . ($result['message'] ?? 'unknown reason'));
                    }
                } catch (\Throwable $e) {
                    $log('Movie intent run failed: ' . $e->getMessage());
                } finally {
                    $this->store->setWorkerHeartbeat();
                }
            }

            if (!$once) {
                sleep(5);
            }
        } while (!$once);

        $log('Automation worker stopped.');
        flock($lock, LOCK_UN);
        fclose($lock);

        return Command::SUCCESS;
    }
}
