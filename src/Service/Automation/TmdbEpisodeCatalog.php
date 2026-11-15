<?php

namespace App\Service\Automation;

use App\Service\TmdbService;

class TmdbEpisodeCatalog
{
    private AutomationStore $store;
    private AutomationSettings $settings;
    private TmdbService $tmdb;

    public function __construct(AutomationStore $store, AutomationSettings $settings, TmdbService $tmdb)
    {
        $this->store = $store;
        $this->settings = $settings;
        $this->tmdb = $tmdb;
    }

    public function seriesDetails(int $tmdbId): ?array
    {
        return $this->tmdb->details('series', $tmdbId);
    }

    public function episodes(int $tmdbId, ?bool $includeSpecials = null, ?bool $includeUnaired = null): array
    {
        if ($tmdbId <= 0 || !$this->tmdb->isConfigured()) {
            return [];
        }

        $backfill = $this->settings->getBackfill();
        $listSettings = $this->settings->getListSettings();
        $includeSpecials = $includeSpecials ?? !((bool) ($backfill['exclude_specials'] ?? true));
        $includeUnaired = $includeUnaired ?? (bool) ($listSettings['include_unaired'] ?? false);
        $positiveTtl = max(3600, (int) ($listSettings['cache_positive_days'] ?? 7) * 86400);
        $negativeTtl = max(3600, (int) ($listSettings['cache_negative_days'] ?? 1) * 86400);
        $cacheKey = 'tmdb_episode_catalog|' . $tmdbId . '|' . ($includeSpecials ? '1' : '0') . '|' . ($includeUnaired ? '1' : '0');

        $cached = $this->store->getMetadataCache($cacheKey);
        if (is_array($cached)) {
            return (string) ($cached['status'] ?? '') === 'resolved' && is_array($cached['episodes'] ?? null)
                ? $cached['episodes']
                : [];
        }

        $details = $this->tmdb->details('series', $tmdbId);
        if ($details === null) {
            $this->store->setMetadataCache($cacheKey, ['status' => 'unresolved', 'reason' => 'details_unavailable'], $negativeTtl);

            return [];
        }

        $episodes = [];
        foreach (($details['seasons'] ?? []) as $season) {
            $seasonNumber = isset($season['season_number']) ? (int) $season['season_number'] : null;
            if ($seasonNumber === null || (int) ($season['episode_count'] ?? 0) <= 0) {
                continue;
            }
            if (!$includeSpecials && $seasonNumber === 0) {
                continue;
            }

            $seasonEpisodes = $this->tmdb->season($tmdbId, $seasonNumber);
            foreach ($seasonEpisodes as $episode) {
                $episodeNumber = (int) ($episode['episode_number'] ?? 0);
                if ($episodeNumber <= 0) {
                    continue;
                }
                $airDate = isset($episode['air_date']) && $episode['air_date'] !== '' ? (string) $episode['air_date'] : null;
                if (!$includeUnaired && !$this->isAired($airDate)) {
                    continue;
                }
                $episodes[] = [
                    'season' => $seasonNumber,
                    'episode' => $episodeNumber,
                    'title' => (string) ($episode['name'] ?? ''),
                    'air_date' => $airDate,
                ];
            }
        }

        usort($episodes, static fn ($a, $b) => [$a['season'], $a['episode']] <=> [$b['season'], $b['episode']]);
        foreach ($episodes as $index => &$episode) {
            $episode['ordinal'] = $index + 1;
        }
        unset($episode);

        $this->store->setMetadataCache($cacheKey, ['status' => 'resolved', 'episodes' => $episodes], $positiveTtl);

        return $episodes;
    }

    private function isAired(?string $airDate): bool
    {
        if ($airDate === null || $airDate === '') {
            return false;
        }
        $timestamp = strtotime($airDate);
        if ($timestamp === false) {
            return false;
        }

        return $timestamp <= strtotime('today 23:59:59') + 86400;
    }
}
