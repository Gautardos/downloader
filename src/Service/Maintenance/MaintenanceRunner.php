<?php

namespace App\Service\Maintenance;

use App\Service\JsonStorage;
use App\Service\LogRedactor;
use App\Service\LogRotator;
use App\Service\Maintenance\Job\MaintenanceJobInterface;
use App\Service\ServerNotificationService;
use DateTimeImmutable;
use DateTimeZone;

class MaintenanceRunner
{
    private MaintenanceStore $store;
    private MaintenanceSettings $settings;
    private MaintenanceScheduler $scheduler;
    /** @var MaintenanceJobInterface[] */
    private array $jobs;
    private JsonStorage $storage;
    private ServerNotificationService $notifications;
    private LogRedactor $redactor;
    private ?DateTimeImmutable $now;

    /**
     * @param iterable<MaintenanceJobInterface> $jobs
     */
    public function __construct(
        MaintenanceStore $store,
        MaintenanceSettings $settings,
        MaintenanceScheduler $scheduler,
        iterable $jobs,
        JsonStorage $storage,
        ServerNotificationService $notifications,
        LogRedactor $redactor,
        ?DateTimeImmutable $now = null
    ) {
        $this->store = $store;
        $this->settings = $settings;
        $this->scheduler = $scheduler;
        $this->jobs = [];
        foreach ($jobs as $job) {
            if ($job instanceof MaintenanceJobInterface) {
                $this->jobs[$job->key()] = $job;
            }
        }
        $this->storage = $storage;
        $this->notifications = $notifications;
        $this->redactor = $redactor;
        $this->now = $now;
    }

