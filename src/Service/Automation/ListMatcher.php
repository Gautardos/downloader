<?php

namespace App\Service\Automation;

use App\Service\TmdbService;

class ListMatcher
{
    private AutomationStore $store;
    private AutomationSettings $settings;
    private TmdbService $tmdb;
    private int $lookupBudget = 0;
    private int $lookups = 0;
    private ?string $lastEpisodeReason = null;

    public function __construct(AutomationStore $store, AutomationSettings $settings, TmdbService $tmdb)
    {
        $this->store = $store;
        $this->settings = $settings;
        $this->tmdb = $tmdb;
    }

    public function beginRun(?int $maxLookups = null): void
    {
        $this->lookupBudget = max(0, $maxLookups ?? (int) ($this->settings->getListSettings()['max_show_lookups_per_run'] ?? 150));
        $this->lookups = 0;
        $this->lastEpisodeReason = null;
    }

    public function getLookups(): int
    {
        return $this->lookups;
    }

    public function match(array $item): array
    {
        if (!empty($item['ignored_at'])) {
            return $this->result('ignored', 'source_item_ignored');
        }

        $entries = $this->store->getLibraryEntriesForListItem($item);
        [$direct, $titleMatches] = $this->splitEntries($entries, $item);

        $directActive = $this->activeUsable($direct);
        $directIgnored = $this->ignored($direct);
        $titleActive = $this->activeUsable($this->confidenceFiltered($titleMatches));
        $titleIgnored = $this->ignored($titleMatches);

        $fallbackGroups = $this->groupEntries($titleMatches, (string) $item['media_type']);
        if (empty($direct) && count($fallbackGroups) > 1) {
            return $this->result('ambiguous', 'multiple_library_matches');
        }
        if ((string) $item['media_type'] === 'movie'
            && empty($item['year'])
            && empty($item['tmdb_id'])
            && empty($item['imdb_id'])
            && $this->distinctYears($titleMatches) > 1
        ) {
            return $this->result('ambiguous', 'ambiguous_year');
        }

        $relevant = !empty($direct) ? $directActive : $titleActive;
        $ignoredRelevant = !empty($direct) ? $directIgnored : $titleIgnored;

        if (empty($relevant)) {
            if (!empty($ignoredRelevant)) {
                return $this->result('ignored', 'library_group_ignored', $ignoredRelevant[0]);
            }
            if (empty($direct) && !empty($titleMatches)) {
                return $this->result('ambiguous', 'low_confidence_library_match');
            }

            return $this->result('missing', 'not_in_library');
        }

        if ((string) $item['media_type'] === 'series') {
            return $this->matchSeries($item, $relevant);
        }

        return $this->matchMovie($item, $relevant);
    }

    private function matchMovie(array $item, array $relevant): array
    {
        $entry = $relevant[0];

        return $this->result('present', 'movie_present', $entry);
    }

