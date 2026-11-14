<?php

namespace App\Service\Automation;

use Symfony\Component\Process\Process;

class LibraryIndexService
{
    private AutomationStore $store;
    private AutomationSettings $settings;
    private LibraryNameParser $parser;
    private string $projectDir;

    public function __construct(
        AutomationStore $store,
        AutomationSettings $settings,
        LibraryNameParser $parser,
        string $projectDir
    ) {
        $this->store = $store;
        $this->settings = $settings;
        $this->parser = $parser;
        $this->projectDir = $projectDir;
    }

    public function scan(?array $rootKeys = null, ?callable $onProgress = null, ?callable $onHeartbeat = null): array
    {
        $lockState = $this->tryAcquireLock();
        if ($lockState['error'] !== null) {
            return ['success' => false, 'message' => $lockState['error']];
        }
        if ($lockState['busy']) {
            return ['success' => false, 'message' => 'A library scan is already running.'];
        }

        $lock = $lockState['handle'];
        $roots = $this->settings->getRoots();
        if ($rootKeys !== null) {
            $roots = array_intersect_key($roots, array_flip($rootKeys));
        }

        $runRoots = [];
        foreach ($roots as $key => $root) {
            if (!is_array($root) || empty($root['enabled']) || empty($root['path'])) {
                continue;
            }
            $runRoots[$key] = [
                'label' => (string) ($root['label'] ?? $key),
                'path' => (string) $root['path'],
                'type' => (string) ($root['type'] ?? 'series'),
            ];
        }

        $started = microtime(true);
        $runId = $this->store->startScan($runRoots);
        $stats = [
            'files_seen' => 0,
            'files_indexed' => 0,
            'files_updated' => 0,
            'files_missing' => 0,
            'files_skipped' => 0,
            'roots' => [],
            'warnings' => [],
        ];

        if (empty($runRoots)) {
            $stats['duration'] = round(microtime(true) - $started, 2);
            $stats['warnings'][] = 'No enabled library root to scan.';
            $this->store->finishScan($runId, $stats, 'warning', 'No enabled library root to scan.');
            $this->releaseLock($lock);
            return ['success' => true, 'run_id' => $runId, 'stats' => $stats, 'message' => 'No enabled library root to scan.'];
        }

        @set_time_limit(0);
        ignore_user_abort(true);

        try {
            foreach ($runRoots as $rootKey => $root) {
                $rootPath = rtrim((string) $root['path'], '/\\');
                $previousActive = $this->store->getRootActiveCount($rootKey);
                $rootStats = ['files' => 0, 'candidates' => 0, 'indexed' => 0, 'updated' => 0, 'missing' => 0, 'skipped' => 0];

                if (!is_dir($rootPath)) {
                    $stats['warnings'][] = $rootKey . ': path does not exist (' . $rootPath . '); previous entries preserved.';
                    $stats['roots'][$rootKey] = $rootStats;
                    continue;
                }

                $scanner = $this->settings->getScanner();
                $extensions = array_map('strtolower', is_array($scanner['extensions'] ?? null) ? $scanner['extensions'] : []);
                $skipSamples = (bool) ($scanner['skip_samples'] ?? true);
                $minSize = max(0, (int) ($scanner['min_size_mb'] ?? 0)) * 1024 * 1024;
                $followSymlinks = (bool) ($scanner['follow_symlinks'] ?? false);
                $rootReal = realpath($rootPath) ?: $rootPath;
                $rootPrefix = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
                $visitedDirs = [str_replace('\\', '/', $rootReal) => true];
                $visitedFiles = [];
                $lastHeartbeat = microtime(true);
                $lastProgress = microtime(true);

                if ($onHeartbeat) {
                    $onHeartbeat();
                }

                try {
                    $directory = new \RecursiveDirectoryIterator(
                        $rootPath,
                        \FilesystemIterator::SKIP_DOTS | ($followSymlinks ? \FilesystemIterator::FOLLOW_SYMLINKS : 0)
                    );
                    $filter = new \RecursiveCallbackFilterIterator(
                        $directory,
                        static function (\SplFileInfo $current) use (&$visitedDirs, $rootPrefix, $followSymlinks): bool {
                            if (!$current->isDir()) {
                                return true;
                            }

                            $name = strtolower($current->getFilename());
                            if ($name === '' || str_starts_with($name, '.')) {
                                return false;
                            }
                            if (in_array($name, ['@eadir', '#recycle', '$recycle.bin', 'lost+found', 'system volume information'], true)) {
                                return false;
                            }

                            $real = realpath($current->getPathname());
                            if ($real === false) {
                                return false;
                            }
                            $real = str_replace('\\', '/', $real);
                            if (isset($visitedDirs[$real])) {
                                return false;
                            }
                            if ($followSymlinks && !str_starts_with(rtrim($real, '/') . '/', $rootPrefix)) {
                                return false;
                            }

                            $visitedDirs[$real] = true;

                            return true;
                        }
                    );
                    $iterator = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::LEAVES_ONLY);

                    foreach ($iterator as $file) {
                        if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                            continue;
                        }

                        $stat = @stat($file->getPathname());
                        $fileKey = $stat ? ($stat['dev'] . ':' . $stat['ino']) : $file->getPathname();
                        if (isset($visitedFiles[$fileKey])) {
                            continue;
                        }
                        $visitedFiles[$fileKey] = true;

                        $stats['files_seen']++;
                        $rootStats['files']++;

                        $extension = strtolower($file->getExtension());
                        if ($extensions && !in_array($extension, $extensions, true)) {
                            $stats['files_skipped']++;
                            $rootStats['skipped']++;
                            continue;
                        }

                        $filename = $file->getFilename();
                        if ($skipSamples && preg_match('/\b(sample|trailer|featurette)\b/i', $filename)) {
                            $stats['files_skipped']++;
                            $rootStats['skipped']++;
                            continue;
                        }

                        try {
                            $size = (int) $file->getSize();
                            $mtime = (int) $file->getMTime();
                        } catch (\Throwable $e) {
                            $stats['files_skipped']++;
                            $rootStats['skipped']++;
                            $stats['warnings'][] = $filename . ': ' . $e->getMessage();
                            continue;
                        }

                        if ($minSize > 0 && $size < $minSize) {
                            $stats['files_skipped']++;
                            $rootStats['skipped']++;
                            continue;
                        }

                        $rootStats['candidates']++;

                        try {
                            $entry = $this->parser->parse(
                                $file->getPathname(),
                                $rootPath,
                                $rootKey,
                                (string) $root['type'],
                                $scanner
                            );
                            $entry['size'] = $size;
                            $entry['mtime'] = $mtime;
                            $entry['scan_id'] = $runId;

                            $result = $this->store->upsertLibraryEntry($entry);
                            if ($result === 'inserted') {
                                $stats['files_indexed']++;
                                $rootStats['indexed']++;
                            } elseif ($result === 'updated') {
                                $stats['files_updated']++;
                                $rootStats['updated']++;
                            }
                        } catch (\Throwable $e) {
                            $stats['files_skipped']++;
                            $rootStats['skipped']++;
                            $stats['warnings'][] = $filename . ': ' . $e->getMessage();
                        }

                        $now = microtime(true);
                        if ($onHeartbeat && ($now - $lastHeartbeat) >= 2) {
                            $onHeartbeat();
                            $lastHeartbeat = $now;
                        }
                        if (($now - $lastProgress) >= 2 || $stats['files_seen'] % 100 === 0) {
                            $this->store->updateScan($runId, $stats);
                            if ($onProgress) {
                                $onProgress($stats);
                            }
                            $lastProgress = $now;
                        }
                    }
                } catch (\Throwable $e) {
                    $stats['warnings'][] = $rootKey . ': scan failed (' . $e->getMessage() . '); previous entries preserved.';
                    $stats['roots'][$rootKey] = $rootStats;
                    continue;
                }

                if ($rootStats['candidates'] === 0) {
                    if ($previousActive > 0) {
                        $stats['warnings'][] = $rootKey . ': no video candidate found; previous entries preserved.';
                    }
                } else {
                    $missing = $this->store->finishRootScan($rootKey, $runId);
                    $stats['files_missing'] += $missing;
                    $rootStats['missing'] = $missing;
                }

                $stats['roots'][$rootKey] = $rootStats;
                if ($onHeartbeat) {
                    $onHeartbeat();
                }
            }

