<?php

namespace App\Service;

use App\Service\Statistics\StorageUsageProviderInterface;
use DateTimeImmutable;
use Throwable;

class StatisticsService
{
    public const PERIODS = ['7d', '30d', '90d', 'all'];
    public const DEFAULT_PERIOD = '30d';

    private const PERIOD_DAYS = [
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
    ];

    private const PERIOD_LABELS = [
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        'all' => 'All time',
    ];

    private const TYPE_KEYS = ['video', 'audio', 'ebook', 'archive', 'image', 'other'];

    private const TYPE_LABELS = [
        'video' => 'Video',
        'audio' => 'Audio',
        'ebook' => 'eBook',
        'archive' => 'Archive',
        'image' => 'Image',
        'other' => 'Other',
    ];

    private const CATEGORY_KEYS = ['movies', 'tv', 'music', 'other'];

    private const CATEGORY_LABELS = [
        'movies' => 'Movies',
        'tv' => 'TV Shows',
        'music' => 'Music',
        'other' => 'Other',
    ];

    private const QUALITY_KEYS = ['2160p', '1080p', '720p', 'sd'];

    private const QUALITY_LABELS = [
        '2160p' => '2160p / 4K',
        '1080p' => '1080p',
        '720p' => '720p',
        'sd' => 'SD / Unknown',
    ];

    private const KNOWN_STATUSES = ['success', 'warning', 'error', 'canceled', 'failed_requeued'];

    private JsonStorage $storage;
    private MediaTypeHelper $mediaTypeHelper;
    private StorageUsageProviderInterface $storageUsage;
    private DateTimeImmutable $now;

    public function __construct(
        JsonStorage $storage,
        MediaTypeHelper $mediaTypeHelper,
        StorageUsageProviderInterface $storageUsage,
        ?DateTimeImmutable $now = null
    ) {
        $this->storage = $storage;
        $this->mediaTypeHelper = $mediaTypeHelper;
        $this->storageUsage = $storageUsage;
        $this->now = $now ?? new DateTimeImmutable('now');
    }

    public function normalizePeriod(?string $period): string
    {
        $period = is_string($period) ? strtolower(trim($period)) : '';

        return in_array($period, self::PERIODS, true) ? $period : self::DEFAULT_PERIOD;
    }

    public function getNow(): DateTimeImmutable
    {
        return $this->now;
    }

