<?php

namespace App\Service\Automation;

class EpisodeNumberingService
{
    private AutomationStore $store;
    private AutomationSettings $settings;
    private TmdbEpisodeCatalog $catalog;
    private SeriesNumberingProviderInterface $provider;

    public function __construct(
        AutomationStore $store,
        AutomationSettings $settings,
        TmdbEpisodeCatalog $catalog,
        SeriesNumberingProviderInterface $provider
    ) {
        $this->store = $store;
        $this->settings = $settings;
        $this->catalog = $catalog;
        $this->provider = $provider;
    }

    public function identity(array $item): array
    {
        $tmdbId = !empty($item['tmdb_id']) ? (int) $item['tmdb_id'] : null;
        $imdbId = trim((string) ($item['imdb_id'] ?? ''));
        $title = trim((string) ($item['title'] ?? ''));
        $year = !empty($item['year']) ? (int) $item['year'] : null;

        return [
            'tmdb_id' => $tmdbId,
            'imdb_id' => $imdbId,
            'title' => $title,
            'year' => $year,
            'title_normalized' => trim((string) ($item['title_normalized'] ?? '')) ?: $this->normalizeTitle($title),
        ];
    }

    public function profileFor(array $identity, bool $refresh = false, bool $detectIfNeeded = true): ?array
    {
        $tmdbId = !empty($identity['tmdb_id']) ? (int) $identity['tmdb_id'] : null;
        $imdbId = trim((string) ($identity['imdb_id'] ?? ''));
        $stored = $this->store->getSeriesNumbering($tmdbId, $imdbId !== '' ? $imdbId : null);
        if ($stored !== null && !$refresh) {
            $autoSource = (string) ($stored['source'] ?? '') !== 'manual';
            $staleAmbiguous = (string) ($stored['status'] ?? '') === 'ambiguous'
                && $autoSource
                && strtotime((string) ($stored['updated_at'] ?? '')) < time() - 21600;
            $staleProfile = $autoSource
                && strtotime((string) ($stored['updated_at'] ?? '')) < time() - 604800;
            if (!$staleAmbiguous && !$staleProfile) {
                return $stored;
            }
        }
        if (!$detectIfNeeded || $tmdbId === null) {
            return $stored;
        }

        return $this->detect($identity, $stored, $refresh);
    }