            $stats['series_reconciled'] = $this->store->reconcileSeriesAmbiguity();
            $stats['duration'] = round(microtime(true) - $started, 2);
            $status = empty($stats['warnings']) ? 'success' : 'warning';
            $error = $status === 'warning' ? implode(' | ', array_slice($stats['warnings'], 0, 20)) : null;
            $this->store->finishScan($runId, $stats, $status, $error);

            return ['success' => true, 'run_id' => $runId, 'stats' => $stats];
        } catch (\Throwable $e) {
            $stats['duration'] = round(microtime(true) - $started, 2);
            $this->store->finishScan($runId, $stats, 'error', $e->getMessage());
            throw $e;
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function startBackgroundScan(?string $rootKey = null): array
    {
        $state = $this->getScanState();
        if ($state['error'] !== null) {
            return ['success' => false, 'message' => $state['error']];
        }
        if ($state['running']) {
            return ['success' => false, 'message' => 'A library scan is already running.'];
        }

        $this->requestScheduledScan($rootKey);

        $consolePath = $this->projectDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'console';
        $phpPath = PHP_BINARY;
        if (!str_contains($phpPath, 'bin/php') && !str_ends_with($phpPath, 'php.exe') && basename($phpPath) !== 'php') {
            $phpPath = 'php';
        }

        $arguments = [$phpPath, $consolePath, 'app:automation-worker', '--once'];
        if ($rootKey !== null && $rootKey !== '') {
            $arguments[] = '--root=' . $rootKey;
        }

        try {
            $process = new Process($arguments);
            $process->setWorkingDirectory($this->projectDir);
            $process->start();
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Could not start the automation worker: ' . $e->getMessage()];
        }

        return ['success' => true, 'message' => 'Library scan requested.'];
    }

    public function requestScheduledScan(?string $rootKey = null): void
    {
        $this->store->setSetting('library_scan_requested', true);
        $this->store->setSetting('library_scan_requested_at', time());
        $this->store->setSetting('library_scan_requested_root', $rootKey ?: null);
    }

    public function shouldRunScheduled(): bool
    {
        $state = $this->getScanState();
        if ($state['error'] !== null || $state['running']) {
            return false;
        }

        if ((bool) $this->store->getSetting('library_scan_requested', false)) {
            return true;
        }

        $last = $this->store->getLastScan();
        if ($last === null) {
            return true;
        }

        $lastAt = strtotime((string) ($last['finished_at'] ?? $last['started_at'] ?? '')) ?: 0;

        $schedule = $this->settings->getSchedule();
        $interval = max(300, (int) ($schedule['library'] ?? 21600));

        return (time() - $lastAt) >= $interval;
    }

    public function getScanState(): array
    {
        $state = $this->tryAcquireLock();
        if ($state['handle']) {
            $this->releaseLock($state['handle']);
        }

        return ['running' => $state['busy'], 'error' => $state['error']];
    }

    public function isRunning(): bool
    {
        return $this->getScanState()['running'];
    }

    public function getLockError(): ?string
    {
        return $this->getScanState()['error'];
    }

    private function tryAcquireLock(): array
    {
        $lockFile = $this->store->getStorageDir() . DIRECTORY_SEPARATOR . 'automation_scan.lock';
        if (!is_dir(dirname($lockFile))) {
            mkdir(dirname($lockFile), 0777, true);
        }

        $handle = @fopen($lockFile, 'c+');
        if (!$handle) {
            $error = error_get_last();

            return [
                'handle' => null,
                'busy' => false,
                'error' => 'Unable to open scan lock file: ' . ($error['message'] ?? $lockFile),
            ];
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return ['handle' => null, 'busy' => true, 'error' => null];
        }

        return ['handle' => $handle, 'busy' => false, 'error' => null];
    }

    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