    public function setNow(?DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    /**
     * @return MaintenanceJobInterface[]
     */
    public function getJobs(): array
    {
        return $this->jobs;
    }

    public function describeJobs(): array
    {
        $settings = $this->settings->all();
        $now = $this->currentNow();
        $nextRun = $this->scheduler->nextRunAt($settings, $now);
        $result = [];

        foreach ($this->jobs as $key => $job) {
            $result[] = [
                'key' => $key,
                'label' => $job->label(),
                'enabled' => $job->isEnabled($settings),
                'last_run' => $this->store->getLastRun($key),
                'next_run' => $nextRun,
            ];
        }

        return $result;
    }

    public function isLocked(): bool
    {
        $this->ensureStorageDir();
        $lockPath = $this->store->getLockPath();
        $handle = @fopen($lockPath, 'c+');
        if (!$handle) {
            return false;
        }

        $acquired = flock($handle, LOCK_EX | LOCK_NB);
        if ($acquired) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return !$acquired;
    }

    /**
     * @param array{force?:bool,job?:?string,dry_run?:bool,trigger?:string} $options
     */
    public function run(array $options = []): array
    {
        $force = (bool) ($options['force'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $jobKey = isset($options['job']) && is_string($options['job']) && $options['job'] !== ''
            ? $options['job']
            : null;
        $trigger = (string) ($options['trigger'] ?? ($force ? 'manual' : 'schedule'));

        $this->ensureStorageDir();
        $lock = $this->acquireLock();
        if ($lock === null) {
            return [
                'success' => false,
                'locked' => true,
                'dry_run' => $dryRun,
                'message' => 'Another maintenance run is already in progress.',
                'runs' => [],
                'skipped' => [],
            ];
        }

        try {
            if (!$dryRun) {
                $this->store->markStaleRuns();
            }

            $settings = $this->settings->all();
            $now = $this->currentNow();
            $log = $this->createLogger();
            $selected = [];
            $skipped = [];

            if ($jobKey !== null && !isset($this->jobs[$jobKey])) {
                return [
                    'success' => false,
                    'locked' => false,
                    'dry_run' => $dryRun,
                    'message' => 'Unknown maintenance job: ' . $jobKey,
                    'runs' => [],
                    'skipped' => [],
                ];
            }

            foreach ($this->jobs as $key => $job) {
                if ($jobKey !== null && $key !== $jobKey) {
                    continue;
                }

                $explicit = $jobKey !== null;
                $enabled = $job->isEnabled($settings);
                if (!$enabled && !($force && $explicit)) {
                    $skipped[] = ['job' => $key, 'reason' => 'disabled'];
                    continue;
                }

                if (!$force) {
                    $last = $this->store->getLastRun($key);
                    $lastStarted = $last !== null ? $this->parseStoredDate((string) $last['started_at']) : null;
                    if (!$this->scheduler->isDue($settings, $lastStarted, $now)) {
                        $skipped[] = ['job' => $key, 'reason' => 'not_due'];
                        continue;
                    }
                }

                $selected[$key] = $job;
            }

            if ($dryRun) {
                $log(sprintf(
                    'Dry run: %d maintenance job(s) would run%s.',
                    count($selected),
                    $selected !== [] ? ' (' . implode(', ', array_keys($selected)) . ')' : ''
                ));

                return [
                    'success' => true,
                    'locked' => false,
                    'dry_run' => true,
                    'message' => $selected === []
                        ? 'No maintenance job is due.'
                        : 'Dry run: ' . implode(', ', array_keys($selected)) . ' would run.',
                    'planned' => array_keys($selected),
                    'runs' => [],
                    'skipped' => $skipped,
                ];
            }

            if ($selected === []) {
                return [
                    'success' => true,
                    'locked' => false,
                    'dry_run' => false,
                    'message' => 'No maintenance job is due.',
                    'runs' => [],
                    'skipped' => $skipped,
                ];
            }

            $pauseEnabled = !empty($settings['pause_downloads']);
            $runs = [];
            $overallSuccess = true;

            foreach ($selected as $key => $job) {
                $runId = $this->store->startRun($key, $trigger, $force);
                $started = microtime(true);

                $log(sprintf('Starting maintenance job "%s" (%s).', $job->label(), $trigger));
                if ($pauseEnabled) {
                    $this->activatePause($key);
                }

                try {
                    $summary = $job->run($log);
                    if (!is_array($summary)) {
                        $summary = [];
                    }
                    $status = (string) ($summary['status'] ?? 'success');
                    if (!in_array($status, ['success', 'warning', 'error'], true)) {
                        $status = 'success';
                    }

                    $error = null;
                    if ($status === 'error') {
                        $error = (string) ($summary['error'] ?? 'Job reported an error.');
                    }

                    $duration = round(microtime(true) - $started, 3);
                    $this->store->finishRun($runId, $status, $summary, $error, $duration);

                    if ($status === 'error') {
                        $overallSuccess = false;
                        $this->notifyFailure($job, $error ?? 'Unknown error.');
                        $log(sprintf('Maintenance job "%s" failed: %s', $job->label(), $error));
                    } else {
                        $log(sprintf('Maintenance job "%s" finished with status %s in %.2fs.', $job->label(), $status, $duration));
                    }

                    $runs[] = [
                        'job' => $key,
                        'job_label' => $job->label(),
                        'run_id' => $runId,
                        'status' => $status,
                        'duration' => $duration,
                        'summary' => $summary,
                        'error' => $error,
                    ];
                } catch (\Throwable $e) {
                    $duration = round(microtime(true) - $started, 3);
                    $message = $this->redactor->redact($e->getMessage());
                    $this->store->finishRun($runId, 'error', [], $message, $duration);
                    $this->notifyFailure($job, $message);
                    $log(sprintf('Maintenance job "%s" failed: %s', $job->label(), $message));
                    $overallSuccess = false;
                    $runs[] = [
                        'job' => $key,
                        'job_label' => $job->label(),
                        'run_id' => $runId,
                        'status' => 'error',
                        'duration' => $duration,
                        'summary' => [],
                        'error' => $message,
                    ];
                } finally {
                    if ($pauseEnabled) {
                        $this->deactivatePause();
                    }
                }
            }

            return [
                'success' => $overallSuccess,
                'locked' => false,
                'dry_run' => false,
                'message' => $overallSuccess
                    ? sprintf('%d maintenance job(s) completed.', count($runs))
                    : 'One or more maintenance jobs failed.',
                'runs' => $runs,
                'skipped' => $skipped,
            ];
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function currentNow(): DateTimeImmutable
    {
        return $this->now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function parseStoredDate(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function ensureStorageDir(): void
    {
        $dir = $this->store->getStorageDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $logsDir = $dir . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0777, true);
        }
    }

    /**
     * @return resource|null
     */
    private function acquireLock()
    {
        $handle = @fopen($this->store->getLockPath(), 'c+');
        if (!$handle) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }
        @chmod($this->store->getLockPath(), 0600);

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private function releaseLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function createLogger(): callable
    {
        $path = $this->store->getLogPath();
        $redactor = $this->redactor;

        return static function (string $message) use ($path, $redactor): void {
            $safe = $redactor->redact($message);
            LogRotator::append($path, '[' . date('Y-m-d H:i:s') . '] ' . $safe . "\n");
        };
    }

    private function activatePause(string $jobKey): void
    {
        $payload = json_encode([
            'job' => $jobKey,
            'started_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        @file_put_contents($this->store->getPausePath(), $payload);
        @chmod($this->store->getPausePath(), 0600);
    }

    private function deactivatePause(): void
    {
        @unlink($this->store->getPausePath());
    }

    private function notifyFailure(MaintenanceJobInterface $job, string $message): void
    {
        $this->notifications->push($this->storage, [
            'type' => 'error',
            'message' => 'Maintenance job failed: ' . $job->label() . ' — ' . $this->redactor->redact($message),
            'action' => 'Failed',
            'item' => $job->label(),
            'media_type' => 'maintenance',
            'time' => date('H:i:s'),
        ]);
    }
}
