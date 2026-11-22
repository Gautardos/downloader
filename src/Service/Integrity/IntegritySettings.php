<?php

namespace App\Service\Integrity;

class IntegritySettings
{
    public const SETTINGS_KEY = 'integrity_settings';

    private IntegrityStore $store;

    public function __construct(IntegrityStore $store)
    {
        $this->store = $store;
    }

    public function defaults(): array
    {
        return [
            'max_requeue_attempts' => 3,
            'auto_requeue' => false,
            'deep_check' => false,
            'small_file_threshold' => 1024 * 1024,
            'scan_limit' => 200,
            'requeue_bulk_limit' => 10,
            'retention_days' => 90,
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
        $settings = $this->all();
        $defaults = $this->defaults();

        if (array_key_exists('max_requeue_attempts', $input)) {
            $settings['max_requeue_attempts'] = $this->clampInt($input['max_requeue_attempts'], 0, 20, $defaults['max_requeue_attempts']);
        }
        if (array_key_exists('auto_requeue', $input)) {
            $settings['auto_requeue'] = $this->toBool($input['auto_requeue']);
        }
        if (array_key_exists('deep_check', $input)) {
            $settings['deep_check'] = $this->toBool($input['deep_check']);
        }
        if (array_key_exists('small_file_threshold', $input)) {
            $settings['small_file_threshold'] = $this->clampInt($input['small_file_threshold'], 0, 10 * 1024 * 1024 * 1024, $defaults['small_file_threshold']);
        }
        if (array_key_exists('scan_limit', $input)) {
            $settings['scan_limit'] = $this->clampInt($input['scan_limit'], 1, 2000, $defaults['scan_limit']);
        }
        if (array_key_exists('requeue_bulk_limit', $input)) {
            $settings['requeue_bulk_limit'] = $this->clampInt($input['requeue_bulk_limit'], 1, 200, $defaults['requeue_bulk_limit']);
        }
        if (array_key_exists('retention_days', $input)) {
            $settings['retention_days'] = $this->clampInt($input['retention_days'], 1, 3650, $defaults['retention_days']);
        }

        $this->store->setSetting(self::SETTINGS_KEY, $settings);

        return $settings;
    }

    private function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        $int = is_numeric($value) ? (int) $value : $default;
        if ($int < $min) {
            return $min;
        }
        if ($int > $max) {
            return $max;
        }

        return $int;
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
