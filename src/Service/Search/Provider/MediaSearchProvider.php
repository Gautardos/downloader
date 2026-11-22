<?php

namespace App\Service\Search\Provider;

use App\Service\Search\SearchProviderInterface;
use App\Service\TmdbService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class MediaSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private TmdbService $tmdb,
        private UrlGeneratorInterface $urls,
        private int $timeout = 4
    ) {
    }

    public function key(): string
    {
        return 'media';
    }

    public function label(): string
    {
        return 'Discover';
    }

    public function icon(): string
    {
        return 'video';
    }

    public function search(string $query, int $limit): array
    {
        if (!$this->tmdb->isConfigured() || trim($query) === '') {
            return [];
        }

        $matches = $this->tmdb->searchQuick($query, $limit, $this->timeout);
        $results = [];

        foreach ($matches as $match) {
            if (!is_array($match)) {
                continue;
            }

            $id = (int) ($match['id'] ?? 0);
            $type = (string) ($match['type'] ?? '');
            if ($id <= 0 || !in_array($type, ['movie', 'series'], true)) {
                continue;
            }

            $title = trim((string) ($match['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $year = trim((string) ($match['year'] ?? ''));
            $rating = $match['rating'] ?? null;

            $results[] = [
                'id' => 'media-' . $type . '-' . $id,
                'title' => $title,
                'subtitle' => ($type === 'movie' ? 'Movie' : 'Series') . ($year !== '' ? ' · ' . $year : ''),
                'meta' => is_numeric($rating) ? 'TMDB ' . number_format((float) $rating, 1, '.', '') : 'TMDB',
                'icon' => $this->icon(),
                'url' => $this->urls->generate('media_index', ['tmdb' => $type . '-' . $id]),
                'source' => $this->key(),
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }
}
