<?php

namespace App\Service\Integrity;

use App\Service\Automation\AutomationSettings;
use App\Service\Automation\AutomationStore;
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
    private ?AutomationStore $automationStore;
    private ?AutomationSettings $automationSettings;
    private MediaTypeHelper $mediaTypeHelper;

    public function __construct(
        JsonStorage $storage,
        IntegrityStore $store,
        IntegritySettings $settings,
        FileIntegrityChecker $checker,
        ?AutomationStore $automationStore = null,
        ?AutomationSettings $automationSettings = null,
        ?MediaTypeHelper $mediaTypeHelper = null
    ) {
        $this->storage = $storage;
        $this->store = $store;
        $this->settings = $settings;
        $this->checker = $checker;
        $this->automationStore = $automationStore;
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

    public function scan(?int $limit = null, bool $deep = false, bool $persist = true, array $filters = []): array
    {
        $policy = $this->settings->all();
        if ($limit === null || $limit <= 0) {
            $limit = (int) $policy['scan_limit'];
        }
        $limit = max(1, min(2000, $limit));

        $includeLibrary = $this->toBool($filters['include_library'] ?? true);
        $hasTarget = $includeLibrary && $this->hasTargetFilters($filters);
        $candidates = $this->collectCandidates($filters, $includeLibrary, $hasTarget, $limit);

        $roots = $this->allowedRoots();
        $summary = $this->emptySummary();
        $checks = [];
        $checked = 0;

        foreach ($candidates as $candidate) {
            if ($checked >= $limit) {
                break;
            }
            $checked++;

            $fullPath = (string) $candidate['path'];
            $filename = (string) $candidate['filename'];
            $downloadId = trim((string) ($candidate['download_id'] ?? ''));
            $historyEntry = is_array($candidate['history_entry'] ?? null) ? $candidate['history_entry'] : null;
            $libraryEntry = is_array($candidate['library_entry'] ?? null) ? $candidate['library_entry'] : null;
            $existing = $this->store->findCheckByPath($fullPath);
            $checkKey = (string) ($existing['check_key'] ?? ($downloadId !== '' ? 'download:' . $downloadId : 'path:' . $fullPath));

            $expected = max(0, (int) ($candidate['expected_size'] ?? 0));
            if ($libraryEntry !== null && $historyEntry === null && $existing !== null) {
                $expected = max($expected, (int) ($existing['expected_size'] ?? 0));
            }

            $source = null;
            if ($historyEntry !== null) {
                $source = $historyEntry['source_url'] ?? ($historyEntry['original_url'] ?? null);
            }
            $source = is_string($source) && trim($source) !== '' ? trim($source) : null;
            $sourceAvailable = $source !== null && preg_match('#^https?://#i', $source) === 1;
            $origin = $historyEntry !== null ? 'history' : 'library';

            $result = $this->checker->check(
                $fullPath,
                $roots,
                $expected,
                $deep,
                (int) $policy['small_file_threshold']
            );

            $extra = array_merge((array) ($result['extra'] ?? []), [
                'origin' => $origin,
                'in_library' => $libraryEntry !== null,
                'source_available' => $sourceAvailable,
                'source_display' => $sourceAvailable ? self::safeSource($source) : null,
                'source_kind' => $source === null ? 'unknown' : ($sourceAvailable ? 'http' : 'non_http'),
            ]);
            if ($libraryEntry !== null) {
                $extra = array_merge($extra, $this->libraryExtra($libraryEntry));
            }

            $record = [
                'check_key' => $checkKey,
                'path' => $fullPath,
                'download_id' => $downloadId,
                'filename' => $filename,
                'type' => MediaTypeHelper::TYPE_VIDEO,
                'expected_size' => $expected,
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
                'origin' => $origin,
                'in_library' => $libraryEntry !== null,
                'source_available' => $sourceAvailable,
                'source_display' => $extra['source_display'],
                'source_kind' => $extra['source_kind'],
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
            'library_included' => $includeLibrary,
            'summary' => $summary,
            'checks' => $checks,
        ];
    }

    private function collectCandidates(array $filters, bool $includeLibrary, bool $hasTarget, int $limit): array
    {
        $history = $this->storage->get('history', []);
        $history = is_array($history) ? $history : [];

        $candidates = [];
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
            if (isset($candidates[$fullPath])) {
                continue;
            }

            $expected = (int) ($entry['actual_size'] ?? 0);
            if ($expected <= 0) {
                $expected = (int) ($entry['size'] ?? 0);
            }

            $candidates[$fullPath] = [
                'path' => $fullPath,
                'filename' => $filename,
                'download_id' => trim((string) ($entry['download_id'] ?? '')),
                'expected_size' => max(0, $expected),
                'history_entry' => $entry,
                'library_entry' => null,
            ];
        }

        $libraryEntries = [];
        if ($includeLibrary && $this->automationStore !== null) {
            $libraryEntries = $this->automationStore->getActiveLibraryEntriesForIntegrity($filters, $limit);
        }

        if ($hasTarget) {
            $targetPaths = [];
            foreach ($libraryEntries as $entry) {
                $targetPaths[(string) ($entry['path'] ?? '')] = true;
            }
            $candidates = array_filter(
                $candidates,
                static fn (array $candidate): bool => isset($targetPaths[(string) $candidate['path']])
            );
        }

        foreach ($libraryEntries as $entry) {
            $fullPath = trim((string) ($entry['path'] ?? ''));
            if ($fullPath === '') {
                continue;
            }

            if (isset($candidates[$fullPath])) {
                $candidates[$fullPath]['library_entry'] = $entry;
                if ((int) ($candidates[$fullPath]['expected_size'] ?? 0) <= 0) {
                    $candidates[$fullPath]['expected_size'] = max(0, (int) ($entry['size'] ?? 0));
                }
                continue;
            }

            $filename = trim((string) ($entry['filename'] ?? basename($fullPath)));
            if ($filename === '' || $this->mediaTypeHelper->getType($filename) !== MediaTypeHelper::TYPE_VIDEO) {
                continue;
            }

            $candidates[$fullPath] = [
                'path' => $fullPath,
                'filename' => $filename,
                'download_id' => '',
                'expected_size' => max(0, (int) ($entry['size'] ?? 0)),
                'history_entry' => null,
                'library_entry' => $entry,
            ];
        }

        return array_values($candidates);
    }

    private function libraryExtra(array $entry): array
    {
        return [
            'library_entry_id' => isset($entry['id']) ? (int) $entry['id'] : null,
            'media_key' => isset($entry['media_key']) ? (string) $entry['media_key'] : null,
            'root_key' => isset($entry['root_key']) ? (string) $entry['root_key'] : null,
            'show_folder' => isset($entry['show_folder']) ? (string) $entry['show_folder'] : null,
            'media_type' => isset($entry['media_type']) ? (string) $entry['media_type'] : null,
            'tmdb_id' => isset($entry['tmdb_id']) && $entry['tmdb_id'] !== null ? (int) $entry['tmdb_id'] : null,
            'season' => isset($entry['season']) && $entry['season'] !== null ? (int) $entry['season'] : null,
            'episode' => isset($entry['episode']) && $entry['episode'] !== null ? (int) $entry['episode'] : null,
            'library_size' => isset($entry['size']) ? (int) $entry['size'] : 0,
        ];
    }

    private function hasTargetFilters(array $filters): bool
    {
        foreach (['show_folder', 'path_prefix', 'root_key', 'media_key'] as $key) {
            if (trim((string) ($filters[$key] ?? '')) !== '') {
                return true;
            }
        }
        if (isset($filters['tmdb_id']) && is_numeric($filters['tmdb_id']) && (int) $filters['tmdb_id'] > 0) {
            return true;
        }

        return in_array(trim((string) ($filters['media_type'] ?? '')), ['movie', 'series', 'anime'], true);
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
            $check['origin'] = (string) ($extra['origin'] ?? 'history');
            $check['in_library'] = (bool) ($extra['in_library'] ?? false);
            $check['media_key'] = $extra['media_key'] ?? null;
            $check['show_folder'] = $extra['show_folder'] ?? null;

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
