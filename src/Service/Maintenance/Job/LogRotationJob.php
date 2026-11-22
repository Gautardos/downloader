<?php

namespace App\Service\Maintenance\Job;

use App\Service\LogRotator;
use App\Service\Maintenance\MaintenanceSettings;
use App\Service\Maintenance\MaintenanceStore;
use DateTimeImmutable;
use DateTimeZone;

class LogRotationJob implements MaintenanceJobInterface
{
    private MaintenanceStore $store;
    private MaintenanceSettings $settings;
    private ?DateTimeImmutable $now;

    public function __construct(
        MaintenanceStore $store,
        MaintenanceSettings $settings,
        ?DateTimeImmutable $now = null
    ) {
        $this->store = $store;
        $this->settings = $settings;
        $this->now = $now;
    }

    public function key(): string
    {
        return 'log_rotation';
    }

    public function label(): string
    {
        return 'Log rotation & housekeeping';
    }

    public function isEnabled(array $settings): bool
    {
        return $this->settings->isJobEnabled($settings, $this->key());
    }

    public function run(callable $log): array
    {
        $settings = $this->settings->all();
        $logsDir = $this->store->getStorageDir() . DIRECTORY_SEPARATOR . 'logs';
        $rotated = [];

        if (is_dir($logsDir)) {
            $files = glob($logsDir . DIRECTORY_SEPARATOR . '*.log') ?: [];
            sort($files, SORT_STRING);
            foreach ($files as $file) {
                if (!is_file($file)) {
                    continue;
                }
                if (LogRotator::rotateIfOversized($file)) {
                    $rotated[] = basename($file);
                }
            }
        }

        $retention = (int) ($settings['run_retention_days'] ?? 30);
        $now = $this->now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $purged = $this->store->purgeRunsOlderThan($retention, $now);

        $log(sprintf(
            'Housekeeping finished: %d log file(s) rotated, %d old maintenance run(s) purged.',
            count($rotated),
            $purged
        ));

        return [
            'status' => 'success',
            'rotated' => count($rotated),
            'rotated_files' => $rotated,
            'purged_runs' => $purged,
            'retention_days' => $retention,
        ];
    }
}