    private function matchSeries(array $item, array $relevant): array
    {
        $tmdbId = !empty($item['tmdb_id']) ? (int) $item['tmdb_id'] : null;
        if ($tmdbId === null) {
            foreach ($relevant as $entry) {
                if (!empty($entry['tmdb_id'])) {
                    $tmdbId = (int) $entry['tmdb_id'];
                    break;
                }
            }
        }

        $seasonScope = $item['season'] !== null ? (int) $item['season'] : null;
        $episodeScope = $item['episode'] !== null ? (int) $item['episode'] : null;

        if ($seasonScope !== null && $episodeScope !== null) {
            $episodes = [[$seasonScope, $episodeScope]];
        } else {
            if ($tmdbId === null) {
                return $this->result('ambiguous', 'identity_unresolved', $relevant[0]);
            }

            $episodes = $this->airedEpisodes($tmdbId, $seasonScope);
            if ($episodes === null) {
                return $this->result('ambiguous', $this->lastEpisodeReason ?: 'episode_list_unavailable', $relevant[0]);
            }
        }

        $present = [];
        foreach ($relevant as $entry) {
            if ($entry['season'] === null || $entry['episode'] === null) {
                continue;
            }
            if ((string) ($entry['parse_source'] ?? '') === 'multi_episode') {
                continue;
            }
            $present[(int) $entry['season'] . ':' . (int) $entry['episode']] = true;
        }

        $episodesTotal = count($episodes);
        $episodesPresent = 0;
        $missing = [];
        foreach ($episodes as [$season, $episode]) {
            if (isset($present[(int) $season . ':' . (int) $episode])) {
                $episodesPresent++;
            } else {
                $missing[] = 'S' . str_pad((string) $season, 2, '0', STR_PAD_LEFT)
                    . 'E' . str_pad((string) $episode, 2, '0', STR_PAD_LEFT);
            }
        }

        $episodesMissing = $episodesTotal - $episodesPresent;
        $firstEntry = $relevant[0];

        if ($episodesTotal === 0) {
            return $this->result('present', 'no_aired_episodes', $firstEntry, [
                'episodes_total' => 0,
                'episodes_present' => 0,
                'episodes_missing' => 0,
            ]);
        }

        $status = match (true) {
            $episodesMissing === 0 => 'present',
            $episodesPresent === 0 => 'missing',
            default => 'missing',
        };
        $reason = match ($status) {
            'present' => 'all_aired_episodes_present',
            'missing' => $episodesPresent === 0 ? 'no_episodes_present' : 'partial_episodes_missing',
        };

        return $this->result($status, $reason, $firstEntry, [
            'episodes_total' => $episodesTotal,
            'episodes_present' => $episodesPresent,
            'episodes_missing' => $episodesMissing,
            'missing_episodes' => array_slice($missing, 0, 50),
        ]);
    }

    private function airedEpisodes(int $tmdbId, ?int $seasonScope): ?array
    {
        $listSettings = $this->settings->getListSettings();
        $backfill = $this->settings->getBackfill();
        $excludeSpecials = (bool) ($backfill['exclude_specials'] ?? true);
        $includeUnaired = (bool) ($listSettings['include_unaired'] ?? false);
        $positiveTtl = max(3600, (int) ($listSettings['cache_positive_days'] ?? 7) * 86400);
        $negativeTtl = max(3600, (int) ($listSettings['cache_negative_days'] ?? 1) * 86400);

        $cacheKey = 'list_show_episodes|' . $tmdbId . '|' . ($excludeSpecials ? '1' : '0') . '|' . ($includeUnaired ? '1' : '0') . '|' . ($seasonScope ?? 'all') . '|' . (new \DateTimeImmutable('today'))->format('Y-m-d');
        $cached = $this->store->getMetadataCache($cacheKey);
        if ($cached !== null) {
            if (($cached['status'] ?? '') === 'resolved' && is_array($cached['episodes'] ?? null)) {
                return $cached['episodes'];
            }

            $this->lastEpisodeReason = 'episode_list_unavailable';

            return null;
        }

        if (!$this->tmdb->isConfigured()) {
            $this->lastEpisodeReason = 'tmdb_not_configured';

            return null;
        }

        if ($this->lookupBudget <= 0) {
            $this->lastEpisodeReason = 'lookup_budget_exhausted';

            return null;
        }

        $details = $this->tmdb->details('series', $tmdbId);
        $this->lookupBudget--;
        $this->lookups++;
        if (!$details) {
            $this->store->setMetadataCache($cacheKey, ['status' => 'unresolved', 'reason' => 'details_unavailable'], $negativeTtl);
            $this->lastEpisodeReason = 'details_unavailable';

            return null;
        }

        $episodes = [];
        foreach ($details['seasons'] ?? [] as $season) {
            $seasonNumber = isset($season['season_number']) ? (int) $season['season_number'] : null;
            if ($seasonNumber === null) {
                continue;
            }
            if ($seasonScope !== null && $seasonNumber !== $seasonScope) {
                continue;
            }
            if ($excludeSpecials && $seasonNumber === 0) {
                continue;
            }
            if ((int) ($season['episode_count'] ?? 0) <= 0) {
                continue;
            }
            if ($this->lookupBudget <= 0) {
                $this->lastEpisodeReason = 'lookup_budget_exhausted';

                return null;
            }

            $seasonEpisodes = $this->tmdb->season($tmdbId, $seasonNumber);
            $this->lookupBudget--;
            $this->lookups++;
            if (empty($seasonEpisodes) && (int) ($season['episode_count'] ?? 0) > 0) {
                $this->lastEpisodeReason = 'season_unavailable';

                return null;
            }
            foreach ($seasonEpisodes as $episode) {
                $episodeNumber = (int) ($episode['episode_number'] ?? 0);
                if ($episodeNumber <= 0) {
                    continue;
                }
                if (!$includeUnaired && !$this->isAired($episode['air_date'] ?? null)) {
                    continue;
                }
                $episodes[] = [$seasonNumber, $episodeNumber];
            }
        }

        $this->store->setMetadataCache($cacheKey, ['status' => 'resolved', 'episodes' => $episodes], $positiveTtl);

        return $episodes;
    }

