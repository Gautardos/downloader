<?php

namespace App\Service\Search\Provider;

use App\Service\Search\SearchProviderInterface;
use App\Service\SpotifyService;

class MusicSearchProvider implements SearchProviderInterface
{
    private const TYPE_LABELS = [
        'track' => 'Track',
        'album' => 'Album',
        'playlist' => 'Playlist',
    ];

    public function __construct(
        private SpotifyService $spotify,
        private int $timeout = 4
    ) {
    }

    public function key(): string
    {
        return 'music';
    }

    public function label(): string
    {
        return 'Music';
    }

    public function icon(): string
    {
        return 'music';
    }

    public function search(string $query, int $limit): array
    {
        if (!$this->spotify->isConfigured() || trim($query) === '') {
            return [];
        }

        $matches = $this->spotify->searchQuick($query, $limit, $this->timeout);
        if (!is_array($matches)) {
            return [];
        }

        $results = [];
        foreach ($matches as $match) {
            if (!is_array($match)) {
                continue;
            }

            $id = trim((string) ($match['id'] ?? ''));
            $type = (string) ($match['type'] ?? '');
            $title = trim((string) ($match['name'] ?? ''));
            if ($id === '' || $title === '' || !isset(self::TYPE_LABELS[$type])) {
                continue;
            }

            $url = (string) ($match['url'] ?? '');
            if (!str_starts_with($url, 'https://open.spotify.com/')) {
                $url = 'https://open.spotify.com/' . $type . '/' . rawurlencode($id);
            }

            $subtitle = self::TYPE_LABELS[$type];
            $artist = trim((string) ($match['subtitle'] ?? ''));
            if ($artist !== '') {
                $subtitle .= ' · ' . $artist;
            }

            $results[] = [
                'id' => 'music-' . $id,
                'title' => $title,
                'subtitle' => $subtitle,
                'meta' => trim((string) ($match['meta'] ?? '')) ?: 'Spotify',
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
}
