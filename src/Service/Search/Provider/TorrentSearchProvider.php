<?php

namespace App\Service\Search\Provider;

use App\Service\Search\SearchProviderInterface;
use App\Service\TorrentDbService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class TorrentSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private TorrentDbService $torrents,
        private UrlGeneratorInterface $urls
    ) {
    }

    public function key(): string
    {
        return 'torrents';
    }

    public function label(): string
    {
        return 'Local torrent database';
    }

    public function icon(): string
    {
        return 'torrent';
    }

    public function search(string $query, int $limit): array
    {
        if (trim($query) === '') {
            return [];
        }

        $matches = $this->torrents->search('all', $query, $limit);
        $results = [];
        $seen = [];

        foreach ($matches as $match) {
            if (!is_array($match)) {
                continue;
            }

            $title = trim((string) ($match['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $size = $match['size'] ?? null;
            $dedupeKey = mb_strtolower($title, 'UTF-8') . '|' . (string) $size;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $hash = (string) ($match['hash'] ?? '');
            $subtitleParts = ['Torrent'];
            $formattedSize = $this->formatSize($size);
            if ($formattedSize !== '') {
                $subtitleParts[] = $formattedSize;
            }

            $results[] = [
                'id' => 'torrent-' . ($hash !== '' ? $hash : sha1($title . '|' . (string) $size)),
                'title' => $title,
                'subtitle' => implode(' · ', $subtitleParts),
                'meta' => $hash !== '' ? mb_substr($hash, 0, 12) . '…' : 'Local DB',
                'icon' => $this->icon(),
                'url' => $this->urls->generate('torrent', ['q' => $title]),
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