    private function isAired(mixed $airDate): bool
    {
        $airDate = trim((string) ($airDate ?? ''));
        if ($airDate === '') {
            return true;
        }

        return $airDate <= (new \DateTimeImmutable('today'))->format('Y-m-d');
    }

    private function splitEntries(array $entries, array $item): array
    {
        $direct = [];
        $title = [];
        $tmdbId = !empty($item['tmdb_id']) ? (int) $item['tmdb_id'] : null;
        $imdbId = !empty($item['imdb_id']) ? (string) $item['imdb_id'] : null;
        $titleNormalized = (string) ($item['title_normalized'] ?? '');

        foreach ($entries as $entry) {
            $isDirect = ($tmdbId !== null && !empty($entry['tmdb_id']) && (int) $entry['tmdb_id'] === $tmdbId)
                || ($imdbId !== null && !empty($entry['imdb_id']) && (string) $entry['imdb_id'] === $imdbId);
            if ($isDirect) {
                $direct[] = $entry;
                continue;
            }
            if ($titleNormalized !== '' && (string) ($entry['title_normalized'] ?? '') === $titleNormalized) {
                $title[] = $entry;
            }
        }

        return [$direct, $title];
    }

    private function activeUsable(array $entries): array
    {
        return array_values(array_filter($entries, static function ($entry) {
            return (string) ($entry['status'] ?? '') === 'active'
                && empty($entry['ignored_at']);
        }));
    }

    private function ignored(array $entries): array
    {
        return array_values(array_filter($entries, static fn ($entry) => !empty($entry['ignored_at'])));
    }

    private function confidenceFiltered(array $entries): array
    {
        return array_values(array_filter($entries, static function ($entry) {
            return (float) ($entry['confidence'] ?? 0) >= 0.8;
        }));
    }

    private function groupEntries(array $entries, string $mediaType): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            if ($mediaType === 'movie') {
                $key = 'movie|' . (string) ($entry['title_normalized'] ?? '') . '|' . ($entry['year'] ?? 'unknown');
            } else {
                $folder = (string) ($entry['show_folder'] ?? '');
                $key = 'series|' . ($folder !== '' ? $folder : ((string) ($entry['title_normalized'] ?? '') . '|' . ($entry['year'] ?? 'unknown')));
            }
            $groups[$key] = true;
        }

        return $groups;
    }

    private function distinctYears(array $entries): int
    {
        $years = [];
        foreach ($entries as $entry) {
            if ($entry['year'] !== null) {
                $years[(int) $entry['year']] = true;
            }
        }

        return count($years);
    }

    private function result(string $status, string $reason, ?array $entry = null, array $extra = []): array
    {
        $entry = $entry ?? [];
        $tmdbId = !empty($entry['tmdb_id']) ? (int) $entry['tmdb_id'] : null;

        return array_merge([
            'status' => $status,
            'reason' => $reason,
            'matched_entry_id' => !empty($entry['id']) ? (int) $entry['id'] : null,
            'matched_tmdb_id' => $tmdbId,
            'matched_folder' => isset($entry['show_folder']) && $entry['show_folder'] !== '' ? (string) $entry['show_folder'] : null,
            'episodes_total' => 0,
            'episodes_present' => 0,
            'episodes_missing' => 0,
            'missing_episodes' => [],
        ], $extra);
    }
}