    public function detect(array $identity, ?array $existing = null, bool $refresh = false): array
    {
        $tmdbId = !empty($identity['tmdb_id']) ? (int) $identity['tmdb_id'] : null;
        $imdbId = trim((string) ($identity['imdb_id'] ?? ''));
        $title = trim((string) ($identity['title'] ?? ''));
        $year = !empty($identity['year']) ? (int) $identity['year'] : null;
        if ($tmdbId === null) {
            return $this->ambiguousProfile($identity, $existing, 'missing_tmdb_id');
        }

        if ($imdbId === '') {
            $details = $this->catalog->seriesDetails($tmdbId);
            if (is_array($details)) {
                $imdbId = trim((string) ($details['imdb_id'] ?? ''));
                $title = $title !== '' ? $title : trim((string) ($details['title'] ?? ''));
                $year = $year ?? (!empty($details['year']) ? (int) $details['year'] : null);
                $identity['imdb_id'] = $imdbId;
                $identity['title'] = $title;
                $identity['year'] = $year;
            }
        }

        $canonical = $this->catalog->episodes($tmdbId);
        if (empty($canonical)) {
            return $this->ambiguousProfile($identity, $existing, 'tmdb_episodes_unavailable');
        }

        $providerResult = $this->provider->fetch($identity, $refresh);
        if (empty($providerResult['success']) || empty($providerResult['videos'])) {
            return $this->ambiguousProfile($identity, $existing, (string) ($providerResult['reason'] ?? 'numbering_provider_unavailable'));
        }

        $providerVideos = $this->validProviderVideos($providerResult['videos'] ?? []);
        if (empty($providerVideos)) {
            return $this->ambiguousProfile($identity, $existing, 'numbering_provider_no_videos');
        }

        $alignment = $this->alignEpisodes($canonical, $providerVideos);
        $mapping = $alignment['mapping'];
        $status = $alignment['ambiguous'] ? 'ambiguous' : 'ok';
        $confidence = $alignment['confidence'];
        $reason = $alignment['reason'];

        $localEntries = $this->localEntries($identity);
        $localRatio = $this->localValidationRatio($localEntries, $providerVideos);
        $canonicalRatio = $this->localValidationRatio($localEntries, array_map(
            static fn (array $episode): array => ['season' => (int) $episode['season'], 'episode' => (int) $episode['episode']],
            $canonical
        ));
        $computedTargetMode = $existing['target_mode'] ?? 'local';
        if ($localRatio !== null) {
            if ($localRatio >= 0.8) {
                $confidence = min(0.99, $confidence + 0.05);
                $computedTargetMode = 'local';
            } elseif ($localRatio < 0.5) {
                if ($canonicalRatio !== null && $canonicalRatio >= 0.8) {
                    $confidence = min(0.99, $confidence + 0.05);
                    $computedTargetMode = 'tmdb';
                } else {
                    $confidence = max(0, $confidence - 0.3);
                    if ($status === 'ok') {
                        $status = 'ambiguous';
                        $reason = 'local_numbering_mismatch';
                    }
                }
            }
        }

        if ($status === 'ok' && count($mapping) !== count($canonical)) {
            $status = 'ambiguous';
            $reason = 'mapping_incomplete';
        }
        if ($alignment['ordinal']) {
            $confidence = min($confidence, 0.65);
        }

        $segments = $this->segmentsFromMapping($mapping);
        $profile = $this->store->getSeriesNumbering($tmdbId, $imdbId !== '' ? $imdbId : null);
        $result = [
            'tmdb_id' => $tmdbId,
            'imdb_id' => $imdbId !== '' ? $imdbId : null,
            'title' => $title,
            'source' => (string) ($existing['source'] ?? '') === 'manual' ? 'manual' : (string) ($providerResult['provider'] ?? 'auto'),
            'source_ref' => (string) ($providerResult['source_ref'] ?? ''),
            'segments' => $segments,
            'mapping' => $mapping,
            'confidence' => round($confidence, 2),
            'status' => $status,
            'reason' => $reason,
            'tmdb_snapshot' => [
                'hash' => sha1(json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                'episodes' => count($canonical),
                'seasons' => $this->canonicalSeasonCounts($canonical),
                'generated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            'target_mode' => (string) ($existing['source'] ?? '') === 'manual'
                ? (string) ($existing['target_mode'] ?? 'local')
                : (string) $computedTargetMode,
            'jellyfin_mode' => (string) ($existing['jellyfin_mode'] ?? $profile['jellyfin_mode'] ?? 'local'),
        ];
        $result['provider_label'] = (string) ($providerResult['provider_label'] ?? $providerResult['provider'] ?? 'AIOMetadata');
        $result['id'] = $this->store->saveSeriesNumbering($result);

        return $this->store->getSeriesNumbering($tmdbId, $imdbId !== '' ? $imdbId : null) ?? $result;
    }

    public function isUsable(?array $profile): bool
    {
        if ($profile === null || (string) ($profile['status'] ?? '') !== 'ok' || empty($profile['mapping'])) {
            return false;
        }
        if ((string) ($profile['source'] ?? '') === 'manual') {
            return true;
        }

        return (float) ($profile['confidence'] ?? 0) >= 0.75;
    }

    public function confirm(array $identity): ?array
    {
        $profile = $this->profileFor($identity, false, false);
        if ($profile === null || empty($profile['mapping'])) {
            return null;
        }
        $profile['source'] = 'manual';
        $profile['status'] = 'ok';
        $profile['reason'] = null;
        $profile['id'] = $this->store->saveSeriesNumbering($profile);

        return $this->store->getSeriesNumbering(
            !empty($identity['tmdb_id']) ? (int) $identity['tmdb_id'] : null,
            $identity['imdb_id'] ?? null
        ) ?? $profile;
    }

    public function acquisitionFor(?array $profile, int $season, int $episode): ?array
    {
        if ($profile === null || ($profile['status'] ?? '') !== 'ok') {
            return null;
        }
        foreach (($profile['mapping'] ?? []) as $entry) {
            if ((int) ($entry['tmdb_season'] ?? 0) === $season && (int) ($entry['tmdb_episode'] ?? 0) === $episode) {
                return [
                    'season' => (int) ($entry['release_season'] ?? $season),
                    'episode' => (int) ($entry['release_episode'] ?? $episode),
                    'numbering_source' => (string) ($profile['source'] ?? ''),
                ];
            }
        }

        return null;
    }

    public function canonicalFor(?array $profile, int $season, int $episode): ?array
    {
        if ($profile === null || ($profile['status'] ?? '') !== 'ok') {
            return null;
        }
        foreach (($profile['mapping'] ?? []) as $entry) {
            if ((int) ($entry['release_season'] ?? 0) === $season && (int) ($entry['release_episode'] ?? 0) === $episode) {
                return [
                    'season' => (int) ($entry['tmdb_season'] ?? $season),
                    'episode' => (int) ($entry['tmdb_episode'] ?? $episode),
                ];
            }
        }

        return null;
    }

    public function displayCoordinates(array $entry, ?array $profile, string $mode = 'local'): array
    {
        $season = isset($entry['season']) ? (int) $entry['season'] : null;
        $episode = isset($entry['episode']) ? (int) $entry['episode'] : null;
        if ($mode === 'tmdb' && $season !== null && $episode !== null && $profile !== null) {
            $canonical = $this->canonicalFor($profile, $season, $episode);
            if ($canonical !== null) {
                return $canonical;
            }
        }

        return ['season' => $season, 'episode' => $episode];
    }

    public function updateModes(array $identity, string $targetMode, string $jellyfinMode, ?array $mapping = null, string $source = 'manual'): array
    {
        $profile = $this->profileFor($identity, false, false) ?? $this->ambiguousProfile($identity, null, 'manual_profile');
        $profile['target_mode'] = in_array($targetMode, ['local', 'tmdb'], true) ? $targetMode : 'local';
        $profile['jellyfin_mode'] = in_array($jellyfinMode, ['local', 'tmdb'], true) ? $jellyfinMode : 'local';
        if ($mapping !== null) {
            $profile['mapping'] = $mapping;
            $profile['segments'] = $this->segmentsFromMapping($mapping);
            $profile['status'] = empty($mapping) ? 'ambiguous' : 'ok';
            $profile['reason'] = empty($mapping) ? 'manual_mapping_empty' : null;
            $profile['confidence'] = 1.0;
        }
        if ($source !== '') {
            $profile['source'] = $source;
        }
        $profile['id'] = $this->store->saveSeriesNumbering($profile);

        return $this->store->getSeriesNumbering(!empty($identity['tmdb_id']) ? (int) $identity['tmdb_id'] : null, $identity['imdb_id'] ?? null) ?? $profile;
    }

    private function ambiguousProfile(array $identity, ?array $existing, string $reason): array
    {
        $profile = [
            'tmdb_id' => !empty($identity['tmdb_id']) ? (int) $identity['tmdb_id'] : null,
            'imdb_id' => trim((string) ($identity['imdb_id'] ?? '')) ?: null,
            'title' => (string) ($identity['title'] ?? ''),
            'source' => 'auto',
            'source_ref' => null,
            'segments' => [],
            'mapping' => [],
            'confidence' => 0.0,
            'status' => 'ambiguous',
            'reason' => $reason,
            'tmdb_snapshot' => [],
            'target_mode' => (string) ($existing['target_mode'] ?? 'local'),
            'jellyfin_mode' => (string) ($existing['jellyfin_mode'] ?? 'local'),
        ];
        if ($profile['tmdb_id'] !== null) {
            $profile['id'] = $this->store->saveSeriesNumbering($profile);
        }

        return $profile;
    }

    private function validProviderVideos(array $videos): array
    {
        $result = [];
        foreach ($videos as $video) {
            if (!is_array($video) || !isset($video['season'], $video['episode'])) {
                continue;
            }
            $season = (int) $video['season'];
            $episode = (int) $video['episode'];
            if ($season <= 0 || $episode <= 0) {
                continue;
            }
            $title = trim((string) ($video['title'] ?? ''));
            if (strcasecmp($title, 'TBA') === 0) {
                continue;
            }
            $released = $this->normalizeDate($video['released'] ?? null);
            $result[] = [
                'season' => $season,
                'episode' => $episode,
                'title' => $title,
                'released' => $released,
                'id' => $video['id'] ?? null,
            ];
        }
        usort($result, static fn ($a, $b) => [$a['season'], $a['episode']] <=> [$b['season'], $b['episode']]);
        $seen = [];
        $unique = [];
        foreach ($result as $video) {
            $key = $video['season'] . ':' . $video['episode'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $video;
        }

        return $unique;
    }

    private function alignEpisodes(array $canonical, array $provider): array
    {
        $used = [];
        $mapping = [];
        $dateOnly = 0;
        $unmatched = 0;
        foreach ($canonical as $episode) {
            $bestIndex = null;
            $bestScore = PHP_INT_MAX;
            foreach ($provider as $index => $candidate) {
                if (isset($used[$index])) {
                    continue;
                }
                $titleMatch = $this->normalizeTitle((string) ($episode['title'] ?? '')) === $this->normalizeTitle((string) ($candidate['title'] ?? ''));
                $dayDistance = $this->dayDistance($episode['air_date'] ?? null, $candidate['released'] ?? null);
                $score = null;
                if ($titleMatch && ($dayDistance === null || $dayDistance <= 3)) {
                    $score = $dayDistance ?? 10;
                } elseif ($dayDistance !== null && $dayDistance <= 3) {
                    $score = 100 + $dayDistance;
                }
                if ($score !== null && $score < $bestScore) {
                    $bestScore = $score;
                    $bestIndex = $index;
                }
            }
            if ($bestIndex === null) {
                $unmatched++;
                continue;
            }
            if ($bestScore >= 100) {
                $dateOnly++;
            }
            $used[$bestIndex] = true;
            $candidate = $provider[$bestIndex];
            $mapping[] = [
                'tmdb_season' => (int) $episode['season'],
                'tmdb_episode' => (int) $episode['episode'],
                'release_season' => (int) $candidate['season'],
                'release_episode' => (int) $candidate['episode'],
                'title' => (string) ($episode['title'] ?? ''),
                'air_date' => $episode['air_date'] ?? null,
            ];
        }

        if ($unmatched === 0) {
            $confidence = $dateOnly > 0 ? 0.82 : 0.95;
            if (count($mapping) !== count($canonical)) {
                return ['mapping' => $mapping, 'ambiguous' => true, 'confidence' => 0.4, 'reason' => 'mapping_incomplete', 'ordinal' => false];
            }

            return ['mapping' => $mapping, 'ambiguous' => false, 'confidence' => $confidence, 'reason' => null, 'ordinal' => false];
        }

        if (count($canonical) === count($provider)) {
            $ordinalMapping = [];
            foreach ($canonical as $index => $episode) {
                $candidate = $provider[$index];
                $ordinalMapping[] = [
                    'tmdb_season' => (int) $episode['season'],
                    'tmdb_episode' => (int) $episode['episode'],
                    'release_season' => (int) $candidate['season'],
                    'release_episode' => (int) $candidate['episode'],
                    'title' => (string) ($episode['title'] ?? ''),
                    'air_date' => $episode['air_date'] ?? null,
                ];
            }

            return ['mapping' => $ordinalMapping, 'ambiguous' => false, 'confidence' => 0.55, 'reason' => null, 'ordinal' => true];
        }

        return ['mapping' => $mapping, 'ambiguous' => true, 'confidence' => 0.3, 'reason' => 'episode_alignment_failed', 'ordinal' => false];
    }

    private function localEntries(array $identity): array
    {
        return $this->store->getLibraryEntriesForListItem([
            'tmdb_id' => $identity['tmdb_id'] ?? null,
            'imdb_id' => $identity['imdb_id'] ?? null,
            'title_normalized' => $identity['title_normalized'] ?? '',
            'year' => $identity['year'] ?? null,
        ]);
    }

    private function localValidationRatio(array $entries, array $providerVideos): ?float
    {
        $providerSet = [];
        foreach ($providerVideos as $video) {
            $providerSet[$video['season'] . ':' . $video['episode']] = true;
        }
        $relevant = 0;
        $matches = 0;
        foreach ($entries as $entry) {
            if (!in_array((string) ($entry['media_type'] ?? ''), ['series', 'anime'], true)) {
                continue;
            }
            if ($entry['season'] === null || $entry['episode'] === null) {
                continue;
            }
            if ((string) ($entry['parse_source'] ?? '') === 'multi_episode' || (float) ($entry['confidence'] ?? 0) < 0.8) {
                continue;
            }
            $season = (int) $entry['season'];
            if ($season <= 0) {
                continue;
            }
            $relevant++;
            if (isset($providerSet[$season . ':' . (int) $entry['episode']])) {
                $matches++;
            }
        }

        return $relevant > 0 ? $matches / $relevant : null;
    }

    private function segmentsFromMapping(array $mapping): array
    {
        $counts = [];
        foreach ($mapping as $entry) {
            $season = (int) ($entry['release_season'] ?? 0);
            if ($season <= 0) {
                continue;
            }
            $counts[$season] = ($counts[$season] ?? 0) + 1;
        }
        ksort($counts);
        $segments = [];
        foreach ($counts as $season => $count) {
            $segments[] = ['season' => $season, 'episodes' => $count];
        }

        return $segments;
    }

    private function canonicalSeasonCounts(array $episodes): array
    {
        $counts = [];
        foreach ($episodes as $episode) {
            $season = (int) ($episode['season'] ?? 0);
            if ($season > 0) {
                $counts[$season] = ($counts[$season] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $timestamp = strtotime($value);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    private function dayDistance(?string $a, ?string $b): ?int
    {
        if ($a === null || $b === null) {
            return null;
        }
        $ta = strtotime($a);
        $tb = strtotime($b);
        if ($ta === false || $tb === false) {
            return null;
        }

        return (int) round(abs($ta - $tb) / 86400);
    }

    private function normalizeTitle(string $title): string
    {
        $title = trim($title);
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($title, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $title = $decomposed;
            }
        }
        $title = preg_replace('/\p{M}+/u', '', $title) ?? $title;
        $title = mb_strtolower($title, 'UTF-8');
        $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? $title;

        return trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    }
}
