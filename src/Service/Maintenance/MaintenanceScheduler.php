<?php

namespace App\Service\Maintenance;

use DateTimeImmutable;
use DateTimeZone;

class MaintenanceScheduler
{
    public function nextRunAt(array $settings, ?DateTimeImmutable $now = null): ?DateTimeImmutable
    {
        if (empty($settings['enabled'])) {
            return null;
        }

        $now = $now ?? new DateTimeImmutable('now');
        $timezone = $this->timezone($settings['timezone'] ?? 'UTC');
        $localNow = $now->setTimezone($timezone);
        [$hour, $minute] = $this->parseTime($settings['time'] ?? '03:00');

        if (($settings['frequency'] ?? 'daily') === 'weekly') {
            return $this->nextWeekly($settings, $localNow, $timezone, $hour, $minute);
        }

        return $this->nextDaily($settings, $localNow, $timezone, $hour, $minute);
    }

    public function nextRunAfter(array $settings, DateTimeImmutable $after): ?DateTimeImmutable
    {
        return $this->nextRunAt($settings, $after);
    }

    public function isDue(array $settings, ?DateTimeImmutable $lastStartedAt, DateTimeImmutable $now): bool
    {
        if (empty($settings['enabled'])) {
            return false;
        }

        $base = $lastStartedAt;
        if ($base === null) {
            $enabledAt = $settings['enabled_at'] ?? null;
            if (is_string($enabledAt) && $enabledAt !== '') {
                try {
                    $base = new DateTimeImmutable($enabledAt);
                } catch (\Throwable) {
                    $base = null;
                }
            }
        }

        if ($base === null) {
            $base = $now->modify('-1 day');
        }

        $next = $this->nextRunAfter($settings, $base);

        return $next !== null && $next <= $now;
    }

    private function nextDaily(
        array $settings,
        DateTimeImmutable $localNow,
        DateTimeZone $timezone,
        int $hour,
        int $minute
    ): ?DateTimeImmutable {
        $days = [];
        foreach ((array) ($settings['days'] ?? []) as $day) {
            $day = (int) $day;
            if ($day >= 1 && $day <= 7) {
                $days[$day] = true;
            }
        }
        if ($days === []) {
            $days = [1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 6 => true, 7 => true];
        }

        $midnight = $localNow->setTime(0, 0, 0);
        for ($offset = 0; $offset <= 7; $offset++) {
            $date = $offset === 0 ? $midnight : $midnight->modify('+' . $offset . ' days');
            if (!isset($days[(int) $date->format('N')])) {
                continue;
            }

            $candidate = $this->atTime($date, $hour, $minute, $timezone);
            if ($candidate > $localNow) {
                return $candidate;
            }
        }

        return null;
    }

    private function nextWeekly(
        array $settings,
        DateTimeImmutable $localNow,
        DateTimeZone $timezone,
        int $hour,
        int $minute
    ): ?DateTimeImmutable {
        $target = (int) ($settings['weekday'] ?? 1);
        if ($target < 1 || $target > 7) {
            $target = 1;
        }

        $current = (int) $localNow->format('N');
        $offset = ($target - $current + 7) % 7;
        $midnight = $localNow->setTime(0, 0, 0);
        $date = $offset === 0 ? $midnight : $midnight->modify('+' . $offset . ' days');
        $candidate = $this->atTime($date, $hour, $minute, $timezone);

        if ($candidate <= $localNow) {
            $candidate = $this->atTime($date->modify('+7 days'), $hour, $minute, $timezone);
        }

        return $candidate;
    }

    private function atTime(DateTimeImmutable $date, int $hour, int $minute, DateTimeZone $timezone): DateTimeImmutable
    {
        return new DateTimeImmutable(
            $date->format('Y-m-d') . ' ' . sprintf('%02d:%02d:00', $hour, $minute),
            $timezone
        );
    }

    private function timezone(mixed $value): DateTimeZone
    {
        if (!is_string($value) || trim($value) === '') {
            return new DateTimeZone('UTC');
        }

        try {
            return new DateTimeZone(trim($value));
        } catch (\Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    private function parseTime(mixed $value): array
    {
        if (!is_string($value) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($value)) !== 1) {
            return [3, 0];
        }

        [$hour, $minute] = explode(':', trim($value));

        return [(int) $hour, (int) $minute];
    }
}
