<?php

namespace App\Service\Automation;

use App\Service\JsonStorage;

class ReleaseIntegrityService
{
    private AutomationStore $store;
    private JsonStorage $storage;

    public function __construct(AutomationStore $store, JsonStorage $storage)
    {
        $this->store = $store;
        $this->storage = $storage;
    }

    public function verifySuccessfulIntents(bool $apply = false, bool $quarantine = false, int $limit = 200): array
    {
        $stats = [
            'checked' => 0,
            'invalid' => 0,
            'retried' => 0,
            'quarantined' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => [],
        ];

        if ($apply && !$quarantine) {
            return [
                'success' => false,
                'message' => 'Applying verification requires --quarantine; no file was changed.',
                'stats' => $stats,
            ];
        }

        $items = $this->store->getIntents(['status' => 'success'], max(1, min(500, $limit)), 0);

        foreach ($items as $intent) {
            $stats['checked']++;
            $historyEntry = $this->findDownloadedFile($intent);
            if ($historyEntry === null) {
                $stats['skipped']++;
                $stats['details'][] = [
                    'id' => (int) $intent['id'],
                    'title' => (string) $intent['title'],
                    'status' => 'skip',
                    'reason' => 'Download path not found in history.',
                ];
                continue;
            }

            $file = $historyEntry['path'];
            $expected = (int) ($historyEntry['actual_size'] ?? 0);
            $actual = is_file($file) ? (int) filesize($file) : 0;
            $html = is_file($file) && $this->looksLikeHtml($file);
            $tooSmall = $actual > 0 && $actual < 1024 * 1024;
            $truncated = $expected > 0 && $actual < (int) round($expected * 0.9);
            $invalid = !is_file($file) || $html || $tooSmall || $truncated;

            if (!$invalid) {
                $stats['details'][] = [
                    'id' => (int) $intent['id'],
                    'title' => (string) $intent['title'],
                    'file' => $file,
                    'size' => $actual,
                    'expected' => $expected,
                    'status' => 'ok',
                ];
                continue;
            }

            $stats['invalid']++;
            $reason = !is_file($file)
                ? 'Downloaded file is missing.'
                : ($html
                    ? 'Downloaded file looks like an HTML error page.'
                    : ($tooSmall
                        ? 'Downloaded file is too small (' . $actual . ' bytes).'
                        : 'Downloaded file is smaller than the exact history size (' . $actual . ' vs ' . $expected . ' bytes).'));

            $detail = [
                'id' => (int) $intent['id'],
                'title' => (string) $intent['title'],
                'file' => $file,
                'size' => $actual,
                'expected' => $expected,
                'status' => $apply ? 'retried' : 'would_retry',
                'reason' => $reason,
            ];

            if ($apply) {
                if (is_file($file)) {
                    $quarantinePath = $file . '.invalid-' . date('YmdHis');
                    if (!@rename($file, $quarantinePath)) {
                        $detail['status'] = 'error';
                        $detail['reason'] = 'Could not quarantine file; intent was not changed.';
                        $stats['failed']++;
                        $stats['details'][] = $detail;
                        continue;
                    }
                    $detail['quarantined_to'] = $quarantinePath;
                    $stats['quarantined']++;
                }

                $this->store->markEntryMissingByPath($file);
                $this->store->updateIntent((int) $intent['id'], [
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_attempt_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'download_id' => null,
                    'info_hash' => null,
                    'file_idx' => null,
                    'error_message' => 'Invalid download detected; queued for retry.',
                ]);
                $this->store->setSetting('library_scan_requested', true);
                $this->store->setSetting(
                    'library_scan_requested_root',
                    in_array((string) ($intent['media_type'] ?? 'movie'), ['series', 'anime'], true) ? 'series' : 'movies'
                );
                $stats['retried']++;
            }

            $stats['details'][] = $detail;
        }

        return ['success' => true, 'stats' => $stats];
    }

    private function findDownloadedFile(array $intent): ?array
    {
        $downloadId = trim((string) ($intent['download_id'] ?? ''));
        if ($downloadId === '') {
            return null;
        }

        $history = $this->storage->get('history', []);
        if (!is_array($history)) {
            return null;
        }

        foreach (array_reverse($history) as $entry) {
            if (!is_array($entry) || (string) ($entry['download_id'] ?? '') !== $downloadId) {
                continue;
            }
            $path = trim((string) ($entry['path'] ?? ''));
            $filename = trim((string) ($entry['filename'] ?? ''));
            if ($path === '' || $filename === '') {
                continue;
            }

            return [
                'path' => rtrim($path, '/\\') . DIRECTORY_SEPARATOR . $filename,
                'size' => isset($entry['size']) && is_numeric($entry['size']) ? (int) $entry['size'] : 0,
                'actual_size' => isset($entry['actual_size']) && is_numeric($entry['actual_size']) ? (int) $entry['actual_size'] : 0,
            ];
        }

        return null;
    }

    private function looksLikeHtml(string $file): bool
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = (string) fread($handle, 512);
        fclose($handle);

        return stripos($head, '<html') !== false || stripos($head, '<!DOCTYPE') !== false;
    }
}
