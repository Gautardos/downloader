<?php

namespace App\Service\Integrity;

use App\Service\Automation\AutomationSettings;
use App\Service\JsonStorage;
use App\Service\MediaTypeHelper;
use DateTimeImmutable;
use DateTimeZone;

class VideoIntegrityService
{
    private JsonStorage $storage;
    private IntegrityStore $store;
    private IntegritySettings $settings;
    private FileIntegrityChecker $checker;
    private ?AutomationSettings $automationSettings;
    private MediaTypeHelper $mediaTypeHelper;

    public function __construct(
        JsonStorage $storage,
        IntegrityStore $store,
        IntegritySettings $settings,
        FileIntegrityChecker $checker,
        ?AutomationSettings $automationSettings = null,
        ?MediaTypeHelper $mediaTypeHelper = null
    ) {
        $this->storage = $storage;
        $this->store = $store;
        $this->settings = $settings;
        $this->checker = $checker;
        $this->automationSettings = $automationSettings;
        $this->mediaTypeHelper = $mediaTypeHelper ?? new MediaTypeHelper();
    }

    public function allowedRoots(): array
    {
        $roots = [];
        $config = $this->storage->get('config', []);
        if (is_array($config) && !empty($config['default_path']) && is_string($config['default_path'])) {
            $roots[] = $config['default_path'];
        }

        if ($this->automationSettings !== null) {
            foreach ($this->automationSettings->getRoots() as $root) {
                if (is_array($root) && !empty($root['path']) && is_string($root['path'])) {
                    $roots[] = $root['path'];
                }
            }
        }

        return array_values(array_unique(array_filter($roots, static fn (string $root): bool => trim($root) !== '')));
    }

    public function scan(?int $limit = null, bool $deep = false, bool $persist = true): array
    {
        $policy = $this->settings->all();
        if ($limit === null || $limit <= 0) {
            $limit = (int) $policy['scan_limit'];
        }
        $limit = max(1, min(2000, $limit));

        $roots = $this->allowedRoots();
        $history = $this->storage->get('history', []);
        if (!is_array($history)) {
            $history = [];
        }

        $summary = $this->emptySummary();
        $checks = [];
        $seen = [];
        $seenPaths = [];
        $checked = 0;

        foreach (array_reverse($history) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $filename = trim((string) ($entry['filename'] ?? ''));
            if ($filename === '' || $this->mediaTypeHelper->getType($filename) !== MediaTypeHelper::TYPE_VIDEO) {
                continue;
            }

            $path = trim((string) ($entry['path'] ?? ''));
            $fullPath = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . $filename;
            $downloadId = trim((string) ($entry['download_id'] ?? ''));
            $checkKey = $downloadId !== '' ? 'download:' . $downloadId : 'path:' . $fullPath;

            if (isset($seen[$checkKey]) || isset($seenPaths[$fullPath])) {
                continue;
            }
            $seen[$checkKey] = true;
            $seenPaths[$fullPath] = true;

            if ($checked >= $limit) {
                break;
            }
            $checked++;

            $expected = (int) ($entry['actual_size'] ?? 0);
            if ($expected <= 0) {
                $expected = (int) ($entry['size'] ?? 0);
            }

            $source = $entry['source_url'] ?? ($entry['original_url'] ?? null);
            $source = is_string($source) && trim($source) !== '' ? trim($source) : null;
            $sourceAvailable = $source !== null && preg_match('#^https?://#i', $source) === 1;

            $result = $this->checker->check(
                $fullPath,
                $roots,
                max(0, $expected),
                $deep,
                (int) $policy['small_file_threshold']
            );

            $extra = array_merge((array) ($result['extra'] ?? []), [
                'source_available' => $sourceAvailable,
                'source_display' => $sourceAvailable ? self::safeSource($source) : null,
                'source_kind' => $source === null ? 'unknown' : ($sourceAvailable ? 'http' : 'non_http'),
            ]);

            $record = [
                'check_key' => $checkKey,
                'path' => $fullPath,
                'download_id' => $downloadId,
                'filename' => $filename,
                'type' => MediaTypeHelper::TYPE_VIDEO,
                'expected_size' => max(0, $expected),
                'actual_size' => (int) ($result['actual_size'] ?? 0),
                'status' => (string) $result['status'],
                'reason' => (string) ($result['reason'] ?? ''),
                'last_checked_at' => $this->utcNow(),
                'extra' => $extra,
            ];

            if ($persist) {
                $record['id'] = $this->store->recordCheck($record);
            }

            $checks[] = array_merge($record, [
                'extra' => $extra,
                'source_available' => $sourceAvailable,
                'source_display' => $extra['source_display'],
            ]);

            if (array_key_exists($result['status'], $summary)) {
                $summary[$result['status']]++;
            }
            $summary['total']++;
        }

        if ($persist) {
            $this->store->purgeOldChecks((int) $policy['retention_days']);
        }

        return [
            'success' => true,
            'checked' => $checked,
            'limit' => $limit,
            'deep' => $deep,
            'persisted' => $persist,
            'summary' => $summary,
            'checks' => $checks,
        ];
    }

    public function listChecks(array $filters = [], ?int $limit = null): array
    {
        if ($limit === null || $limit <= 0) {
            $limit = (int) $this->settings->get('scan_limit', 200);
        }

        $checks = $this->store->getChecks($filters, $limit);

        return array_map(static function (array $check): array {
            $extra = is_array($check['extra'] ?? null) ? $check['extra'] : [];
            $check['source_available'] = (bool) ($extra['source_available'] ?? false);
            $check['source_display'] = $extra['source_display'] ?? null;
            $check['source_kind'] = $extra['source_kind'] ?? 'unknown';

            return $check;
        }, $checks);
    }

    public function summary(): array
    {
        return $this->store->getSummary();
    }

    public function count(): int
    {
        return $this->store->countChecks();
    }

    public function findCheck(string $identifier): ?array
    {
        return $this->store->findCheck($identifier);
    }

    public function policy(): array
    {
        return $this->settings->all();
    }

    public static function safeSource(?string $source): ?string
    {
        if ($source === null || trim($source) === '') {
            return null;
        }

        $parts = parse_url(trim($source));
        if (!is_array($parts) || empty($parts['host'])) {
            return 'source hidden';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));

        return $scheme . '://' . $parts['host'];
    }

    private function emptySummary(): array
    {
        return [
            'total' => 0,
            'healthy' => 0,
            'incomplete' => 0,
            'corrupted' => 0,
            'missing' => 0,
            'outside_root' => 0,
            'unsupported' => 0,
            'requeued' => 0,
        ];
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
