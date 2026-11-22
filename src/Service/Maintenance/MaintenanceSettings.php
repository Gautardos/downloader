<?php

namespace App\Service\Maintenance;

use DateTimeImmutable;
use DateTimeZone;

class MaintenanceSettings
{
    public const SETTINGS_KEY = 'maintenance_settings';
    public const JOBS = ['torrent_db', 'log_rotation', 'integrity'];

    private MaintenanceStore $store;

    public function __construct(MaintenanceStore $store)
    {
        $this->store = $store;
    }

    public function defaults(): array
    {
        return [
            'enabled' => false,
            'frequency' => 'daily',
            'time' => '03:00',
            'days' => [1, 2, 3, 4, 5, 6, 7],
            'weekday' => 1,
            'timezone' => 'UTC',
            'pause_downloads' => true,
            'run_retention_days' => 30,
            'jobs' => [
                'torrent_db' => true,
                'log_rotation' => true,
                'integrity' => false,
            ],
            'enabled_at' => null,
        ];
    }

    public function all(): array
    {
        $stored = $this->store->getSetting(self::SETTINGS_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return $this->merge($this->defaults(), $stored);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();

        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    public function save(array $input): array
    {
        $current = $this->all();
        $settings = $this->sanitize($input, $current);

        $wasEnabled = !empty($current['enabled']);
        if ($settings['enabled']) {
            if (!$wasEnabled || empty($current['enabled_at'])) {
                $settings['enabled_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
            } else {
                $settings['enabled_at'] = $current['enabled_at'];
            }
        } else {
            $settings['enabled_at'] = null;
        }

        $this->store->setSetting(self::SETTINGS_KEY, $settings);

        return $settings;
    }

    public function isJobEnabled(array $settings, string $jobKey): bool
    {
        $jobs = is_array($settings['jobs'] ?? null) ? $settings['jobs'] : [];
        if (!array_key_exists($jobKey, $jobs)) {
            return in_array($jobKey, self::JOBS, true);
        }

        return (bool) $jobs[$jobKey];
    }

    private function sanitize(array $input, array $current): array
    {
        $defaults = $this->defaults();
        $isForm = !empty($input['_maintenance_form']);

        $settings = [
            'enabled' => $isForm
                ? $this->toBool($input['enabled'] ?? false)
                : (array_key_exists('enabled', $input)
                    ? $this->toBool($input['enabled'])
                    : (bool) ($current['enabled'] ?? $defaults['enabled'])),
            'frequency' => $this->sanitizeFrequency($input['frequency'] ?? ($current['frequency'] ?? $defaults['frequency'])),
            'time' => $this->sanitizeTime($input['time'] ?? ($current['time'] ?? $defaults['time'])),
            'days' => array_key_exists('days', $input)
                ? $this->sanitizeDays($input['days'])
                : $this->sanitizeDays($current['days'] ?? $defaults['days']),
            'weekday' => $this->sanitizeWeekday($input['weekday'] ?? ($current['weekday'] ?? $defaults['weekday'])),
            'timezone' => $this->sanitizeTimezone($input['timezone'] ?? ($current['timezone'] ?? $defaults['timezone'])),
            'pause_downloads' => $isForm
                ? $this->toBool($input['pause_downloads'] ?? false)
                : (array_key_exists('pause_downloads', $input)
                    ? $this->toBool($input['pause_downloads'])
                    : (bool) ($current['pause_downloads'] ?? $defaults['pause_downloads'])),
            'run_retention_days' => $this->sanitizeRetention($input['run_retention_days'] ?? ($current['run_retention_days'] ?? $defaults['run_retention_days'])),
            'jobs' => $this->sanitizeJobs($input, $current, $isForm),
            'enabled_at' => $this->sanitizeEnabledAt($input, $current),
        ];

        return $settings;
    }

    private function sanitizeJobs(array $input, array $current, bool $isForm): array
    {
        $defaults = $this->defaults()['jobs'];
        $jobs = is_array($current['jobs'] ?? null) ? $current['jobs'] : $defaults;

        if ($isForm || array_key_exists('jobs', $input)) {
            $posted = is_array($input['jobs'] ?? null) ? $input['jobs'] : [];
            $jobs = [];
            foreach ($defaults as $key => $enabled) {
                $jobs[$key] = array_key_exists($key, $posted) ? $this->toBool($posted[$key]) : false;
            }
        }

        foreach ($defaults as $key => $enabled) {
            if (!array_key_exists($key, $jobs)) {
                $jobs[$key] = $enabled;
            } else {
                $jobs[$key] = (bool) $jobs[$key];
            }
        }

        return $jobs;
    }

    private function sanitizeEnabledAt(array $input, array $current): ?string
    {
        $value = array_key_exists('enabled_at', $input)
            ? $input['enabled_at']
            : ($current['enabled_at'] ?? null);

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format('c');
        } catch (\Throwable) {
            return null;
        }
    }

    private function sanitizeFrequency(mixed $value): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, ['daily', 'weekly'], true) ? $value : 'daily';
    }

    private function sanitizeTime(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($value)) !== 1) {
            return '03:00';
        }

        return trim($value);
    }

    private function sanitizeTimezone(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return 'UTC';
        }

        try {
            new DateTimeZone(trim($value));

            return trim($value);
        } catch (\Throwable) {
            return 'UTC';
        }
    }

    private function sanitizeDays(mixed $value): array
    {
        if (!is_array($value)) {
            return [1, 2, 3, 4, 5, 6, 7];
        }

        $days = [];
        foreach ($value as $day) {
            $day = (int) $day;
            if ($day >= 1 && $day <= 7) {
                $days[$day] = $day;
            }
        }

        if ($days === []) {
            return [1, 2, 3, 4, 5, 6, 7];
        }

        ksort($days);

        return array_values($days);
    }

    private function sanitizeWeekday(mixed $value): int
    {
        $weekday = (int) $value;

        return $weekday >= 1 && $weekday <= 7 ? $weekday : 1;
    }

    private function sanitizeRetention(mixed $value): int
    {
        $days = (int) $value;
        if ($days < 1) {
            return 30;
        }

        return min(365, $days);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }

        return (bool) $value;
    }

    private function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value)) {
                $base[$key] = $this->merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
