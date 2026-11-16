<?php

namespace App\Service\Jellyfin;

use App\Service\Automation\AutomationSettings;
use App\Service\Automation\AutomationStore;

class JellyfinLibraryService
{
    private AutomationStore $store;
    private AutomationSettings $settings;
    private JellyfinStateService $state;
    private JellyfinMediaService $media;
    private JellyfinMetadataService $metadata;
    private ?\App\Service\Automation\EpisodeNumberingService $numbering;

    private ?array $roots = null;
    private array $entryCache = [];
    private array $movieGroupCache = [];
    private array $seriesGroupCache = [];
    private array $metadataCache = [];

    public function __construct(
        AutomationStore $store,
        AutomationSettings $settings,
        JellyfinStateService $state,
        JellyfinMediaService $media,
        JellyfinMetadataService $metadata,
        ?\App\Service\Automation\EpisodeNumberingService $numbering = null
    ) {
        $this->store = $store;
        $this->settings = $settings;
        $this->state = $state;
        $this->media = $media;
        $this->metadata = $metadata;
        $this->numbering = $numbering;
    }

    public function views(): array
    {
        $stats = $this->store->getRootStats();
        $items = [];
        foreach ($this->roots() as $key => $root) {
            if ((int) ($stats[$key]['files'] ?? 0) === 0) {
                continue;
            }

            $items[] = [
                'Id' => JellyfinIds::library($key),
                'Name' => (string) ($root['label'] ?? $key),
                'Type' => 'CollectionFolder',
                'CollectionType' => $this->collectionType((string) ($root['type'] ?? 'series')),
            ];
        }

        return [
            'Items' => $items,
            'TotalRecordCount' => count($items),
        ];
    }

    public function items(array $query, string $userId): array
    {
        $parentId = $this->queryString($query, 'parentId');
        $parent = $parentId !== '' ? JellyfinIds::decode($parentId) : null;
        $includeItemTypes = $this->includeItemTypes($this->queryString($query, 'includeItemTypes'));
        $searchTerm = trim($this->queryString($query, 'searchTerm'));
        $startIndex = max(0, $this->queryInt($query, 'startIndex', 0));
        $limit = min(500, max(1, $this->queryInt($query, 'limit', 50)));
        $includeUserData = !$this->queryIsFalse($query, 'enableUserData');
        $includeImages = !$this->queryIsFalse($query, 'enableImages') && $this->queryInt($query, 'imageTypeLimit', 1) !== 0;
        $includeMetadata = $includeImages || $this->fieldsWantMetadata($this->queryString($query, 'fields'));

        $groups = [];
        if ($parent !== null && ($parent['kind'] ?? '') === 'series') {
            return $this->seriesEpisodes($parent, $userId, null, $includeUserData, $includeImages, $includeMetadata, $startIndex, $limit);
        }

        if ($parent !== null && ($parent['kind'] ?? '') === 'movie') {
            $movie = $this->findMovieGroup((string) ($parent['root'] ?? ''), (string) ($parent['key'] ?? ''));
            $groups = $movie !== null ? [$movie] : [];
        } elseif ($parent !== null && ($parent['kind'] ?? '') === 'library') {
            $rootKey = (string) ($parent['root'] ?? '');
            $root = $this->roots()[$rootKey] ?? null;
            if ($root !== null) {
                $groups = $this->groupsForRoot($rootKey, (string) ($root['type'] ?? 'series'), $includeItemTypes);
            }
        } elseif ($parent === null && $searchTerm !== '') {
            foreach ($this->roots() as $rootKey => $root) {
                $groups = array_merge($groups, $this->groupsForRoot($rootKey, (string) ($root['type'] ?? 'series'), $includeItemTypes));
            }
        }

        if ($searchTerm !== '') {
            $needle = mb_strtolower($searchTerm);
            $allGroups = $groups;
            $groups = array_values(array_filter($groups, static function (array $group) use ($needle): bool {
                return str_contains(mb_strtolower((string) ($group['title'] ?? '')), $needle);
            }));
            if ($groups === []
                && ($parent['kind'] ?? '') === 'library'
                && in_array('series', $includeItemTypes, true)
            ) {
                $groups = array_values(array_filter($allGroups, static fn (array $group): bool => ($group['kind'] ?? '') === 'series'));
            }
        }

        $sortBy = $this->queryString($query, 'sortBy');
        $sortOrder = $this->queryString($query, 'sortOrder', 'Ascending');
        $this->sortGroups($groups, $sortBy, $sortOrder);

        $total = count($groups);
        $page = array_slice($groups, $startIndex, $limit);
        $items = [];
        foreach ($page as $group) {
            $items[] = $group['kind'] === 'movie'
                ? $this->buildMovieItem($group, $userId, false, $includeUserData, $includeImages, $includeMetadata)
                : $this->buildSeriesItem($group, $userId, $includeUserData, $includeImages, $includeMetadata);
        }

        return [
            'Items' => $items,
            'TotalRecordCount' => $total,
        ];
    }

