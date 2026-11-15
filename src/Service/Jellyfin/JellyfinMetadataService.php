<?php

namespace App\Service\Jellyfin;

use App\Service\TmdbService;

class JellyfinMetadataService
{
    private TmdbService $tmdb;

    public function __construct(TmdbService $tmdb)
    {
        $this->tmdb = $tmdb;
    }

    public function isConfigured(): bool
    {
        return $this->tmdb->isConfigured();
    }

    public function details(string $type, ?int $tmdbId, ?string $imdbId = null): ?array
    {
        if (!$this->tmdb->isConfigured()) {
            return null;
        }

        if ($tmdbId !== null && $tmdbId > 0) {
            return $this->tmdb->details($type, $tmdbId);
        }

        $imdbId = trim((string) $imdbId);
        if ($imdbId !== '') {
            return $this->tmdb->findByImdb($imdbId);
        }

        return null;
    }

    public function imageUrl(
        string $type,
        ?int $tmdbId,
        ?string $imdbId,
        string $imageType,
        ?int $index = null
    ): ?string {
        $details = $this->details($type, $tmdbId, $imdbId);
        if ($details === null) {
            return null;
        }

        return match (strtolower($imageType)) {
            'primary', 'thumb', 'poster' => $details['poster'] ?? null,
            'backdrop', 'background', 'art', 'banner' => $details['backdrop'] ?? null,
            default => null,
        };
    }

    public function runtimeTicks(string $type, ?array $details): ?int
    {
        if ($details === null) {
            return null;
        }

        $minutes = $type === 'movie'
            ? ($details['runtime'] ?? null)
            : ($details['episode_runtime'] ?? null);

        if (!is_numeric($minutes) || (int) $minutes <= 0) {
            return null;
        }

        return (int) $minutes * 60 * 10_000_000;
    }
}
