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
        $items = $this->store->getIntents(['status' => 'success'], max(1, min(500, $limit)), 0);
        $stats = [
            'checked' => 0,
            'invalid' => 0,
            'retried' => 0,
            'quarantined' => 0,
            'skipped' => 0,
            'details' => [],
        ];

        foreach ($items as $intent) {
            $stats['checked']++;
            $file = $this->findDownloadedFile($intent);
            if ($file === null) {
                $stats['skipped']++;
                $stats['details'][] = [
                    'id' => (int) $intent['id'],
                    'title' => (string) $intent['title'],
                    'status' => 'skip',
                    'reason' => 'Download path not found in history.',
                ];
                continue;
            }

            $expected = $this->parseExpectedBytes((string) ($intent['release_explanation'] ?? ''));
            $actual = is_file($file) ? (int) filesize($file) : 0;
            $invalid = !is_file($file)
                || ($expected > 0 && $actual < (int) round($expected * 0.9))
                || ($expected === 0 && $actual > 0 && $actual < 1024 * 1024);

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
            $detail = [
                'id' => (int) $intent['id'],
                'title' => (string) $intent['title'],
                'file' => $file,
                'size' => $actual,
                'expected' => $expected,
                'status' => $apply ? 'retried' : 'would_retry',
                'reason' => !is_file($file)
                    ? 'Downloaded file is missing.'
                    : ($expected > 0
                        ? 'Downloaded file is smaller than expected (' . $actual . ' vs ' . $expected . ' bytes).'
                        : 'Downloaded file is too small (' . $actual . ' bytes).'),
            ];

            if ($apply) {
                if ($quarantine && is_file($file)) {
                    $quarantinePath = $file . '.invalid-' . date('YmdHis');
                    if (@rename($file, $quarantinePath)) {
                        $detail['quarantined_to'] = $quarantinePath;
                        $stats['quarantined']++;
                    }
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
                $this->store->setSetting('library_scan_requested_root', 'movies');
                $stats['retried']++;
            }

            $stats['details'][] = $detail;
        }

        return ['success' => true, 'stats' => $stats];
    }

    private function findDownloadedFile(array $intent): ?string
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

            return rtrim($path, '/\\') . DIRECTORY_SEPARATOR . $filename;
        }

        return null;
    }

    private function parseExpectedBytes(string $text): int
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(TB|TO|GB|GO|MB|MO)\b/iu', $text, $matches)) {
            $value = (float) str_replace(',', '.', $matches[1]);
            $factor = match (strtoupper($matches[2])) {
                'TB', 'TO' => 1024 ** 4,
                'GB', 'GO' => 1024 ** 3,
                'MB', 'MO' => 1024 ** 2,
                default => 0,
            };

            return (int) round($value * $factor);
        }

        return 0;
    }
}
