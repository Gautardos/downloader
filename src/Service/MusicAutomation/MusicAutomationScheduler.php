<?php

namespace App\Service\MusicAutomation;

use DateTimeImmutable;
use DateTimeZone;

class MusicAutomationScheduler
{
    public const FREQUENCIES = ['6h', '12h', 'daily', 'weekly'];
    public const DEFAULT_TIMEZONE = 'UTC';
    public const DEFAULT_RUN_TIME = '03:00';

    public function normalizeFrequency(?string $frequency): string
    {
        $frequency = strtolower(trim((string) $frequency));
        $aliases = [
            '6hours' => '6h',
            '6' => '6h',
            '12hours' => '12h',
            '12' => '12h',
            'day' => 'daily',
            'week' => 'weekly',
        ];
        $frequency = $aliases[$frequency] ?? $frequency;

        return in_array($frequency, self::FREQUENCIES, true) ? $frequency : 'daily';
    }

    public function normalizeTimezone(?string $timezone): string
    {
        $timezone = trim((string) $timezone);
        if ($timezone === '') {
            return self::DEFAULT_TIMEZONE;
        }

        try {
            new DateTimeZone($timezone);
            return $timezone;
        } catch (\Exception) {
            return self::DEFAULT_TIMEZONE;
        }
    }

    public function normalizeRunTime(?string $runTime): string
    {
        $runTime = trim((string) $runTime);
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $runTime, $matches)) {
            return str_pad((string) (int) $matches[1], 2, '0', STR_PAD_LEFT) . ':' . $matches[2];
        }

        return self::DEFAULT_RUN_TIME;
    }

    public function normalizeWeekday(mixed $weekday): int
    {
        $weekday = (int) $weekday;
        if ($weekday < 1 || $weekday > 7) {
            return 1;
        }

        return $weekday;
    }

    public function computeNextRun(array $rule, ?DateTimeImmutable $from = null): string
    {
        $from = ($from ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $timezone = new DateTimeZone($this->normalizeTimezone($rule['timezone'] ?? null));
        $localFrom = $from->setTimezone($timezone);
        $frequency = $this->normalizeFrequency($rule['frequency'] ?? null);

        if ($frequency === '6h' || $frequency === '12h') {
            $hours = $frequency === '6h' ? 6 : 12;
            $interval = new \DateInterval('PT' . $hours . 'H');

            $base = $this->parseUtc($rule['last_run_at'] ?? null)
                ?? $this->parseUtc($rule['created_at'] ?? null)
                ?? $from;
            $next = $base->setTimezone($timezone);
            do {
                $next = $next->add($interval);
            } while ($next <= $localFrom);

            return $next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        [$hour, $minute] = array_map('intval', explode(':', $this->normalizeRunTime($rule['run_time'] ?? null)));

        if ($frequency === 'weekly') {
            $weekday = $this->normalizeWeekday($rule['weekday'] ?? 1);
            $candidate = $localFrom->setTime($hour, $minute, 0);
            $delta = ($weekday - (int) $localFrom->format('N') + 7) % 7;
            if ($delta > 0) {
                $candidate = $candidate->modify('+' . $delta . ' days');
            }
            if ($candidate <= $localFrom) {
                $candidate = $candidate->modify('+7 days');
            }

            return $candidate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        $candidate = $localFrom->setTime($hour, $minute, 0);
        if ($candidate <= $localFrom) {
            $candidate = $candidate->modify('+1 day');
        }

        return $candidate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function isDue(array $rule, ?DateTimeImmutable $now = null): bool
    {
        if (empty($rule['enabled'])) {
            return false;
        }

        $next = $this->parseUtc($rule['next_run_at'] ?? null);
        if ($next === null) {
            return false;
        }

        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));

        return $next <= $now;
    }

    public function describeFrequency(array $rule): string
    {
        $frequency = $this->normalizeFrequency($rule['frequency'] ?? null);
        if ($frequency === '6h' || $frequency === '12h') {
            return 'Every ' . $frequency;
        }

        $time = $this->normalizeRunTime($rule['run_time'] ?? null);
        if ($frequency === 'weekly') {
            $labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
            $weekday = $labels[$this->normalizeWeekday($rule['weekday'] ?? 1)] ?? 'Mon';

            return 'Weekly on ' . $weekday . ' at ' . $time;
        }

        return 'Daily at ' . $time;
    }

    private function parseUtc(?string $value): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