    public function item(
        string $itemId,
        string $userId,
        bool $includeMediaSources = false,
        bool $includeUserData = true,
        bool $includeImages = true,
        bool $includeMetadata = true
    ): ?array {
        $decoded = JellyfinIds::decode($itemId);
        if ($decoded === null) {
            return null;
        }

        return match ($decoded['kind']) {
            'movie' => ($group = $this->findMovieGroup((string) ($decoded['root'] ?? ''), (string) ($decoded['key'] ?? ''))) !== null
                ? $this->buildMovieItem($group, $userId, $includeMediaSources, $includeUserData, $includeImages, $includeMetadata)
                : null,
            'series' => ($group = $this->findSeriesGroup((string) ($decoded['root'] ?? ''), (string) ($decoded['key'] ?? ''))) !== null
                ? $this->buildSeriesItem($group, $userId, $includeUserData, $includeImages, $includeMetadata)
                : null,
            'episode' => $this->buildEpisodeFromEntryId((int) ($decoded['id'] ?? 0), $userId, $includeMediaSources, $includeUserData, $includeImages, $includeMetadata),
            default => null,
        };
    }

    public function episodes(
        string $seriesId,
        string $userId,
        ?int $season = null,
        bool $includeUserData = true,
        bool $includeImages = true,
        bool $includeMetadata = true,
        int $startIndex = 0,
        int $limit = 500
    ): ?array {
        $decoded = JellyfinIds::decode($seriesId);
        if ($decoded === null || ($decoded['kind'] ?? '') !== 'series') {
            return null;
        }

        return $this->seriesEpisodes($decoded, $userId, $season, $includeUserData, $includeImages, $includeMetadata, $startIndex, $limit);
    }

    public function resume(string $userId, int $limit = 50): array
    {
        $items = [];
        foreach ($this->state->resume($userId, 200) as $state) {
            $item = $this->item((string) ($state['item_id'] ?? ''), $userId);
            if ($item !== null) {
                $items[] = $item;
            }
            if (count($items) >= $limit) {
                break;
            }
        }

        return [
            'Items' => $items,
            'TotalRecordCount' => count($items),
        ];
    }

    public function playbackInfo(string $itemId): ?array
    {
        $decoded = JellyfinIds::decode($itemId);
        if ($decoded === null) {
            return null;
        }

        $entries = $this->entriesForItem($decoded);
        if ($entries === []) {
            return null;
        }

        $sources = $this->media->mediaSources($entries, $itemId);
        if ($sources === []) {
            return null;
        }

        return [
            'MediaSources' => $sources,
            'PlaySessionId' => bin2hex(random_bytes(8)),
        ];
    }

    public function streamEntries(string $itemId, ?string $mediaSourceId): ?array
    {
        $decoded = JellyfinIds::decode($itemId);
        if ($decoded === null) {
            return null;
        }

        $entries = $this->entriesForItem($decoded);
        if ($entries === []) {
            return null;
        }

        $entry = $this->media->streamEntry($entries, $mediaSourceId);

        return $entry !== null ? ['entry' => $entry] : null;
    }

