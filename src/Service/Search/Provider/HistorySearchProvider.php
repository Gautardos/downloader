<?php

namespace App\Service\Search\Provider;

use App\Service\JsonStorage;
use App\Service\Search\SearchProviderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class HistorySearchProvider implements SearchProviderInterface
{
    public function __construct(
        private JsonStorage $storage,
        private UrlGeneratorInterface $urls
    ) {
    }

    public function key(): string
    {
        return 'history';
    }

    public function label(): string
    {
        return 'History';
    }

    public function icon(): string
    {
        return 'history';
    }

    public function search(string $query, int $limit): array
    {
        $needle = mb_strtolower(trim($query), 'UTF-8');
        if ($needle === '') {
            return [];
        }

        $history = $this->storage->get('history', []);
        if (!is_array($history)) {
            return [];
        }

        $results = [];
        foreach (array_reverse($history) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $downloadId = (string) ($entry['download_id'] ?? '');
            $filename = (string) ($entry['filename'] ?? '');
            $status = (string) ($entry['status'] ?? '');
            $type = (string) ($entry['type'] ?? '');
            $path = (string) ($entry['path'] ?? '');

            $haystack = mb_strtolower(implode(' ', [
                $filename,
                $path,
                $status,
                $type,
                $downloadId,
            ]), 'UTF-8');

            if (!str_contains($haystack, $needle)) {
                continue;
            }

            $title = $filename !== '' ? $filename : 'Download ' . $downloadId;
            $subtitleParts = [];
            if ($status !== '') {
                $subtitleParts[] = ucfirst($status);
            }
            if ($type !== '') {
                $subtitleParts[] = ucfirst($type);
            }
            $date = (string) ($entry['date'] ?? '');
            if ($date !== '') {
                $subtitleParts[] = $date;
            }

            $metaParts = [];
            $fileCount = (int) ($entry['file_count'] ?? 0);
            if ($fileCount > 0) {
                $metaParts[] = $fileCount . ' file' . ($fileCount > 1 ? 's' : '');
            }
            $size = $this->formatSize($entry['size'] ?? $entry['actual_size'] ?? null);
            if ($size !== '') {
                $metaParts[] = $size;
            }

            $url = $this->urls->generate('history');
            if ($downloadId !== '') {
                try {
                    $url = $this->urls->generate('history_log', ['downloadId' => $downloadId]);
                } catch (\Throwable $e) {
                    $url = $this->urls->generate('history');
                }
            }

            $results[] = [
                'id' => 'history-' . ($downloadId !== '' ? $downloadId : sha1($title . '|' . $date)),
                'title' => $title,
                'subtitle' => implode(' · ', $subtitleParts),
                'meta' => implode(' · ', $metaParts),
                'icon' => $this->icon(),
                'url' => $url,
                'source' => $this->key(),
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    private function formatSize(mixed $size): string
    {
        if (!is_numeric($size)) {
            return '';
        }

        $bytes = (float) $size;
        if ($bytes <= 0) {
            return '';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return number_format($bytes, $index === 0 ? 0 : 1, '.', '') . ' ' . $units[$index];
    }
}
