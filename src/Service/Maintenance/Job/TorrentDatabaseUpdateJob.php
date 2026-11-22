<?php

namespace App\Service\Maintenance\Job;

use App\Service\Maintenance\MaintenanceSettings;
use App\Service\Maintenance\TorrentDatabaseUpdater;

class TorrentDatabaseUpdateJob implements MaintenanceJobInterface
{
    private TorrentDatabaseUpdater $updater;
    private MaintenanceSettings $settings;

    public function __construct(TorrentDatabaseUpdater $updater, MaintenanceSettings $settings)
    {
        $this->updater = $updater;
        $this->settings = $settings;
    }

    public function key(): string
    {
        return 'torrent_db';
    }

    public function label(): string
    {
        return 'Torrent database update';
    }

    public function isEnabled(array $settings): bool
    {
        return $this->settings->isJobEnabled($settings, $this->key());
    }

    public function run(callable $log): array
    {
        $result = $this->updater->update(false, $log);

        if (($result['status'] ?? 'error') !== 'success') {
            throw new \RuntimeException((string) ($result['error'] ?? 'Torrent database update failed.'));
        }

        $log(sprintf(
            'Torrent database is up to date: %d inserted, %d ignored.',
            $result['inserted'] ?? 0,
            $result['ignored'] ?? 0
        ));

        return [
            'status' => 'success',
            'inserted' => (int) ($result['inserted'] ?? 0),
            'ignored' => (int) ($result['ignored'] ?? 0),
            'duration' => (float) ($result['duration'] ?? 0),
            'diagnostics' => $result['diagnostics'] ?? [],
        ];
    }
}