    public function setPlayed(string $userId, string $itemId, bool $played): bool
    {
        $decoded = JellyfinIds::decode($itemId);
        if ($decoded === null) {
            return false;
        }

        $exists = match ($decoded['kind']) {
            'movie' => $this->findMovieGroup((string) ($decoded['root'] ?? ''), (string) ($decoded['key'] ?? '')) !== null,
            'series' => $this->findSeriesGroup((string) ($decoded['root'] ?? ''), (string) ($decoded['key'] ?? '')) !== null,
            'episode' => ($entry = $this->store->getEntryById((int) ($decoded['id'] ?? 0))) !== null
                && isset($this->roots()[(string) $entry['root_key']]),
            default => false,
        };

        if (!$exists) {
            return false;
        }

        $this->state->setPlayed($userId, $itemId, $played);

        return true;
    }

    public function imageUrl(string $itemId, string $imageType, ?int $index = null): ?string
    {
        $decoded = JellyfinIds::decode($itemId);
        if ($decoded === null) {
            return null;
        }

        $type = 'movie';
        $tmdbId = null;
        $imdbId = null;

        if ($decoded['kind'] === 'movie') {
            $group = $this->findMovieGroup((string) ($decoded['root'] ?? ''), (string) ($decoded['key'] ?? ''));
            if ($group === null) {
                return null;
            }
            $tmdbId = $group['tmdb_id'] ?? null;
            $imdbId = $group['imdb_id'] ?? null;
        } elseif ($decoded['kind'] === 'series') {
            $group = $this->findSeriesGroup((string) ($decoded['root'] ?? ''), (string) ($decoded['key'] ?? ''));
            if ($group === null) {
                return null;
            }
            $type = 'series';
            $tmdbId = $group['tmdb_id'] ?? null;
            $imdbId = $group['imdb_id'] ?? null;
        } elseif ($decoded['kind'] === 'episode') {
            $entry = $this->store->getEntryById((int) ($decoded['id'] ?? 0));
            if ($entry === null) {
                return null;
            }
            $type = 'series';
            $tmdbId = $entry['tmdb_id'] ?? null;
            $imdbId = $entry['imdb_id'] ?? null;
        } else {
            return null;
        }

        return $this->metadata->imageUrl($type, $tmdbId !== null ? (int) $tmdbId : null, $imdbId !== null ? (string) $imdbId : null, $imageType, $index);
    }

    private function groupsForRoot(string $rootKey, string $rootType, array $includeItemTypes): array
    {
        $groups = [];
        $allowMovies = $rootType === 'movie' || $rootType === 'mixed';
        $allowSeries = in_array($rootType, ['series', 'anime', 'mixed'], true);
        $wantMovies = $allowMovies && ($includeItemTypes === [] || in_array('movie', $includeItemTypes, true));
        $wantSeries = $allowSeries && ($includeItemTypes === [] || in_array('series', $includeItemTypes, true));

        if ($wantMovies) {
            $groups = array_merge($groups, array_values($this->movieGroups($rootKey)));
        }
        if ($wantSeries) {
            $groups = array_merge($groups, array_values($this->seriesGroups($rootKey)));
        }

        return $groups;
    }