    public function setNow(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    public function getStatistics(string $period = self::DEFAULT_PERIOD): array
    {
        $period = $this->normalizePeriod($period);
        $resolved = $this->resolvePeriod($period);
        $entries = $resolved['entries'];
        $range = $resolved['range'];

        $summary = $this->buildSummary($entries);
        $types = $this->buildTypeBreakdown($entries);
        $quality = $this->buildQualityMix($entries);
        $queue = $this->storage->get('server_queue', []);

        return [
            'period' => $period,
            'period_label' => self::PERIOD_LABELS[$period],
            'periods' => self::PERIOD_LABELS,
            'range' => [
                'start' => $range['start_day']->format('Y-m-d'),
                'end' => $range['end_day']->format('Y-m-d'),
                'days' => $range['days'],
            ],
            'summary' => $summary,
            'daily' => $this->buildDaily($entries, $range),
            'types' => $types,
            'categories' => $this->buildCategoryBreakdown($entries),
            'quality' => $quality,
            'storage' => $this->buildStorageBreakdown($types, $summary),
            'recent' => $this->buildRecent($entries),
            'queue_count' => is_array($queue) ? count($queue) : 0,
            'active_task' => $this->storage->get('active_worker_task', null),
        ];
    }

    public function exportCsv(string $period = self::DEFAULT_PERIOD): string
    {
        $period = $this->normalizePeriod($period);
        $entries = $this->resolvePeriod($period)['entries'];

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, ['date', 'filename', 'type', 'status', 'size_bytes', 'path'], ',', '"', '');

        foreach ($entries as $entry) {
            fputcsv($handle, [
                $this->sanitizeCsvValue($entry['date']),
                $this->sanitizeCsvValue($entry['filename']),
                $this->sanitizeCsvValue($this->entryType($entry)),
                $this->sanitizeCsvValue($entry['status']),
                (string) $entry['size'],
                $this->sanitizeCsvValue($entry['path']),
            ], ',', '"', '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    public function parseSize(mixed $value): int
    {
        if (is_int($value) || is_float($value)) {
            return max(0, (int) round((float) $value));
        }

        if (!is_string($value)) {
            return 0;
        }

        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        if (is_numeric($value)) {
            return max(0, (int) round((float) $value));
        }

        if (preg_match('/^([0-9]+(?:[.,][0-9]+)?)\s*([kmgtpe]?i?b)\.?$/i', $value, $matches) !== 1) {
            return 0;
        }

        $number = (float) str_replace(',', '.', $matches[1]);
        $multiplier = $this->unitMultiplier($matches[2]);

        return max(0, (int) round($number * $multiplier));
    }

    public function parseSpeed(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return max(0.0, (float) $value);
        }

        if (!is_string($value)) {
            return 0.0;
        }

        $value = trim($value);
        if ($value === '') {
            return 0.0;
        }

        if (preg_match('/^([0-9]+(?:[.,][0-9]+)?)\s*([kmgtpe]?i?b)?\s*(?:\/\s*s(?:ec(?:ond)?s?)?)?$/i', $value, $matches) !== 1) {
            return 0.0;
        }

        $number = (float) str_replace(',', '.', $matches[1]);
        $unit = $matches[2] ?? '';

        return max(0.0, $number * $this->unitMultiplier($unit));
    }

    public function parseDuration(mixed $value): int
    {
        if (is_int($value) || is_float($value)) {
            return max(0, (int) round((float) $value));
        }

        if (!is_string($value)) {
            return 0;
        }

        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        if (is_numeric($value)) {
            return max(0, (int) round((float) $value));
        }

        if (preg_match('/^(\d+):(\d{1,2}):(\d{1,2})$/', $value, $matches) === 1) {
            return ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) $matches[3];
        }

        if (preg_match('/^(\d+):(\d{1,2})$/', $value, $matches) === 1) {
            return ((int) $matches[1] * 60) + (int) $matches[2];
        }

        $seconds = 0;
        $matched = false;
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*h/i', $value, $matches) === 1) {
            $seconds += (int) round((float) str_replace(',', '.', $matches[1]) * 3600);
            $matched = true;
        }
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*m(?:in)?/i', $value, $matches) === 1) {
            $seconds += (int) round((float) str_replace(',', '.', $matches[1]) * 60);
            $matched = true;
        }
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*s(?:ec)?/i', $value, $matches) === 1) {
            $seconds += (int) round((float) str_replace(',', '.', $matches[1]));
            $matched = true;
        }

        return $matched ? max(0, $seconds) : 0;
    }

    public function formatBytes(int|float $bytes): string
    {
        $bytes = max(0, (float) $bytes);
        $units = [
            'PB' => 1024 ** 5,
            'TB' => 1024 ** 4,
            'GB' => 1024 ** 3,
            'MB' => 1024 ** 2,
            'KB' => 1024,
        ];

        foreach ($units as $unit => $factor) {
            if ($bytes >= $factor) {
                return round($bytes / $factor, 2) . ' ' . $unit;
            }
        }

        return round($bytes) . ' B';
    }

    public function formatSpeed(float $bytesPerSecond): string
    {
        return $this->formatBytes($bytesPerSecond) . '/s';
    }

    private function resolvePeriod(string $period): array
    {
        $entries = $this->collectEntries();
        $endDay = $this->now->setTime(0, 0, 0);
        $endTs = $this->now->getTimestamp();

        if ($period === 'all') {
            if ($entries === []) {
                $startDay = $endDay;
                $startTs = $endTs;
                $days = 0;
            } else {
                $earliestTs = min(array_column($entries, 'timestamp'));
                $timezone = $this->now->getTimezone();
                $earliestDay = (new DateTimeImmutable('@' . $earliestTs))
                    ->setTimezone($timezone)
                    ->setTime(0, 0, 0);
                $startTs = $earliestTs;
                $minimumDay = $endDay->modify('-364 days');
                $startDay = $earliestDay < $minimumDay ? $minimumDay : $earliestDay;
                $days = $startDay > $endDay ? 0 : (int) $startDay->diff($endDay)->days + 1;
                if ($days === 0) {
                    $startDay = $endDay;
                }
            }
        } else {
            $days = self::PERIOD_DAYS[$period];
            $startDay = $endDay->modify('-' . ($days - 1) . ' days');
            $startTs = $startDay->getTimestamp();
        }

        $filtered = [];
        foreach ($entries as $entry) {
            if ($entry['timestamp'] >= $startTs && $entry['timestamp'] <= $endTs) {
                $filtered[] = $entry;
            }
        }

        return [
            'entries' => $filtered,
            'range' => [
                'start_day' => $startDay,
                'end_day' => $endDay,
                'start_ts' => $startTs,
                'end_ts' => $endTs,
                'days' => $days,
            ],
        ];
    }

    private function collectEntries(): array
    {
        $history = $this->storage->get('history', []);
        if (!is_array($history)) {
            return [];
        }

        $entries = [];
        foreach ($history as $index => $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $date = $this->parseDate($raw['date'] ?? null);
            if ($date === null) {
                continue;
            }

            $entries[] = [
                'index' => (int) $index,
                'timestamp' => $date->getTimestamp(),
                'date' => $date->format('Y-m-d H:i:s'),
                'filename' => $this->stringValue($raw['filename'] ?? $raw['item'] ?? 'Unknown'),
                'type' => $this->stringValue($raw['type'] ?? ''),
                'status' => $this->normalizeStatus($raw['status'] ?? ''),
                'path' => $this->stringValue($raw['path'] ?? ''),
                'size' => $this->entrySize($raw),
                'speed' => $this->parseSpeed($raw['speed'] ?? null),
                'duration' => $this->parseDuration($raw['duration'] ?? null),
                'download_id' => $this->stringValue($raw['download_id'] ?? ''),
            ];
        }

        return $entries;
    }

    private function buildSummary(array $entries): array
    {
        $counts = [
            'success' => 0,
            'warning' => 0,
            'error' => 0,
            'canceled' => 0,
            'failed_requeued' => 0,
            'other' => 0,
        ];
        $totalBytes = 0;
        $speedSum = 0.0;
        $speedCount = 0;
        $duration = 0;

        foreach ($entries as $entry) {
            $status = $entry['status'];
            if (isset($counts[$status]) && $status !== 'other') {
                $counts[$status]++;
            } else {
                $counts['other']++;
            }

            $totalBytes += $entry['size'];
            $duration += $entry['duration'];

            if ($entry['speed'] > 0) {
                $speedSum += $entry['speed'];
                $speedCount++;
            }
        }

        $items = count($entries);
        $successRate = $items > 0 ? $counts['success'] / $items : 0.0;
        $averageSpeed = $speedCount > 0 ? $speedSum / $speedCount : 0.0;
        $averageSize = $items > 0 ? $totalBytes / $items : 0.0;

        return [
            'items' => $items,
            'total_bytes' => $totalBytes,
            'total_human' => $this->formatBytes($totalBytes),
            'success' => $counts['success'],
            'warning' => $counts['warning'],
            'error' => $counts['error'],
            'canceled' => $counts['canceled'],
            'failed_requeued' => $counts['failed_requeued'],
            'other' => $counts['other'],
            'success_rate' => $successRate,
            'success_rate_percent' => round($successRate * 100, 1),
            'average_speed' => $averageSpeed,
            'average_speed_human' => $this->formatSpeed($averageSpeed),
            'total_duration' => $duration,
            'average_size' => $averageSize,
            'average_size_human' => $this->formatBytes($averageSize),
        ];
    }

    private function buildDaily(array $entries, array $range): array
    {
        $days = (int) $range['days'];
        if ($days <= 0) {
            return [];
        }

        $startKey = $range['start_day']->format('Y-m-d');
        $endKey = $range['end_day']->format('Y-m-d');
        $buckets = [];

        $cursor = $range['start_day'];
        for ($i = 0; $i < $days; $i++) {
            $key = $cursor->format('Y-m-d');
            $buckets[$key] = [
                'date' => $key,
                'items' => 0,
                'bytes' => 0,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        foreach ($entries as $entry) {
            $key = substr($entry['date'], 0, 10);
            if ($key < $startKey || $key > $endKey || !isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['items']++;
            $buckets[$key]['bytes'] += $entry['size'];
        }

        $maxItems = 0;
        $maxBytes = 0;
        foreach ($buckets as $bucket) {
            $maxItems = max($maxItems, $bucket['items']);
            $maxBytes = max($maxBytes, $bucket['bytes']);
        }

        $daily = [];
        foreach ($buckets as $bucket) {
            $bucket['bytes_human'] = $this->formatBytes($bucket['bytes']);
            $bucket['height'] = $maxItems > 0 ? max(2, (int) round($bucket['items'] / $maxItems * 100)) : 0;
            $bucket['bytes_height'] = $maxBytes > 0 ? max(2, (int) round($bucket['bytes'] / $maxBytes * 100)) : 0;
            $daily[] = $bucket;
        }

        return $daily;
    }

    private function buildTypeBreakdown(array $entries): array
    {
        $buckets = [];
        foreach (self::TYPE_KEYS as $key) {
            $buckets[$key] = [
                'key' => $key,
                'label' => self::TYPE_LABELS[$key],
                'items' => 0,
                'bytes' => 0,
            ];
        }

        $totalBytes = 0;
        foreach ($entries as $entry) {
            $key = $this->entryType($entry);
            $buckets[$key]['items']++;
            $buckets[$key]['bytes'] += $entry['size'];
            $totalBytes += $entry['size'];
        }

        $items = count($entries);
        foreach ($buckets as &$bucket) {
            $bucket['bytes_human'] = $this->formatBytes($bucket['bytes']);
            $bucket['percent_items'] = $items > 0 ? round($bucket['items'] / $items * 100, 1) : 0.0;
            $bucket['percent_bytes'] = $totalBytes > 0 ? round($bucket['bytes'] / $totalBytes * 100, 1) : 0.0;
        }
        unset($bucket);

        return $buckets;
    }

    private function buildCategoryBreakdown(array $entries): array
    {
        $buckets = [];
        foreach (self::CATEGORY_KEYS as $key) {
            $buckets[$key] = [
                'key' => $key,
                'label' => self::CATEGORY_LABELS[$key],
                'items' => 0,
                'bytes' => 0,
            ];
        }

        foreach ($entries as $entry) {
            $key = $this->entryCategory($entry);
            $buckets[$key]['items']++;
            $buckets[$key]['bytes'] += $entry['size'];
        }

        $items = count($entries);
        foreach ($buckets as &$bucket) {
            $bucket['bytes_human'] = $this->formatBytes($bucket['bytes']);
            $bucket['percent'] = $items > 0 ? round($bucket['items'] / $items * 100, 1) : 0.0;
        }
        unset($bucket);

        return $buckets;
    }

    private function buildQualityMix(array $entries): array
    {
        $buckets = [];
        foreach (self::QUALITY_KEYS as $key) {
            $buckets[$key] = [
                'key' => $key,
                'label' => self::QUALITY_LABELS[$key],
                'items' => 0,
            ];
        }

        foreach ($entries as $entry) {
            $buckets[$this->entryQuality($entry)]['items']++;
        }

        $items = count($entries);
        foreach ($buckets as &$bucket) {
            $bucket['percent'] = $items > 0 ? round($bucket['items'] / $items * 100, 1) : 0.0;
        }
        unset($bucket);

        return $buckets;
    }

    private function buildStorageBreakdown(array $types, array $summary): array
    {
        $config = $this->storage->get('config', []);
        if (!is_array($config)) {
            $config = [];
        }

        $paths = [];
        foreach (['default_path', 'music_root_path', 'music_library_path'] as $key) {
            $path = $config[$key] ?? '';
            if (!is_string($path)) {
                continue;
            }
            $path = trim($path);
            if ($path !== '') {
                $paths[$path] = true;
            }
        }

        $volumes = [];
        $unavailable = [];
        $total = 0;
        $free = 0;
        $used = 0;

        foreach (array_keys($paths) as $path) {
            try {
                $usage = $this->storageUsage->getUsage($path);
            } catch (Throwable) {
                $usage = null;
            }

            if (!is_array($usage) || !isset($usage['total'], $usage['free'])
                || !is_numeric($usage['total']) || !is_numeric($usage['free'])) {
                $unavailable[] = $path;
                continue;
            }

            $volumeTotal = max(0, (int) $usage['total']);
            $volumeFree = max(0, (int) $usage['free']);
            $volumeUsed = isset($usage['used']) && is_numeric($usage['used'])
                ? max(0, (int) $usage['used'])
                : max(0, $volumeTotal - $volumeFree);

            $volumes[] = [
                'path' => $path,
                'total' => $volumeTotal,
                'free' => $volumeFree,
                'used' => $volumeUsed,
                'total_human' => $this->formatBytes($volumeTotal),
                'free_human' => $this->formatBytes($volumeFree),
                'used_human' => $this->formatBytes($volumeUsed),
                'used_percent' => $volumeTotal > 0 ? round($volumeUsed / $volumeTotal * 100, 1) : 0.0,
            ];

            $total += $volumeTotal;
            $free += $volumeFree;
            $used += $volumeUsed;
        }

        $byType = [];
        foreach ($types as $type) {
            $byType[] = [
                'key' => $type['key'],
                'label' => $type['label'],
                'bytes' => $type['bytes'],
                'bytes_human' => $type['bytes_human'],
            ];
        }

        return [
            'volumes' => $volumes,
            'unavailable_paths' => $unavailable,
            'total' => $total,
            'free' => $free,
            'used' => $used,
            'total_human' => $this->formatBytes($total),
            'free_human' => $this->formatBytes($free),
            'used_human' => $this->formatBytes($used),
            'history_bytes' => $summary['total_bytes'],
            'history_human' => $summary['total_human'],
            'by_type' => $byType,
            'has_volumes' => $volumes !== [],
        ];
    }

    private function buildRecent(array $entries, int $limit = 10): array
    {
        usort($entries, static function (array $a, array $b): int {
            return $b['timestamp'] <=> $a['timestamp'] ?: $b['index'] <=> $a['index'];
        });

        $recent = [];
        foreach (array_slice($entries, 0, $limit) as $entry) {
            $recent[] = [
                'date' => $entry['date'],
                'filename' => $entry['filename'],
                'type' => $this->entryType($entry),
                'type_label' => self::TYPE_LABELS[$this->entryType($entry)],
                'status' => $entry['status'],
                'size' => $entry['size'],
                'size_human' => $this->formatBytes($entry['size']),
                'path' => $entry['path'],
                'speed' => $entry['speed'],
                'speed_human' => $entry['speed'] > 0 ? $this->formatSpeed($entry['speed']) : '--',
                'duration' => $entry['duration'],
                'download_id' => $entry['download_id'],
            ];
        }

        return $recent;
    }

    private function entrySize(array $raw): int
    {
        foreach (['actual_size', 'size'] as $key) {
            if (!array_key_exists($key, $raw) || $raw[$key] === null || $raw[$key] === '') {
                continue;
            }

            $value = $raw[$key];
            if (is_int($value) || is_float($value)) {
                return max(0, (int) round((float) $value));
            }

            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value === '') {
                continue;
            }

            if (is_numeric($value)) {
                return max(0, (int) round((float) $value));
            }

            if (preg_match('/^[0-9]+(?:[.,][0-9]+)?\s*[kmgtpe]?i?b\.?$/i', $value) === 1) {
                return $this->parseSize($value);
            }
        }

        return 0;
    }

    private function entryType(array $entry): string
    {
        $raw = strtolower(trim($entry['type']));
        $aliases = [
            'video' => 'video',
            'movie' => 'video',
            'movies' => 'video',
            'audio' => 'audio',
            'music' => 'audio',
            'ebook' => 'ebook',
            'text' => 'other',
            'archive' => 'archive',
            'image' => 'image',
            'photo' => 'image',
            'iso' => 'image',
            'other' => 'other',
        ];

        if (isset($aliases[$raw])) {
            return $aliases[$raw];
        }

        $detected = strtolower($this->mediaTypeHelper->getType($entry['filename']));

        return $aliases[$detected] ?? 'other';
    }

    private function entryCategory(array $entry): string
    {
        $type = $this->entryType($entry);
        if ($type === 'audio') {
            return 'music';
        }

        $path = strtolower($entry['path']);
        $filename = strtolower($entry['filename']);

        if (preg_match('/(^|[^a-z])(tv|tvshows|tv shows|series|shows?)([^a-z]|$)/', $path) === 1
            || preg_match('/s\d{1,2}e\d{1,2}/i', $filename) === 1) {
            return 'tv';
        }

        if (str_contains($path, 'movie') || str_contains($path, 'film')) {
            return 'movies';
        }

        return $type === 'video' ? 'movies' : 'other';
    }

    private function entryQuality(array $entry): string
    {
        if (preg_match('/(?:2160p|4k|uhd)/i', $entry['filename']) === 1) {
            return '2160p';
        }
        if (preg_match('/1080p/i', $entry['filename']) === 1) {
            return '1080p';
        }
        if (preg_match('/720p/i', $entry['filename']) === 1) {
            return '720p';
        }

        return 'sd';
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (is_int($value)) {
            try {
                return (new DateTimeImmutable('@' . $value))->setTimezone($this->now->getTimezone());
            } catch (Throwable) {
                return null;
            }
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, $this->now->getTimezone());
            if (!$date instanceof DateTimeImmutable) {
                continue;
            }

            $errors = DateTimeImmutable::getLastErrors();
            if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                return $date;
            }
        }

        try {
            return new DateTimeImmutable($value, $this->now->getTimezone());
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeStatus(mixed $status): string
    {
        $status = strtolower(trim($this->stringValue($status)));

        return in_array($status, self::KNOWN_STATUSES, true) ? $status : 'unknown';
    }

    private function sanitizeCsvValue(mixed $value): string
    {
        $value = $this->stringValue($value);
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }

        return $value;
    }

    private function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    private function unitMultiplier(string $unit): float
    {
        $unit = strtoupper(trim($unit));
        if ($unit === '') {
            return 1.0;
        }

        $unit = rtrim($unit, 'B');
        if ($unit === '') {
            return 1.0;
        }

        $unit = rtrim($unit, 'I');
        $powers = [
            'K' => 1,
            'M' => 2,
            'G' => 3,
            'T' => 4,
            'P' => 5,
            'E' => 6,
        ];

        if (!isset($powers[$unit])) {
            return 1.0;
        }

        return 1024 ** $powers[$unit];
    }
}