    private function seriesEpisodes(
        array $decoded,
        string $userId,
        ?int $season,
        bool $includeUserData,
        bool $includeImages,
        bool $includeMetadata,
        int $startIndex,
        int $limit
    ): array {
        $group = $this->findSeriesGroup((string) ($decoded['root'] ?? ''), (string) ($decoded['key'] ?? ''));
        if ($group === null) {
            return ['Items' => [], 'TotalRecordCount' => 0];
        }

        $entries = array_values(array_filter($group['entries'], static function (array $entry) use ($season): bool {
            $displaySeason = $entry['_display_season'] ?? $entry['season'];
            return $season === null || (int) ($displaySeason ?? -1) === $season;
        }));

        usort($entries, [$this, 'compareEpisodes']);
        $total = count($entries);
        $entries = array_slice($entries, max(0, $startIndex), max(1, $limit));

        $items = [];
        foreach ($entries as $entry) {
            $item = $this->buildEpisodeItem($entry, $group, $userId, false, $includeUserData, $includeImages, $includeMetadata);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return [
            'Items' => $items,
            'TotalRecordCount' => $total,
        ];
    }

    private function buildMovieItem(
        array $group,
        string $userId,
        bool $includeMediaSources,
        bool $includeUserData,
        bool $includeImages,
        bool $includeMetadata
    ): array {
        $itemId = JellyfinIds::movie((string) $group['root'], (string) $group['key']);
        $name = $this->groupName($group);
        $item = [
            'Id' => $itemId,
            'Name' => $name,
            'Type' => 'Movie',
            'LocationType' => 'FileSystem',
            'ProductionYear' => $group['year'] !== null ? (int) $group['year'] : null,
            'ProviderIds' => $this->providerIds($group['tmdb_id'] ?? null, $group['imdb_id'] ?? null),
            'SortName' => $name,
        ];

        if ($includeMetadata) {
            $this->applyMetadata($item, 'movie', $group, $includeImages, true);
        }

        if ($includeMediaSources) {
            $item['MediaSources'] = $this->media->mediaSources($group['entries'], $itemId);
        }

        if ($includeUserData) {
            $item['UserData'] = $this->userData($userId, $itemId);
        }

        return array_filter($item, static fn ($value) => $value !== null && $value !== []);
    }

    private function buildSeriesItem(
        array $group,
        string $userId,
        bool $includeUserData,
        bool $includeImages,
        bool $includeMetadata
    ): array {
        $itemId = JellyfinIds::series((string) $group['root'], (string) $group['key']);
        $name = $this->groupName($group);
        $item = [
            'Id' => $itemId,
            'Name' => $name,
            'Type' => 'Series',
            'ProductionYear' => $group['year'] !== null ? (int) $group['year'] : null,
            'ProviderIds' => $this->providerIds($group['tmdb_id'] ?? null, $group['imdb_id'] ?? null, $group['tvdb_id'] ?? null),
            'Status' => 'Continuing',
            'SortName' => $name,
        ];

        if ($includeMetadata) {
            $this->applyMetadata($item, 'series', $group, $includeImages, false);
        }

        if ($includeUserData) {
            $item['UserData'] = $this->userData($userId, $itemId);
        }

        return array_filter($item, static fn ($value) => $value !== null && $value !== []);
    }

    private function buildEpisodeFromEntryId(
        int $entryId,
        string $userId,
        bool $includeMediaSources,
        bool $includeUserData,
        bool $includeImages,
        bool $includeMetadata
    ): ?array {
        $entry = $this->store->getEntryById($entryId);
        if ($entry === null || !isset($this->roots()[(string) $entry['root_key']])) {
            return null;
        }

        $path = $this->seriesKey($entry);
        $group = $this->findSeriesGroup((string) $entry['root_key'], $path);
        if ($group !== null) {
            $entry = $this->decorateEntry($entry, $group);
        }

        return $this->buildEpisodeItem($entry, $group, $userId, $includeMediaSources, $includeUserData, $includeImages, $includeMetadata);
    }

    private function buildEpisodeItem(
        array $entry,
        ?array $group,
        string $userId,
        bool $includeMediaSources,
        bool $includeUserData,
        bool $includeImages,
        bool $includeMetadata
    ): ?array {
        $entryId = (int) ($entry['id'] ?? 0);
        if ($entryId <= 0) {
            return null;
        }

        $itemId = JellyfinIds::episode($entryId);
        $displaySeason = $entry['_display_season'] ?? $entry['season'];
        $displayEpisode = $entry['_display_episode'] ?? $entry['episode'];
        $item = [
            'Id' => $itemId,
            'Name' => $this->episodeName($entry),
            'Type' => 'Episode',
            'LocationType' => 'FileSystem',
            'IndexNumber' => $displayEpisode !== null ? (int) $displayEpisode : null,
            'ParentIndexNumber' => $displaySeason !== null ? (int) $displaySeason : null,
            'ProductionYear' => $entry['year'] !== null ? (int) $entry['year'] : null,
            'SortName' => $this->episodeName($entry),
        ];

        if ($group !== null) {
            $seriesId = JellyfinIds::series((string) $group['root'], (string) $group['key']);
            $item['SeriesId'] = $seriesId;
            $item['SeriesName'] = (string) ($group['title'] ?? '');

            if ($includeMetadata) {
                $details = $this->tmdbDetails('series', $group['tmdb_id'] ?? null, $group['imdb_id'] ?? null);
                if ($details !== null) {
                    $runtime = $this->metadata->runtimeTicks('series', $details);
                    if ($runtime !== null && $runtime > 0) {
                        $item['RunTimeTicks'] = $runtime;
                    }
                    if ($includeImages) {
                        $this->applyImageTags($item, $details);
                        if (!empty($item['ImageTags']['Primary'])) {
                            $item['SeriesPrimaryImageTag'] = $item['ImageTags']['Primary'];
                        }
                    }
                }
            }
        }

        if ($includeMediaSources) {
            $item['MediaSources'] = $this->media->mediaSources([$entry], $itemId);
        }

        if ($includeUserData) {
            $item['UserData'] = $this->userData($userId, $itemId);
        }

        return array_filter($item, static fn ($value) => $value !== null && $value !== []);
    }

    private function applyMetadata(array &$item, string $type, array $group, bool $includeImages, bool $applyRuntime): void
    {
        $details = $this->tmdbDetails($type, $group['tmdb_id'] ?? null, $group['imdb_id'] ?? null);
        if ($details === null) {
            return;
        }

        if (empty($item['Name']) && !empty($details['title'])) {
            $item['Name'] = (string) $details['title'];
        }
        if (!empty($details['overview'])) {
            $item['Overview'] = (string) $details['overview'];
        }
        if (!empty($details['genres'])) {
            $item['Genres'] = array_values(array_filter((array) $details['genres'], 'is_string'));
        }
        if (isset($details['rating']) && is_numeric($details['rating'])) {
            $item['CommunityRating'] = (float) $details['rating'];
        }
        if (empty($item['ProductionYear']) && !empty($details['year'])) {
            $item['ProductionYear'] = (int) $details['year'];
        }
        if (empty($item['ProviderIds']['Imdb']) && !empty($details['imdb_id'])) {
            $item['ProviderIds']['Imdb'] = (string) $details['imdb_id'];
        }

        if ($applyRuntime) {
            $runtime = $this->metadata->runtimeTicks($type, $details);
            if ($runtime !== null && $runtime > 0) {
                $item['RunTimeTicks'] = $runtime;
            }
        }

        if ($includeImages) {
            $this->applyImageTags($item, $details);
        }
    }

    private function applyImageTags(array &$item, array $details): void
    {
        if (!empty($details['poster'])) {
            $item['ImageTags'] = ['Primary' => $this->imageTag((string) $details['poster'])];
        }
        if (!empty($details['backdrop'])) {
            $item['BackdropImageTags'] = [$this->imageTag((string) $details['backdrop'])];
        }
    }

    private function tmdbDetails(string $type, ?int $tmdbId, ?string $imdbId): ?array
    {
        $key = $type . '|' . ($tmdbId ?? '') . '|' . ($imdbId ?? '');
        if (array_key_exists($key, $this->metadataCache)) {
            return $this->metadataCache[$key];
        }

        $details = $this->metadata->details($type, $tmdbId, $imdbId);
        $this->metadataCache[$key] = $details;

        return $details;
    }

    private function userData(string $userId, string $itemId): array
    {
        $state = $this->state->get($userId, $itemId);

        return [
            'PlaybackPositionTicks' => (int) ($state['position_ticks'] ?? 0),
            'Played' => (bool) ($state['played'] ?? false),
            'LastPlayedDate' => $state['last_played'] ?? null,
        ];
    }

    private function entriesForItem(array $decoded): array
    {
        return match ($decoded['kind']) {
            'movie' => ($group = $this->findMovieGroup((string) ($decoded['root'] ?? ''), (string) ($decoded['key'] ?? ''))) !== null
                ? $group['entries']
                : [],
            'episode' => ($entry = $this->store->getEntryById((int) ($decoded['id'] ?? 0))) !== null
                && isset($this->roots()[(string) $entry['root_key']])
                ? [$entry]
                : [],
            default => [],
        };
    }

    private function findMovieGroup(string $rootKey, string $key): ?array
    {
        if (!isset($this->roots()[$rootKey])) {
            return null;
        }

        return $this->movieGroups($rootKey)[$key] ?? null;
    }

    private function findSeriesGroup(string $rootKey, string $key): ?array
    {
        if (!isset($this->roots()[$rootKey])) {
            return null;
        }

        $groups = $this->seriesGroups($rootKey);
        if (isset($groups[$key])) {
            return $groups[$key];
        }

        if (str_starts_with($key, 'folder:') || str_starts_with($key, 'title:')) {
            foreach ($groups as $group) {
                foreach ($group['entries'] as $entry) {
                    if ($this->legacySeriesKey($entry) === $key) {
                        return $group;
                    }
                }
            }
        }

        return null;
    }

    private function movieGroups(string $rootKey): array
    {
        if (isset($this->movieGroupCache[$rootKey])) {
            return $this->movieGroupCache[$rootKey];
        }

        $groups = [];
        foreach ($this->entries($rootKey) as $entry) {
            if ((string) ($entry['media_type'] ?? '') !== 'movie') {
                continue;
            }

            $key = $this->movieKey($entry);
            if (!isset($groups[$key])) {
                $groups[$key] = $this->newGroup('movie', $rootKey, $key);
            }
            $groups[$key]['entries'][] = $entry;
            $this->updateGroup($groups[$key], $entry);
        }

        foreach ($groups as &$group) {
            usort($group['entries'], static fn ($a, $b) => (int) ($b['size'] ?? 0) <=> (int) ($a['size'] ?? 0));
        }
        unset($group);

        $this->movieGroupCache[$rootKey] = $groups;

        return $groups;
    }

    private function seriesGroups(string $rootKey): array
    {
        if (isset($this->seriesGroupCache[$rootKey])) {
            return $this->seriesGroupCache[$rootKey];
        }

        $groups = [];
        foreach ($this->entries($rootKey) as $entry) {
            if (!in_array((string) ($entry['media_type'] ?? ''), ['series', 'anime'], true)) {
                continue;
            }

            $key = $this->seriesKey($entry);
            if (!isset($groups[$key])) {
                $groups[$key] = $this->newGroup('series', $rootKey, $key);
            }
            $groups[$key]['entries'][] = $entry;
            $this->updateGroup($groups[$key], $entry);
        }

        foreach ($groups as &$group) {
            $this->applySeriesNumbering($group);
            usort($group['entries'], [$this, 'compareEpisodes']);
        }
        unset($group);

        $this->seriesGroupCache[$rootKey] = $groups;

        return $groups;
    }

    private function newGroup(string $kind, string $rootKey, string $key): array
    {
        return [
            'kind' => $kind,
            'root' => $rootKey,
            'key' => $key,
            'title' => '',
            'year' => null,
            'tmdb_id' => null,
            'imdb_id' => null,
            'sort_date' => '',
            'entries' => [],
        ];
    }

    private function updateGroup(array &$group, array $entry): void
    {
        if ($group['title'] === '') {
            $group['title'] = (string) ($entry['title'] ?? '');
        }
        if ($group['year'] === null && $entry['year'] !== null) {
            $group['year'] = (int) $entry['year'];
        }
        if ($group['tmdb_id'] === null && !empty($entry['tmdb_id'])) {
            $group['tmdb_id'] = (int) $entry['tmdb_id'];
        }
        if ($group['imdb_id'] === null && !empty($entry['imdb_id'])) {
            $group['imdb_id'] = (string) $entry['imdb_id'];
        }

        $date = (string) ($entry['last_seen_at'] ?? $entry['first_seen_at'] ?? '');
        if ($date > $group['sort_date']) {
            $group['sort_date'] = $date;
        }
    }

    private function movieKey(array $entry): string
    {
        if (!empty($entry['tmdb_id'])) {
            return 'tmdb:' . (int) $entry['tmdb_id'];
        }
        if (!empty($entry['imdb_id'])) {
            return 'imdb:' . (string) $entry['imdb_id'];
        }

        return 'title:' . (string) ($entry['title_normalized'] ?? '') . '|' . ($entry['year'] ?? '');
    }

    private function applySeriesNumbering(array &$group): void
    {
        $profile = $this->seriesNumberingProfile($group);
        $group['tvdb_id'] = $this->tvdbIdFromProfile($profile);
        $mode = (string) ($profile['jellyfin_mode'] ?? 'local');
        foreach ($group['entries'] as &$entry) {
            $entry = $this->decorateEntry($entry, $group, $profile, $mode);
        }
        unset($entry);
        $group['numbering_profile'] = $profile;
        $group['numbering_mode'] = $mode;
    }

    private function decorateEntry(array $entry, ?array $group, ?array $profile = null, ?string $mode = null): array
    {
        if ($profile === null && $group !== null) {
            $profile = $this->seriesNumberingProfile($group);
        }
        $mode = $mode ?? (string) ($profile['jellyfin_mode'] ?? 'local');
        $season = isset($entry['season']) ? (int) $entry['season'] : null;
        $episode = isset($entry['episode']) ? (int) $entry['episode'] : null;
        $display = ['season' => $season, 'episode' => $episode];
        if ($mode === 'tmdb' && $this->numbering !== null && $profile !== null && $season !== null && $episode !== null) {
            $canonical = $this->numbering->canonicalFor($profile, $season, $episode);
            if ($canonical !== null) {
                $display = $canonical;
            }
        }
        $entry['_display_season'] = $display['season'];
        $entry['_display_episode'] = $display['episode'];
        $entry['_numbering_mode'] = $mode;

        return $entry;
    }

    private function seriesNumberingProfile(array $group): ?array
    {
        if ($this->numbering === null) {
            return null;
        }
        $tmdbId = !empty($group['tmdb_id']) ? (int) $group['tmdb_id'] : null;
        $imdbId = trim((string) ($group['imdb_id'] ?? ''));
        if ($tmdbId === null && $imdbId === '') {
            return null;
        }
        $profile = $this->numbering->profileFor([
            'tmdb_id' => $tmdbId,
            'imdb_id' => $imdbId,
            'title' => (string) ($group['title'] ?? ''),
            'year' => $group['year'] ?? null,
        ], false, false);

        return $this->numbering->isUsable($profile) ? $profile : null;
    }

    private function seriesKey(array $entry): string
    {
        if (!empty($entry['tmdb_id'])) {
            return 'tmdb:' . (int) $entry['tmdb_id'];
        }
        if (!empty($entry['imdb_id'])) {
            return 'imdb:' . (string) $entry['imdb_id'];
        }

        return $this->legacySeriesKey($entry);
    }

    private function legacySeriesKey(array $entry): string
    {
        $showFolder = trim((string) ($entry['show_folder'] ?? ''));
        if ($showFolder !== '') {
            return 'folder:' . $showFolder;
        }

        return 'title:' . (string) ($entry['title_normalized'] ?? '') . '|' . ($entry['year'] ?? '');
    }

    private function compareEpisodes(array $a, array $b): int
    {
        $season = (int) ($a['_display_season'] ?? $a['season'] ?? -1) <=> (int) ($b['_display_season'] ?? $b['season'] ?? -1);
        if ($season !== 0) {
            return $season;
        }

        $episode = (int) ($a['_display_episode'] ?? $a['episode'] ?? -1) <=> (int) ($b['_display_episode'] ?? $b['episode'] ?? -1);
        if ($episode !== 0) {
            return $episode;
        }

        return strcmp((string) ($a['relative_path'] ?? ''), (string) ($b['relative_path'] ?? ''));
    }

    private function sortGroups(array &$groups, string $sortBy, string $sortOrder): void
    {
        $byDate = str_contains(strtolower($sortBy), 'datecreated');
        usort($groups, static function (array $a, array $b) use ($byDate): int {
            if ($byDate) {
                return strcmp((string) ($a['sort_date'] ?? ''), (string) ($b['sort_date'] ?? ''));
            }

            return strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
        });

        if (strtolower($sortOrder) === 'descending') {
            $groups = array_reverse($groups);
        }
    }

    private function groupName(array $group): string
    {
        $name = trim((string) ($group['title'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $entry = $group['entries'][0] ?? [];
        $filename = (string) ($entry['filename'] ?? '');
        $name = $filename !== '' ? pathinfo($filename, PATHINFO_FILENAME) : '';

        return $name !== '' ? $name : 'Unknown';
    }

    private function episodeName(array $entry): string
    {
        $season = $entry['_display_season'] ?? $entry['season'];
        $episode = $entry['_display_episode'] ?? $entry['episode'];
        if ($season !== null && $episode !== null) {
            return sprintf('S%02dE%02d', (int) $season, (int) $episode);
        }

        $filename = (string) ($entry['filename'] ?? '');

        return $filename !== '' ? pathinfo($filename, PATHINFO_FILENAME) : 'Episode';
    }

    private function tvdbIdFromProfile(?array $profile): ?int
    {
        $sourceRef = trim((string) ($profile['source_ref'] ?? ''));
        if (preg_match('/^tvdb:(\d+)$/i', $sourceRef, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function providerIds($tmdbId, $imdbId, $tvdbId = null): array
    {
        $ids = [];
        if ($tmdbId !== null && (int) $tmdbId > 0) {
            $ids['Tmdb'] = (string) (int) $tmdbId;
        }
        if (!empty($imdbId)) {
            $ids['Imdb'] = (string) $imdbId;
        }
        if ($tvdbId !== null && (int) $tvdbId > 0) {
            $ids['Tvdb'] = (string) (int) $tvdbId;
        }

        return $ids;
    }

    private function imageTag(string $url): string
    {
        return substr(sha1($url), 0, 16);
    }

    private function roots(): array
    {
        if ($this->roots !== null) {
            return $this->roots;
        }

        $this->roots = [];
        foreach ($this->settings->getEnabledRoots() as $key => $root) {
            if (is_array($root) && !empty($root['path'])) {
                $this->roots[(string) $key] = $root;
            }
        }

        return $this->roots;
    }

    private function entries(string $rootKey): array
    {
        if (!isset($this->roots()[$rootKey])) {
            return [];
        }

        if (!isset($this->entryCache[$rootKey])) {
            $this->entryCache[$rootKey] = $this->store->getActiveEntriesByRoot($rootKey);
        }

        return $this->entryCache[$rootKey];
    }

    private function collectionType(string $rootType): string
    {
        return match ($rootType) {
            'movie' => 'movies',
            'series', 'anime' => 'tvshows',
            default => 'mixed',
        };
    }

    private function fieldsWantMetadata(string $fields): bool
    {
        $fields = strtolower($fields);

        return str_contains($fields, 'overview')
            || str_contains($fields, 'genres')
            || str_contains($fields, 'people')
            || str_contains($fields, 'studios')
            || str_contains($fields, 'premieredate');
    }

    private function includeItemTypes(string $value): array
    {
        $types = array_map(static fn ($type) => strtolower(trim($type)), explode(',', $value));

        return array_values(array_filter($types, static fn ($type) => $type !== ''));
    }

    private function queryString(array $query, string $key, string $default = ''): string
    {
        $value = $query[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    private function queryInt(array $query, string $key, int $default): int
    {
        $value = $query[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    private function queryIsFalse(array $query, string $key): bool
    {
        $value = strtolower($this->queryString($query, $key, 'true'));

        return in_array($value, ['false', '0', 'no'], true);
    }
}
