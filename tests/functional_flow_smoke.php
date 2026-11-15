<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Command\LibraryReconcileMissingCommand;
use App\Command\ReleaseIntentReconcileCommand;
use App\Service\Automation\AutomationNotifier;
use App\Service\Automation\AutomationSettings;
use App\Service\Automation\AutomationStore;
use App\Service\Automation\EpisodeNumberingService;
use App\Service\Automation\LibraryIndexService;
use App\Service\Automation\LibraryRenameService;
use App\Service\Automation\LibraryNameParser;
use App\Service\Automation\LibraryTitleRefreshService;
use App\Service\Automation\ListMatcher;
use App\Service\Automation\ListSourceClientInterface;
use App\Service\Automation\ListSourceRegistry;
use App\Service\Automation\ListSyncService;
use App\Service\Automation\MdbListClient;
use App\Service\Automation\MovieIdentityResolverInterface;
use App\Service\Automation\ReleaseDownloaderInterface;
use App\Service\Automation\ReleaseDryRunService;
use App\Service\Automation\ReleaseIntentService;
use App\Service\Automation\ReleaseIntegrityService;
use App\Service\Automation\ReleaseMetadataParser;
use App\Service\Automation\ReleasePreferenceService;
use App\Service\Automation\ReleaseResolverService;
use App\Service\Automation\ReleaseStreamProviderInterface;
use App\Service\Automation\ScrobListClient;
use App\Service\Automation\SeriesIdentityResolverInterface;
use App\Service\Automation\SeriesNumberingProviderInterface;
use App\Service\Automation\TmdbEpisodeCatalog;
use App\Service\JsonStorage;
use App\Service\Jellyfin\JellyfinIds;
use App\Service\Jellyfin\JellyfinLibraryService;
use App\Service\Jellyfin\JellyfinMediaService;
use App\Service\Jellyfin\JellyfinMetadataService;
use App\Service\Jellyfin\JellyfinStateService;
use App\Service\MediaDownloadService;
use App\Service\TmdbService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;

$passes = 0;
$failures = 0;

function check(bool $condition, string $label, mixed $actual = null): void
{
    global $passes, $failures;
    if ($condition) {
        $passes++;
        echo "  ok  $label\n";
        return;
    }
    $failures++;
    echo "  FAIL $label" . ($actual !== null ? ' (actual: ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ')' : '') . "\n";
}

function eq(mixed $expected, mixed $actual, string $label): void
{
    check($expected === $actual, $label . ' => ' . json_encode($expected, JSON_UNESCAPED_UNICODE), $actual);
}

function tempProject(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(4));
    mkdir($dir . '/var/db', 0777, true);
    mkdir($dir . '/var/storage', 0777, true);
    mkdir($dir . '/nas', 0777, true);

    return $dir;
}

function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dir);
}

function storageAt(string $dir): JsonStorage
{
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $storage = (new ReflectionClass(JsonStorage::class))->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(JsonStorage::class, 'storageDir');
    $property->setAccessible(true);
    $property->setValue($storage, $dir);

    return $storage;
}

function writeFile(string $path, string $content = 'video'): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $content);
}

function writeSparse(string $path, int $size, string $head = '<mkv>'): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    $handle = fopen($path, 'wb');
    fwrite($handle, $head);
    ftruncate($handle, $size);
    fclose($handle);
}

function addHistory(JsonStorage $storage, string $downloadId, string $path, int $size, bool $withActual = true): void
{
    $history = $storage->get('history', []);
    $entry = [
        'download_id' => $downloadId,
        'filename' => basename($path),
        'path' => dirname($path),
        'size' => $size,
        'status' => 'success',
        'date' => date('Y-m-d H:i:s'),
    ];
    if ($withActual) {
        $entry['actual_size'] = $size;
    }
    $history[] = $entry;
    $storage->set('history', $history);
}

function normalizeTitle(string $title): string
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

function seedListRun(AutomationStore $store, string $sourceId = 'scrob', int $itemsSeen = 1): int
{
    $runId = $store->startListRun($sourceId, 'scrob', 'dry_run');
    $store->finishListRun($runId, ['items_seen' => $itemsSeen, 'missing' => $itemsSeen], 'success');

    return $runId;
}

function seedListItem(AutomationStore $store, int $runId, array $item, ?array $match = null): int
{
    $item = array_merge([
        'source_id' => 'scrob',
        'provider' => 'scrob',
        'list_ref' => '1',
        'title_normalized' => normalizeTitle((string) ($item['title'] ?? '')),
        'season' => null,
        'episode' => null,
        'raw' => [],
    ], $item);
    $store->upsertListItem($item, $runId);
    $stmt = $store->getPdo()->prepare('SELECT id FROM list_items WHERE source_id = :source AND remote_key = :key LIMIT 1');
    $stmt->execute([':source' => (string) $item['source_id'], ':key' => (string) ($item['remote_key'] ?? '')]);
    $id = (int) $stmt->fetchColumn();
    if ($match !== null) {
        $store->updateListItemMatch($id, $match, $runId);
    }

    return $id;
}

function addLibraryEntry(AutomationStore $store, array $entry): int
{
    $entry = array_merge([
        'season' => null,
        'episode' => null,
        'year' => null,
        'tmdb_id' => null,
        'imdb_id' => null,
        'quality' => '1080p',
        'size' => 1024,
        'mtime' => 100,
        'confidence' => 0.99,
        'parse_source' => 'filename',
        'parser_version' => LibraryNameParser::VERSION,
        'scan_id' => 1,
        'show_folder' => null,
        'extension' => 'mkv',
    ], $entry);
    $store->upsertLibraryEntry($entry);
    $stmt = $store->getPdo()->prepare('SELECT id FROM library_entries WHERE path = :path LIMIT 1');
    $stmt->execute([':path' => (string) $entry['path']]);

    return (int) $stmt->fetchColumn();
}

function movieStream(string $filename = 'Dune.Part.Two.2024.1080p.WEB-DL.x265.VOSTFR', string $hashChar = 'a', int $seeders = 50): array
{
    return [
        'addon' => 'Fake Addon',
        'addon_id' => 'fake',
        'priority' => 1,
        'name' => $filename,
        'title' => $filename,
        'info_hash' => str_repeat($hashChar, 40),
        'file_idx' => null,
        'url' => null,
        'kind' => 'torrent',
        'filename' => $filename . '.mkv',
        'sources' => [],
        'size' => '2 GB',
        'seeders' => $seeders,
    ];
}

function seriesStream(string $filename, string $hashChar = 'b', int $seeders = 50, string $size = '1.2 GB'): array
{
    return [
        'addon' => 'Fake Addon',
        'addon_id' => 'fake',
        'priority' => 1,
        'name' => $filename,
        'title' => $filename,
        'info_hash' => str_repeat($hashChar, 40),
        'file_idx' => null,
        'url' => null,
        'kind' => 'torrent',
        'filename' => $filename . '.mkv',
        'sources' => [],
        'size' => $size,
        'seeders' => $seeders,
    ];
}

final class FunctionalFakeTmdb extends TmdbService
{
    public array $detailsMap = [];
    public array $seasonsMap = [];
    public array $calls = [];

    public function __construct()
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function getLanguage(): string
    {
        return 'en-US';
    }

    public function details(string $type, int $id, ?string $language = null): ?array
    {
        $this->calls[] = $type . '|' . $id;

        return $this->detailsMap[$id] ?? null;
    }

    public function season(int $tvId, int $seasonNumber): array
    {
        return $this->seasonsMap[$tvId][$seasonNumber] ?? [];
    }
}

final class FunctionalFakeListSourceClient implements ListSourceClientInterface
{
    public array $items;
    public array $warnings;

    public function __construct(private string $providerName, array $items, array $warnings = [])
    {
        $this->items = $items;
        $this->warnings = $warnings;
    }

    public function provider(): string
    {
        return $this->providerName;
    }

    public function isConfigured(array $source): bool
    {
        return true;
    }

    public function canTest(array $source): bool
    {
        return true;
    }

    public function test(array $source): array
    {
        return [
            'success' => true,
            'message' => 'Connection OK',
            'lists' => [['ref' => '1', 'name' => 'Smoke list', 'count' => count($this->items)]],
        ];
    }

    public function fetch(array $source, int $maxItems = 500): array
    {
        return [
            'items' => array_slice($this->items, 0, $maxItems),
            'lists' => [['ref' => '1', 'name' => 'Smoke list', 'count' => count($this->items)]],
            'warnings' => $this->warnings,
        ];
    }
}

final class FunctionalIdentityResolver implements MovieIdentityResolverInterface, SeriesIdentityResolverInterface
{
    public array $identity = [];

    public function resolve(array $intent): array
    {
        return $this->identity;
    }
}

final class FunctionalStreamProvider implements ReleaseStreamProviderInterface
{
    public array $streamsByType = [];
    public array $calls = [];

    public function getStreams(string $type, string $videoId): array
    {
        $this->calls[] = $type . '|' . $videoId;

        return ['success' => true, 'streams' => $this->streamsByType[$type] ?? [], 'errors' => []];
    }
}

final class FunctionalDownloader implements ReleaseDownloaderInterface
{
    public array $payloads = [];

    public function addStream(array $payload, ?callable $heartbeat = null): array
    {
        $this->payloads[] = $payload;

        return [
            'success' => true,
            'message' => 'Added to the download queue.',
            'download_id' => 'functional-dl-' . count($this->payloads),
            'filename' => $payload['override_filename'] ?? null,
            'path' => $payload['path'] ?? null,
            'size' => 0,
            'file_index' => isset($payload['file_idx']) ? (int) $payload['file_idx'] : 0,
            'addon' => $payload['addon'] ?? null,
        ];
    }
}

final class FunctionalEpisodeCatalog extends TmdbEpisodeCatalog
{
    public array $episodesData = [];

    public function __construct()
    {
    }

    public function episodes(int $tmdbId, ?bool $includeSpecials = null, ?bool $includeUnaired = null): array
    {
        return $this->episodesData;
    }
}

final class FunctionalSeriesNumberingProvider implements SeriesNumberingProviderInterface
{
    public array $result = [];

    public function fetch(array $identity, bool $refresh = false): array
    {
        return $this->result;
    }
}

final class FunctionalJellyfinMetadataService extends JellyfinMetadataService
{
    public function __construct()
    {
    }

    public function details(string $type, ?int $tmdbId, ?string $imdbId = null): ?array
    {
        if ($type === 'movie' && $tmdbId === 693134) {
            return [
                'id' => 693134,
                'type' => 'movie',
                'title' => 'Dune: Part Two',
                'overview' => 'Paul Atreides unites with the Fremen.',
                'genres' => ['Science Fiction'],
                'rating' => 8.5,
                'year' => '2024',
                'poster' => 'https://image.tmdb.org/t/p/w342/dune.jpg',
                'backdrop' => 'https://image.tmdb.org/t/p/w780/dune-backdrop.jpg',
                'runtime' => 166,
                'imdb_id' => 'tt15239678',
            ];
        }

        if ($type === 'series' && $tmdbId === 900001) {
            return [
                'id' => 900001,
                'type' => 'series',
                'title' => 'Split Show',
                'overview' => 'A split numbering smoke series.',
                'genres' => ['Drama'],
                'rating' => 8.0,
                'year' => '2024',
                'poster' => 'https://image.tmdb.org/t/p/w342/split.jpg',
                'backdrop' => 'https://image.tmdb.org/t/p/w780/split-backdrop.jpg',
                'episode_runtime' => 45,
                'imdb_id' => 'tt9000001',
            ];
        }

        return null;
    }
}

final class FunctionalJellyfinNumberingService extends EpisodeNumberingService
{
    public string $mode = 'local';

    public function __construct()
    {
    }

    public function profileFor(array $identity, bool $refresh = false, bool $detectIfNeeded = true): ?array
    {
        if ((int) ($identity['tmdb_id'] ?? 0) !== 900001) {
            return null;
        }

        return [
            'id' => 1,
            'tmdb_id' => 900001,
            'imdb_id' => 'tt9000001',
            'title' => 'Split Show',
            'source' => 'manual',
            'status' => 'ok',
            'confidence' => 1.0,
            'jellyfin_mode' => $this->mode,
            'target_mode' => 'local',
            'segments' => [['season' => 1, 'episodes' => 25], ['season' => 2, 'episodes' => 23]],
            'mapping' => [[
                'tmdb_season' => 1,
                'tmdb_episode' => 25,
                'release_season' => 2,
                'release_episode' => 1,
                'title' => 'Episode 25',
                'air_date' => '2024-01-25',
            ]],
        ];
    }

    public function isUsable(?array $profile): bool
    {
        return $profile !== null && (string) ($profile['status'] ?? '') === 'ok';
    }

    public function canonicalFor(?array $profile, int $season, int $episode): ?array
    {
        if ($season === 2 && $episode === 1) {
            return ['season' => 1, 'episode' => 25];
        }

        return null;
    }
}

echo "A. Missing movie: list sync, dry-run, queue, resolve, fake scan\n";
$projectA = tempProject('functional-a');
$moviesRootA = $projectA . '/nas/Movies';
$seriesRootA = $projectA . '/nas/TV Shows';
$animeRootA = $projectA . '/nas/Anime';
foreach ([$moviesRootA, $seriesRootA, $animeRootA] as $dir) {
    mkdir($dir, 0777, true);
}
$storeA = new AutomationStore($projectA);
$settingsA = new AutomationSettings($storeA);
$storageA = storageAt($projectA . '/var/storage');
$sourceA = [
    'id' => 'scrob',
    'provider' => 'scrob',
    'label' => 'Scrob',
    'enabled' => true,
    'config' => ['api_key' => 'test', 'list_ids' => ['1'], 'auto_download' => true, 'auto_download_series' => false],
];
$settingsA->save([
    'roots' => [
        'movies' => ['label' => 'Movies', 'path' => $moviesRootA, 'type' => 'movie', 'enabled' => true],
        'series' => ['label' => 'TV Shows', 'path' => $seriesRootA, 'type' => 'series', 'enabled' => true],
        'anime' => ['label' => 'Anime', 'path' => $animeRootA, 'type' => 'anime', 'enabled' => false],
    ],
    'sources' => [$sourceA],
    'scanner' => ['min_size_mb' => 0, 'read_nfo' => false, 'skip_samples' => false, 'extensions' => ['mkv']],
    'backfill' => ['max_intents_per_run' => 10, 'queue_guard' => 20],
]);
$tmdbA = new FunctionalFakeTmdb();
$tmdbA->detailsMap[693134] = ['id' => 693134, 'type' => 'movie', 'title' => 'Dune Part Two', 'year' => 2024, 'imdb_id' => 'tt15239678'];
$listClientA = new FunctionalFakeListSourceClient('scrob', [[
    'list_ref' => '1',
    'remote_id' => 'dune',
    'media_type' => 'movie',
    'tmdb_id' => 693134,
    'imdb_id' => 'tt15239678',
    'title' => 'Dune Part Two',
    'year' => 2024,
    'season' => null,
    'episode' => null,
    'raw' => [],
]]);
$registryA = new ListSourceRegistry(new ScrobListClient(), new MdbListClient());
$registryA->register($listClientA);
$matcherA = new ListMatcher($storeA, $settingsA, $tmdbA);
$listSyncA = new ListSyncService($storeA, $settingsA, $matcherA, $registryA, $projectA);
$syncA = $listSyncA->sync(['scrob']);
check(!empty($syncA['success']), 'A list sync succeeds', $syncA);
eq(1, $syncA['stats']['items_seen'] ?? null, 'A list sync sees one movie');
eq(1, $syncA['stats']['missing'] ?? null, 'A movie is missing from the fake library');

$identityMovieA = new FunctionalIdentityResolver();
$identityMovieA->identity = ['success' => true, 'tmdb_id' => 693134, 'imdb_id' => 'tt15239678', 'title' => 'Dune Part Two', 'year' => 2024, 'reason' => null];
$identitySeriesA = new FunctionalIdentityResolver();
$identitySeriesA->identity = ['success' => false, 'reason' => 'missing_identity'];
$streamsA = new FunctionalStreamProvider();
$streamsA->streamsByType['movie'] = [movieStream()];
$downloaderA = new FunctionalDownloader();
$preferencesA = new ReleasePreferenceService(new ReleaseMetadataParser(), $settingsA);
$resolverA = new ReleaseResolverService($storeA, $settingsA, $preferencesA, $streamsA, $downloaderA, $identityMovieA, $identitySeriesA, $storageA);
$notifierA = new AutomationNotifier($storageA);
$intentsA = new ReleaseIntentService($storeA, $settingsA, $resolverA, $notifierA, $storageA, $projectA);
$dryRunA = new ReleaseDryRunService($storeA, $settingsA, $resolverA, $storageA, $projectA);
$dryA = $dryRunA->run(10, 'scrob');
check(!empty($dryA['success']), 'A release dry-run succeeds', $dryA['message'] ?? null);
eq(1, $dryA['state']['ok'] ?? null, 'A dry-run finds one usable release');
$queuedA = $intentsA->queueMissingMovies(false);
eq(1, $queuedA['stats']['created'], 'A movie intent is created after dry-run');
$intentA = $storeA->getPdo()->query("SELECT * FROM intents WHERE media_key = 'movie:tmdb:693134' LIMIT 1")->fetch();
check($intentA !== false, 'A movie intent is persisted');
$resolvedA = $resolverA->processIntent($intentA);
eq('queued', $resolvedA['status'], 'A movie intent is resolved and queued');
$payloadA = end($downloaderA->payloads);
eq($moviesRootA . '/Dune Part Two (2024)', $payloadA['path'], 'A movie destination uses the movie root');
eq('Dune Part Two (2024).mkv', $payloadA['override_filename'], 'A movie filename uses title and year');
eq('movie:tmdb:693134', $payloadA['automation_media_key'], 'A queue payload carries the automation media key');

$downloadPathA = $payloadA['path'] . '/' . $payloadA['override_filename'];
writeFile($downloadPathA, str_repeat('m', 2048));
addHistory($storageA, 'functional-dl-a', $downloadPathA, filesize($downloadPathA));
$intentsA->onDownloadFinished([
    'automation_intent_id' => (int) $intentA['id'],
    'download_id' => 'functional-dl-a',
    'filename' => $payloadA['override_filename'],
], 'success');
eq('success', $storeA->getIntent((int) $intentA['id'])['status'], 'A simulated download marks the intent successful');
eq('movies', $storeA->getSetting('library_scan_requested_root'), 'A success requests a movie library scan');
$indexA = new LibraryIndexService($storeA, $settingsA, new LibraryNameParser(), $projectA);
$scanA = $indexA->scan(['movies']);
check(!empty($scanA['success']), 'A fake library scan succeeds', $scanA);
$entryA = $storeA->getPdo()->query("SELECT * FROM library_entries WHERE path = " . $storeA->getPdo()->quote($downloadPathA) . " LIMIT 1")->fetch();
check($entryA !== false && (string) $entryA['status'] === 'active', 'A downloaded movie is indexed as active');
eq('Dune Part Two', (string) $entryA['title'], 'A scanned movie keeps its parsed title');
eq('movie', (string) $entryA['media_type'], 'A scanned movie keeps its media type');
$syncA2 = $listSyncA->sync(['scrob']);
check(!empty($syncA2['success']), 'A second list sync succeeds', $syncA2);
$listItemA = $storeA->getPdo()->query("SELECT match_status FROM list_items WHERE tmdb_id = 693134 LIMIT 1")->fetch();
eq('present', $listItemA['match_status'] ?? null, 'A library scan makes the list item present');
$queuedA2 = $intentsA->queueMissingMovies(false);
eq(0, $queuedA2['stats']['created'], 'A present movie does not create a new intent');
eq(1, $storeA->countIntents(['type' => 'movie']), 'A intent count stays stable');
removeTree($projectA);

echo "B. Split series 24/23: TMDB 1x47, TVDB 24/23, TMDB S01E25 -> release S02E01\n";
$projectB = tempProject('functional-b');
$seriesRootB = $projectB . '/nas/TV Shows';
$moviesRootB = $projectB . '/nas/Movies';
mkdir($seriesRootB, 0777, true);
mkdir($moviesRootB, 0777, true);
$storeB = new AutomationStore($projectB);
$settingsB = new AutomationSettings($storeB);
$storageB = storageAt($projectB . '/var/storage');
$settingsB->save([
    'roots' => [
        'movies' => ['label' => 'Movies', 'path' => $moviesRootB, 'type' => 'movie', 'enabled' => true],
        'series' => ['label' => 'TV Shows', 'path' => $seriesRootB, 'type' => 'series', 'enabled' => true],
        'anime' => ['label' => 'Anime', 'path' => $projectB . '/nas/Anime', 'type' => 'anime', 'enabled' => false],
    ],
    'sources' => [[
        'id' => 'scrob',
        'provider' => 'scrob',
        'label' => 'Scrob',
        'enabled' => true,
        'config' => ['api_key' => 'test', 'list_ids' => ['1'], 'auto_download_series' => true],
    ]],
    'backfill' => ['max_intents_per_run' => 50, 'queue_guard' => 20, 'exclude_specials' => true],
]);
$catalogB = new FunctionalEpisodeCatalog();
$canonicalB = [];
$providerVideosB = [];
for ($i = 1; $i <= 47; $i++) {
    $airDate = (new DateTimeImmutable('2024-01-01'))->modify('+' . ($i - 1) . ' days')->format('Y-m-d');
    $canonicalB[] = ['season' => 1, 'episode' => $i, 'title' => 'Episode ' . $i, 'air_date' => $airDate, 'ordinal' => $i];
    $providerVideosB[] = [
        'season' => $i <= 24 ? 1 : 2,
        'episode' => $i <= 24 ? $i : $i - 24,
        'title' => 'Episode ' . $i,
        'released' => $airDate,
        'id' => 'tt9000010:' . ($i <= 24 ? '1:' . $i : '2:' . ($i - 24)),
    ];
}
$catalogB->episodesData = $canonicalB;
$providerB = new FunctionalSeriesNumberingProvider();
$providerB->result = ['success' => true, 'provider' => 'tvdb', 'provider_label' => 'TVDB Fake', 'source_ref' => 'tvdb:900010', 'videos' => $providerVideosB];
$numberingB = new EpisodeNumberingService($storeB, $settingsB, $catalogB, $providerB);
$runB = seedListRun($storeB, 'scrob', 1);
seedListItem($storeB, $runB, [
    'remote_key' => 'split-show',
    'media_type' => 'series',
    'tmdb_id' => 900010,
    'imdb_id' => 'tt9000010',
    'title' => 'Split Show',
    'year' => 2024,
], [
    'status' => 'missing',
    'missing_episodes' => ['S01E25'],
    'episodes_total' => 47,
    'episodes_present' => 46,
    'episodes_missing' => 1,
]);
$identitySplitB = [
    'tmdb_id' => 900010,
    'imdb_id' => 'tt9000010',
    'title' => 'Split Show',
    'year' => 2024,
    'title_normalized' => 'split show',
];
$profileB = $numberingB->profileFor($identitySplitB, true, true);
eq('ok', $profileB['status'] ?? null, 'B split numbering profile is resolved');
eq(47, count($profileB['mapping'] ?? []), 'B mapping covers all 47 TMDB episodes');
eq([['season' => 1, 'episodes' => 24], ['season' => 2, 'episodes' => 23]], $profileB['segments'] ?? null, 'B segments follow the 24/23 TVDB split');
eq(['season' => 2, 'episode' => 1, 'numbering_source' => 'tvdb'], $numberingB->acquisitionFor($profileB, 1, 25), 'B TMDB S01E25 maps to release S02E01');
eq(['season' => 1, 'episode' => 25], $numberingB->canonicalFor($profileB, 2, 1), 'B release S02E01 maps back to TMDB S01E25');

$identitySeriesB = new FunctionalIdentityResolver();
$identitySeriesB->identity = ['success' => true, 'tmdb_id' => 900010, 'imdb_id' => 'tt9000010', 'title' => 'Split Show', 'year' => 2024, 'reason' => null];
$identityMovieB = new FunctionalIdentityResolver();
$identityMovieB->identity = ['success' => false, 'reason' => 'missing_identity'];
$streamsB = new FunctionalStreamProvider();
$streamsB->streamsByType['series'] = [seriesStream('Split.Show.S02E01.1080p.WEB-DL.x265.VOSTFR', 'c')];
$downloaderB = new FunctionalDownloader();
$preferencesB = new ReleasePreferenceService(new ReleaseMetadataParser(), $settingsB);
$resolverB = new ReleaseResolverService($storeB, $settingsB, $preferencesB, $streamsB, $downloaderB, $identityMovieB, $identitySeriesB, $storageB, $numberingB);
$notifierB = new AutomationNotifier($storageB);
$intentsB = new ReleaseIntentService($storeB, $settingsB, $resolverB, $notifierB, $storageB, $projectB, $numberingB);
$queuedB = $intentsB->queueMissingEpisodes(false);
eq(1, $queuedB['stats']['created'], 'B one split episode intent is created');
$intentB = $storeB->getPdo()->query("SELECT * FROM intents WHERE media_key = 'series:tmdb:900010:s01e25' LIMIT 1")->fetch();
check($intentB !== false, 'B TMDB S01E25 intent is persisted');
eq(2, (int) ($intentB['release_season'] ?? 0), 'B intent stores release season 2');
eq(1, (int) ($intentB['release_episode'] ?? 0), 'B intent stores release episode 1');
$resolvedB = $resolverB->processIntent($intentB);
eq('queued', $resolvedB['status'], 'B split episode intent is resolved');
eq('series|tt9000010:2:1', end($streamsB->calls), 'B release lookup uses TVDB S02E01');
$payloadB = end($downloaderB->payloads);
eq($seriesRootB . '/Split Show (2024)/Season 02', $payloadB['path'], 'B local payload uses release season folder');
eq('Split Show (2024) - S02E01.mkv', $payloadB['override_filename'], 'B local payload uses release episode filename');
eq(1, (int) $payloadB['season'], 'B payload keeps canonical TMDB season');
eq(25, (int) $payloadB['episode'], 'B payload keeps canonical TMDB episode');
eq(2, (int) $payloadB['release_season'], 'B payload carries release season');
eq(1, (int) $payloadB['release_episode'], 'B payload carries release episode');
$modesB = $numberingB->updateModes($identitySplitB, 'local', 'tmdb', null, 'manual');
eq('local', $modesB['target_mode'] ?? null, 'B target mode local is stored');
eq('tmdb', $modesB['jellyfin_mode'] ?? null, 'B Jellyfin TMDB mode is stored');
$modesB = $numberingB->updateModes($identitySplitB, 'tmdb', 'local', null, 'manual');
eq('tmdb', $modesB['target_mode'] ?? null, 'B target mode TMDB is stored');
eq('local', $modesB['jellyfin_mode'] ?? null, 'B Jellyfin local mode is stored');
removeTree($projectB);

echo "C. Local episode already present: dry-run matching, no intent\n";
$projectC = tempProject('functional-c');
$seriesRootC = $projectC . '/nas/TV Shows';
mkdir($seriesRootC . '/Present Show (2024)/Season 01', 0777, true);
$episodePathC = $seriesRootC . '/Present Show (2024)/Season 01/Present Show (2024) - S01E01.mkv';
writeFile($episodePathC, str_repeat('e', 512));
$storeC = new AutomationStore($projectC);
$settingsC = new AutomationSettings($storeC);
$storageC = storageAt($projectC . '/var/storage');
$settingsC->save([
    'roots' => [
        'movies' => ['label' => 'Movies', 'path' => $projectC . '/nas/Movies', 'type' => 'movie', 'enabled' => true],
        'series' => ['label' => 'TV Shows', 'path' => $seriesRootC, 'type' => 'series', 'enabled' => true],
        'anime' => ['label' => 'Anime', 'path' => $projectC . '/nas/Anime', 'type' => 'anime', 'enabled' => false],
    ],
    'sources' => [[
        'id' => 'scrob',
        'provider' => 'scrob',
        'label' => 'Scrob',
        'enabled' => true,
        'config' => ['api_key' => 'test', 'list_ids' => ['1'], 'auto_download_series' => true],
    ]],
    'backfill' => ['max_intents_per_run' => 50, 'queue_guard' => 20],
]);
$tmdbC = new FunctionalFakeTmdb();
$tmdbC->detailsMap[900020] = ['id' => 900020, 'type' => 'series', 'title' => 'Present Show', 'year' => 2024, 'imdb_id' => 'tt9000020', 'seasons' => [['season_number' => 1, 'episode_count' => 3]]];
$tmdbC->seasonsMap[900020][1] = [
    ['episode_number' => 1, 'name' => 'One', 'air_date' => '2024-01-01'],
    ['episode_number' => 2, 'name' => 'Two', 'air_date' => '2024-01-08'],
    ['episode_number' => 3, 'name' => 'Three', 'air_date' => '2024-01-15'],
];
addLibraryEntry($storeC, [
    'root_key' => 'series',
    'path' => $episodePathC,
    'relative_path' => 'Present Show (2024)/Season 01/' . basename($episodePathC),
    'filename' => basename($episodePathC),
    'media_type' => 'series',
    'title' => 'Present Show',
    'title_normalized' => 'present show',
    'year' => 2024,
    'season' => 1,
    'episode' => 1,
    'media_key' => 'series:tmdb:900020:s01e01',
    'tmdb_id' => 900020,
    'imdb_id' => 'tt9000020',
    'size' => 512,
    'mtime' => 100,
    'show_folder' => 'Present Show (2024)',
]);
$runC = seedListRun($storeC, 'scrob', 1);
$listItemIdC = seedListItem($storeC, $runC, [
    'remote_key' => 'present-show-e1',
    'media_type' => 'series',
    'tmdb_id' => 900020,
    'imdb_id' => 'tt9000020',
    'title' => 'Present Show',
    'year' => 2024,
    'season' => 1,
    'episode' => 1,
]);
$matcherC = new ListMatcher($storeC, $settingsC, $tmdbC);
$matcherC->beginRun(10);
$matchC = $matcherC->match($storeC->getListItem($listItemIdC));
eq('present', $matchC['status'] ?? null, 'C dry-run matching sees the local episode as present');
eq(1, $matchC['episodes_present'] ?? null, 'C present episode is counted');
$storeC->updateListItemMatch($listItemIdC, $matchC, $runC);
$resolverC = (new ReflectionClass(ReleaseResolverService::class))->newInstanceWithoutConstructor();
$intentsC = new ReleaseIntentService($storeC, $settingsC, $resolverC, new AutomationNotifier($storageC), $storageC, $projectC);
$queuedC = $intentsC->queueMissingEpisodes(false);
eq(0, $queuedC['stats']['created'], 'C present episode creates no intent');
eq(0, $storeC->countIntents(), 'C intent table stays empty');
removeTree($projectC);

echo "D. Deleted episode: scan missing, orphan success reactivated, resume without duplicate\n";
$projectD = tempProject('functional-d');
$seriesRootD = $projectD . '/nas/TV Shows';
mkdir($seriesRootD . '/Gone Show (2024)/Season 01', 0777, true);
$episodeOnePathD = $seriesRootD . '/Gone Show (2024)/Season 01/Gone Show (2024) - S01E01.mkv';
$episodeTwoPathD = $seriesRootD . '/Gone Show (2024)/Season 01/Gone Show (2024) - S01E02.mkv';
writeFile($episodeOnePathD, str_repeat('1', 1024));
writeFile($episodeTwoPathD, str_repeat('2', 1024));
$storeD = new AutomationStore($projectD);
$settingsD = new AutomationSettings($storeD);
$storageD = storageAt($projectD . '/var/storage');
$settingsD->save([
    'roots' => [
        'movies' => ['label' => 'Movies', 'path' => $projectD . '/nas/Movies', 'type' => 'movie', 'enabled' => true],
        'series' => ['label' => 'TV Shows', 'path' => $seriesRootD, 'type' => 'series', 'enabled' => true],
        'anime' => ['label' => 'Anime', 'path' => $projectD . '/nas/Anime', 'type' => 'anime', 'enabled' => false],
    ],
    'sources' => [[
        'id' => 'scrob',
        'provider' => 'scrob',
        'label' => 'Scrob',
        'enabled' => true,
        'config' => ['api_key' => 'test', 'list_ids' => ['1'], 'auto_download_series' => true],
    ]],
    'scanner' => ['min_size_mb' => 0, 'read_nfo' => false, 'skip_samples' => false, 'extensions' => ['mkv']],
    'backfill' => ['max_intents_per_run' => 50, 'queue_guard' => 20],
]);
$indexD = new LibraryIndexService($storeD, $settingsD, new LibraryNameParser(), $projectD);
$scanD = $indexD->scan(['series']);
check(!empty($scanD['success']), 'D initial fake scan succeeds', $scanD);
eq(2, $storeD->getRootActiveCount('series'), 'D two local episodes are indexed');
$episodeDataD = [
    'source_id' => 'scrob',
    'source_list_id' => '1',
    'media_key' => 'series:tmdb:900030:s01e01',
    'media_type' => 'series',
    'tmdb_id' => 900030,
    'imdb_id' => 'tt9000030',
    'title' => 'Gone Show',
    'year' => 2024,
    'season' => 1,
    'episode' => 1,
];
$createdD = $storeD->createIntent($episodeDataD);
$intentIdD = (int) $createdD['intent']['id'];
$storeD->updateIntent($intentIdD, ['status' => 'success', 'attempts' => 2, 'download_id' => 'old-download-d']);
unlink($episodeOnePathD);
$scanD2 = $indexD->scan(['series']);
check(!empty($scanD2['success']), 'D scan after deletion succeeds', $scanD2);
$entryOneD = $storeD->getPdo()->query("SELECT * FROM library_entries WHERE path = " . $storeD->getPdo()->quote($episodeOnePathD) . " LIMIT 1")->fetch();
eq('missing', $entryOneD['status'] ?? null, 'D scan marks the deleted episode as missing');
$tmdbD = new FunctionalFakeTmdb();
$tmdbD->detailsMap[900030] = ['id' => 900030, 'type' => 'series', 'title' => 'Gone Show', 'year' => 2024, 'imdb_id' => 'tt9000030', 'seasons' => [['season_number' => 1, 'episode_count' => 2]]];
$tmdbD->seasonsMap[900030][1] = [
    ['episode_number' => 1, 'name' => 'One', 'air_date' => '2024-01-01'],
    ['episode_number' => 2, 'name' => 'Two', 'air_date' => '2024-01-08'],
];
$runD = seedListRun($storeD, 'scrob', 1);
$listItemD = seedListItem($storeD, $runD, [
    'remote_key' => 'gone-show',
    'media_type' => 'series',
    'tmdb_id' => 900030,
    'imdb_id' => 'tt9000030',
    'title' => 'Gone Show',
    'year' => 2024,
]);
$matcherD = new ListMatcher($storeD, $settingsD, $tmdbD);
$matcherD->beginRun(10);
$matchD = $matcherD->match($storeD->getListItem($listItemD));
eq('missing', $matchD['status'] ?? null, 'D dry-run matching sees the deleted episode as missing');
eq(['S01E01'], $matchD['missing_episodes'] ?? null, 'D missing episode code is complete');
$storeD->updateListItemMatch($listItemD, $matchD, $runD);
$reactivatedD = $storeD->createIntent($episodeDataD, true);
eq('reactivated', $reactivatedD['status'] ?? null, 'D orphan success intent is reactivated');
eq('pending', $reactivatedD['intent']['status'] ?? null, 'D reactivated intent is pending');
$identityD = new FunctionalIdentityResolver();
$identityD->identity = ['success' => true, 'tmdb_id' => 900030, 'imdb_id' => 'tt9000030', 'title' => 'Gone Show', 'year' => 2024, 'reason' => null];
$identityMovieD = new FunctionalIdentityResolver();
$identityMovieD->identity = ['success' => false, 'reason' => 'missing_identity'];
$streamsD = new FunctionalStreamProvider();
$streamsD->streamsByType['series'] = [seriesStream('Gone.Show.S01E01.1080p.WEB-DL.x265.VOSTFR', 'd')];
$downloaderD = new FunctionalDownloader();
$preferencesD = new ReleasePreferenceService(new ReleaseMetadataParser(), $settingsD);
$resolverD = new ReleaseResolverService($storeD, $settingsD, $preferencesD, $streamsD, $downloaderD, $identityMovieD, $identityD, $storageD);
$notifierD = new AutomationNotifier($storageD);
$intentsD = new ReleaseIntentService($storeD, $settingsD, $resolverD, $notifierD, $storageD, $projectD);
$queuedD = $intentsD->queueMissingEpisodes(false);
eq(0, $queuedD['stats']['created'], 'D missing episode reuses the reactivated orphan intent');
check(($queuedD['stats']['existing'] ?? 0) >= 1, 'D existing orphan intent is reported');
eq(1, $storeD->countIntents(['type' => 'series']), 'D no duplicate episode intent is created');
$intentD = $storeD->getIntent($intentIdD);
$resolvedD = $resolverD->processIntent($intentD);
eq('queued', $resolvedD['status'], 'D episode re-download is queued');
$payloadD = end($downloaderD->payloads);
$newEpisodePathD = $payloadD['path'] . '/' . $payloadD['override_filename'];
writeFile($newEpisodePathD, str_repeat('n', 1024));
addHistory($storageD, 'functional-dl-d', $newEpisodePathD, filesize($newEpisodePathD));
$intentsD->onDownloadFinished(['automation_intent_id' => $intentIdD, 'download_id' => 'functional-dl-d', 'filename' => $payloadD['override_filename']], 'success');
$scanD3 = $indexD->scan(['series']);
check(!empty($scanD3['success']), 'D resume scan succeeds', $scanD3);
$restoredD = $storeD->getPdo()->query("SELECT * FROM library_entries WHERE path = " . $storeD->getPdo()->quote($newEpisodePathD) . " LIMIT 1")->fetch();
check($restoredD !== false && (string) $restoredD['status'] === 'active', 'D re-downloaded episode is active after scan');
eq(1, $storeD->countIntents(['type' => 'series']), 'D resume keeps a single intent');
removeTree($projectD);

echo "E. Per-run cap and queue guard\n";
$projectE = tempProject('functional-e');
$storeE = new AutomationStore($projectE);
$settingsE = new AutomationSettings($storeE);
$storageE = storageAt($projectE . '/var/storage');
$settingsE->save([
    'roots' => [
        'movies' => ['label' => 'Movies', 'path' => $projectE . '/nas/Movies', 'type' => 'movie', 'enabled' => true],
        'series' => ['label' => 'TV Shows', 'path' => $projectE . '/nas/TV Shows', 'type' => 'series', 'enabled' => true],
        'anime' => ['label' => 'Anime', 'path' => $projectE . '/nas/Anime', 'type' => 'anime', 'enabled' => false],
    ],
    'sources' => [[
        'id' => 'scrob',
        'provider' => 'scrob',
        'label' => 'Scrob',
        'enabled' => true,
        'config' => ['api_key' => 'test', 'list_ids' => ['1'], 'auto_download' => true],
    ]],
    'backfill' => ['max_intents_per_run' => 1, 'queue_guard' => 20],
]);
$runE = seedListRun($storeE, 'scrob', 3);
foreach ([[7001, 'Cap Movie One'], [7002, 'Cap Movie Two'], [7003, 'Cap Movie Three']] as [$tmdbId, $title]) {
    seedListItem($storeE, $runE, [
        'remote_key' => 'cap-' . $tmdbId,
        'media_type' => 'movie',
        'tmdb_id' => $tmdbId,
        'title' => $title,
        'year' => 2024,
    ], ['status' => 'missing']);
}
$resolverE = (new ReflectionClass(ReleaseResolverService::class))->newInstanceWithoutConstructor();
$intentsE = new ReleaseIntentService($storeE, $settingsE, $resolverE, new AutomationNotifier($storageE), $storageE, $projectE);
$capE = $intentsE->queueMissingMovies(false);
eq(1, $capE['stats']['created'], 'E per-run cap creates exactly one intent');
eq(1, $storeE->countIntents(['type' => 'movie']), 'E cap stops before extra intents');
check(str_contains(implode(' ', $capE['stats']['warnings']), 'Maximum intents per run'), 'E cap warning is reported');
$settingsE->save(['backfill' => ['max_intents_per_run' => 10, 'queue_guard' => 1]]);
$storageE->set('server_queue', [['url' => 'fake']]);
$guardE = $intentsE->queueMissingMovies(false);
eq(0, $guardE['stats']['created'], 'E queue guard blocks intent creation');
eq(1, $storeE->countIntents(['type' => 'movie']), 'E queue guard does not add intents');
check(str_contains((string) ($guardE['message'] ?? ''), 'Queue guard'), 'E queue guard warning is reported');
removeTree($projectE);

echo "F. Release verification: exact size, rounded label, HTML, small, quarantine\n";
$projectF = tempProject('functional-f');
$storageF = storageAt($projectF . '/var/storage');
$storeF = new AutomationStore($projectF);
$integrityF = new ReleaseIntegrityService($storeF, $storageF);
$goodFileF = $projectF . '/files/Good Movie.mkv';
writeSparse($goodFileF, 2 * 1024 * 1024);
$goodIntentF = $storeF->createIntent(['media_key' => 'movie:tmdb:1001', 'media_type' => 'movie', 'tmdb_id' => 1001, 'title' => 'Good Movie', 'year' => 2020]);
$goodIdF = (int) $goodIntentF['intent']['id'];
$storeF->updateIntent($goodIdF, ['status' => 'success', 'download_id' => 'dl-good-f', 'release_explanation' => '1080p x265 · 0.3 Go']);
addHistory($storageF, 'dl-good-f', $goodFileF, filesize($goodFileF));

$exactFileF = $projectF . '/files/Exact Movie.mkv';
writeSparse($exactFileF, 1310720);
$exactIntentF = $storeF->createIntent(['media_key' => 'movie:tmdb:1002', 'media_type' => 'movie', 'tmdb_id' => 1002, 'title' => 'Exact Movie', 'year' => 2021]);
$exactIdF = (int) $exactIntentF['intent']['id'];
$storeF->updateIntent($exactIdF, ['status' => 'success', 'download_id' => 'dl-exact-f']);
addHistory($storageF, 'dl-exact-f', $exactFileF, filesize($exactFileF));

$truncatedFileF = $projectF . '/files/Truncated Movie.mkv';
writeSparse($truncatedFileF, (int) (1.5 * 1024 * 1024));
$truncatedIntentF = $storeF->createIntent(['media_key' => 'movie:tmdb:1003', 'media_type' => 'movie', 'tmdb_id' => 1003, 'title' => 'Truncated Movie', 'year' => 2022]);
$truncatedIdF = (int) $truncatedIntentF['intent']['id'];
$storeF->updateIntent($truncatedIdF, ['status' => 'success', 'download_id' => 'dl-truncated-f']);
addHistory($storageF, 'dl-truncated-f', $truncatedFileF, 2 * 1024 * 1024);

$smallFileF = $projectF . '/files/Small Movie.mkv';
writeSparse($smallFileF, 512);
$smallIntentF = $storeF->createIntent(['media_key' => 'movie:tmdb:1004', 'media_type' => 'movie', 'tmdb_id' => 1004, 'title' => 'Small Movie', 'year' => 2023]);
$smallIdF = (int) $smallIntentF['intent']['id'];
$storeF->updateIntent($smallIdF, ['status' => 'success', 'download_id' => 'dl-small-f']);
addHistory($storageF, 'dl-small-f', $smallFileF, filesize($smallFileF));

$htmlFileF = $projectF . '/files/Html Movie.mkv';
writeSparse($htmlFileF, 2 * 1024 * 1024, '<html><body>expired</body></html>');
$htmlIntentF = $storeF->createIntent(['media_key' => 'movie:tmdb:1005', 'media_type' => 'movie', 'tmdb_id' => 1005, 'title' => 'Html Movie', 'year' => 2024]);
$htmlIdF = (int) $htmlIntentF['intent']['id'];
$storeF->updateIntent($htmlIdF, ['status' => 'success', 'download_id' => 'dl-html-f']);
addHistory($storageF, 'dl-html-f', $htmlFileF, filesize($htmlFileF));

$missingIntentF = $storeF->createIntent(['media_key' => 'movie:tmdb:1006', 'media_type' => 'movie', 'tmdb_id' => 1006, 'title' => 'Missing Movie', 'year' => 2024]);
$missingIdF = (int) $missingIntentF['intent']['id'];
$storeF->updateIntent($missingIdF, ['status' => 'success', 'download_id' => 'dl-missing-f']);
addHistory($storageF, 'dl-missing-f', $projectF . '/files/Missing Movie.mkv', 2 * 1024 * 1024);

$previewF = $integrityF->verifySuccessfulIntents(false, false, 20);
eq(6, $previewF['stats']['checked'], 'F verification checks six success intents');
eq(4, $previewF['stats']['invalid'], 'F truncated, small, HTML and missing files are invalid');
$detailByIdF = array_column($previewF['stats']['details'], 'status', 'id');
eq('ok', $detailByIdF[$goodIdF] ?? null, 'F rounded release label does not invalidate an exact file');
eq('ok', $detailByIdF[$exactIdF] ?? null, 'F exact actual_size is accepted');
eq('would_retry', $detailByIdF[$truncatedIdF] ?? null, 'F truncated file is flagged');
eq('would_retry', $detailByIdF[$smallIdF] ?? null, 'F tiny file is flagged');
eq('would_retry', $detailByIdF[$htmlIdF] ?? null, 'F HTML payload is flagged');
eq('would_retry', $detailByIdF[$missingIdF] ?? null, 'F missing file is flagged');
$reasonsF = array_column($previewF['stats']['details'], 'reason', 'id');
check(str_contains((string) ($reasonsF[$htmlIdF] ?? ''), 'HTML'), 'F HTML reason is explicit');
check(str_contains((string) ($reasonsF[$truncatedIdF] ?? ''), 'exact history size'), 'F truncation reason uses exact history size');
$refusedF = $integrityF->verifySuccessfulIntents(true, false, 20);
check(empty($refusedF['success']), 'F apply without quarantine is refused');
check(is_file($truncatedFileF), 'F refused apply leaves files untouched');
eq('success', $storeF->getIntent($truncatedIdF)['status'] ?? null, 'F refused apply leaves intents untouched');
$appliedF = $integrityF->verifySuccessfulIntents(true, true, 20);
check(!empty($appliedF['success']), 'F apply with quarantine succeeds');
eq(3, $appliedF['stats']['quarantined'], 'F every existing invalid file is quarantined');
check(!is_file($truncatedFileF), 'F truncated file is moved');
check(is_file($goodFileF), 'F valid file is never quarantined');
check(is_file($exactFileF), 'F exact file is never quarantined');
eq('pending', $storeF->getIntent($truncatedIdF)['status'] ?? null, 'F invalid intent is reactivated');
$truncatedAfterF = $storeF->getIntent($truncatedIdF);
check(array_key_exists('download_id', $truncatedAfterF) && $truncatedAfterF['download_id'] === null, 'F invalid intent download id is cleared');
removeTree($projectF);

echo "G. Deterministic file selection\n";
$selectorG = (new ReflectionClass(MediaDownloadService::class))->newInstanceWithoutConstructor();
$selectFileG = new ReflectionMethod(MediaDownloadService::class, 'selectFile');
$selectFileG->setAccessible(true);
$packG = [
    ['index' => 0, 'torrent_index' => 5, 'name' => 'Show.S01E05.1080p.mkv', 'path' => 'Show.S01E05.1080p.mkv', 'size' => 9000000000, 'link' => 'a'],
    ['index' => 1, 'torrent_index' => 6, 'name' => 'Show.S01E03.1080p.mkv', 'path' => 'Show.S01E03.1080p.mkv', 'size' => 1000000000, 'link' => 'b'],
];
$pickG = $selectFileG->invoke($selectorG, $packG, null, '', 1, 3, 'Show.S01E03');
eq(1, $pickG['index'] ?? null, 'G exact SxxExx beats the largest file');
$pickG = $selectFileG->invoke($selectorG, [$packG[0]], null, '', 1, 3, 'Show.S01E03');
eq(null, $pickG, 'G pack without the exact episode is refused');
$pickG = $selectFileG->invoke($selectorG, $packG, 5, '', 1, 3, 'Show.S01E03');
eq(1, $pickG['index'] ?? null, 'G correct fileIdx also resolves to the exact episode');
$pickG = $selectFileG->invoke($selectorG, $packG, 9, '', 1, 3, 'Show.S01E03');
eq(1, $pickG['index'] ?? null, 'G incorrect fileIdx falls back to the exact episode');
$pickG = $selectFileG->invoke($selectorG, [['index' => 0, 'torrent_index' => 0, 'name' => 'Show.S01E03E04.mkv', 'path' => 'Show.S01E03E04.mkv', 'size' => 100, 'link' => 'c']], null, '', 1, 3, 'Show.S01E03E04');
eq(null, $pickG, 'G multi-episode file is refused for one episode');
$pickG = $selectFileG->invoke($selectorG, [['index' => 0, 'torrent_index' => 0, 'name' => 'Show.S01.1080p.mkv', 'path' => 'Show.S01.1080p.mkv', 'size' => 100, 'link' => 'd']], null, '', 1, 3, 'Show.S01.1080p');
eq(null, $pickG, 'G season pack without episode file is refused');
$pickG = $selectFileG->invoke($selectorG, [['index' => 0, 'torrent_index' => 0, 'name' => 'Show.1080p.mkv', 'path' => 'Show.1080p.mkv', 'size' => 100, 'link' => 'e']], null, '', 1, 3, 'Show');
eq(null, $pickG, 'G unmarked single file is refused for an episode');
$movieFilesG = [
    ['index' => 0, 'torrent_index' => 2, 'name' => 'Movie.mkv', 'path' => 'Movie.mkv', 'size' => 100, 'link' => 'f'],
    ['index' => 1, 'torrent_index' => 3, 'name' => 'Featurette.mkv', 'path' => 'Featurette.mkv', 'size' => 200, 'link' => 'g'],
];
$pickG = $selectFileG->invoke($selectorG, $movieFilesG, 2, '', null, null, '');
check(($pickG['name'] ?? null) === 'Movie.mkv', 'G movie uses a reliable torrent index');
$pickG = $selectFileG->invoke($selectorG, $movieFilesG, null, 'Featurette.mkv', null, null, '');
check(($pickG['name'] ?? null) === 'Featurette.mkv', 'G movie uses the requested filename when needed');
$pickG = $selectFileG->invoke($selectorG, $movieFilesG, null, '', null, null, '');
eq(null, $pickG, 'G ambiguous movie pack is refused');
$pickG = $selectFileG->invoke($selectorG, [['index' => 0, 'torrent_index' => 0, 'name' => 'Movie.mkv', 'path' => 'Movie.mkv', 'size' => 100, 'link' => 'h']], 0, '', null, null, '');
check(($pickG['name'] ?? null) === 'Movie.mkv', 'G single-file movie is selected');

echo "H. Library scan, empty root, root move, reconcile-missing, rescan without duplicate\n";
$projectH = tempProject('functional-h');
$moviesRootH = $projectH . '/nas/Movies';
$animeRootH = $projectH . '/nas/Anime';
$seriesRootH = $projectH . '/nas/TV Shows';
mkdir($moviesRootH . '/A Movie (2020)', 0777, true);
mkdir($moviesRootH . '/B Movie (2021)', 0777, true);
mkdir($animeRootH, 0777, true);
mkdir($seriesRootH, 0777, true);
$fileAH = $moviesRootH . '/A Movie (2020)/A Movie (2020).mkv';
$fileBH = $moviesRootH . '/B Movie (2021)/B Movie (2021).mkv';
writeFile($fileAH, str_repeat('a', 1024));
writeFile($fileBH, str_repeat('b', 1024));
$storeH = new AutomationStore($projectH);
$settingsH = new AutomationSettings($storeH);
$settingsH->save([
    'roots' => [
        'movies' => ['label' => 'Movies', 'path' => $moviesRootH, 'type' => 'movie', 'enabled' => true],
        'series' => ['label' => 'TV Shows', 'path' => $seriesRootH, 'type' => 'series', 'enabled' => true],
        'anime' => ['label' => 'Anime', 'path' => $animeRootH, 'type' => 'anime', 'enabled' => true],
    ],
    'scanner' => ['min_size_mb' => 0, 'read_nfo' => false, 'skip_samples' => false, 'extensions' => ['mkv']],
]);
$indexH = new LibraryIndexService($storeH, $settingsH, new LibraryNameParser(), $projectH);
$scanH = $indexH->scan();
check(!empty($scanH['success']), 'H initial scan succeeds', $scanH);
eq(2, $storeH->getRootActiveCount('movies'), 'H two movies are indexed');
$scanH2 = $indexH->scan(['movies']);
check(!empty($scanH2['success']), 'H rescan succeeds', $scanH2);
$movieCountH = (int) $storeH->getPdo()->query("SELECT COUNT(*) FROM library_entries WHERE root_key = 'movies' AND status = 'active'")->fetchColumn();
eq(2, $movieCountH, 'H rescan does not duplicate entries');
$scanSeriesH = $indexH->scan(['series']);
check(!empty($scanSeriesH['success']), 'H empty root scan succeeds');
eq(0, count($storeH->getActiveLibraryEntriesForRoots(['series'])), 'H empty root keeps zero entries');
$movedBH = $animeRootH . '/B Movie (2021)/B Movie (2021).mkv';
mkdir(dirname($movedBH), 0777, true);
rename($fileBH, $movedBH);
$scanH3 = $indexH->scan();
check(!empty($scanH3['success']), 'H scan after moving between roots succeeds', $scanH3);
$oldBH = $storeH->getPdo()->query("SELECT * FROM library_entries WHERE path = " . $storeH->getPdo()->quote($fileBH) . " LIMIT 1")->fetch();
eq('missing', $oldBH['status'] ?? null, 'H old root entry is marked missing after the move');
$newBH = $storeH->getPdo()->query("SELECT * FROM library_entries WHERE path = " . $storeH->getPdo()->quote($movedBH) . " LIMIT 1")->fetch();
check($newBH !== false && (string) $newBH['status'] === 'active', 'H moved file is active under its new root');
eq('anime', $newBH['root_key'] ?? null, 'H moved file keeps its new root key');
$duplicatesH = $storeH->getPdo()->query('SELECT path, COUNT(*) AS c FROM library_entries GROUP BY path HAVING c > 1')->fetchAll();
eq([], $duplicatesH, 'H no duplicate path is created');
unlink($fileAH);
$reconcileH = new LibraryReconcileMissingCommand($storeH, $settingsH);
$testerH = new CommandTester($reconcileH);
$testerH->execute(['--root' => 'movies', '--dry-run' => true]);
check(str_contains($testerH->getDisplay(), 'Missing files: 1'), 'H reconcile-missing dry-run reports the missing file', $testerH->getDisplay());
$entryAH = $storeH->getPdo()->query("SELECT * FROM library_entries WHERE path = " . $storeH->getPdo()->quote($fileAH) . " LIMIT 1")->fetch();
eq('active', $entryAH['status'] ?? null, 'H reconcile-missing dry-run does not mutate the index');
$testerH->execute(['--root' => 'movies']);
check(str_contains($testerH->getDisplay(), 'Marked as missing: 1'), 'H reconcile-missing marks the missing file', $testerH->getDisplay());
$entryAH = $storeH->getPdo()->query("SELECT * FROM library_entries WHERE path = " . $storeH->getPdo()->quote($fileAH) . " LIMIT 1")->fetch();
eq('missing', $entryAH['status'] ?? null, 'H reconcile-missing updates the index');
$indexH->scan(['anime']);
$indexH->scan(['anime']);
$animeCountH = (int) $storeH->getPdo()->query("SELECT COUNT(*) FROM library_entries WHERE root_key = 'anime' AND status = 'active'")->fetchColumn();
eq(1, $animeCountH, 'H repeated anime rescan keeps a single active entry');
removeTree($projectH);

echo "I. Jellyfin views, items, episodes, playback, local/TMDB numbering\n";
$projectI = tempProject('functional-i');
$moviesRootI = $projectI . '/nas/Movies';
$seriesRootI = $projectI . '/nas/TV Shows';
mkdir($moviesRootI . '/Dune Part Two (2024)', 0777, true);
mkdir($seriesRootI . '/Split Show (2024)/Season 02', 0777, true);
$moviePathI = $moviesRootI . '/Dune Part Two (2024)/Dune Part Two (2024).mkv';
$splitPathI = $seriesRootI . '/Split Show (2024)/Season 02/Split Show (2024) - S02E01.mkv';
writeFile($moviePathI, str_repeat('m', 512));
writeFile($splitPathI, str_repeat('s', 512));
$storeI = new AutomationStore($projectI);
$settingsI = new AutomationSettings($storeI);
$storageI = storageAt($projectI . '/var/storage');
$settingsI->save([
    'roots' => [
        'movies' => ['label' => 'Movies', 'path' => $moviesRootI, 'type' => 'movie', 'enabled' => true],
        'series' => ['label' => 'TV Shows', 'path' => $seriesRootI, 'type' => 'series', 'enabled' => true],
        'anime' => ['label' => 'Anime', 'path' => $projectI . '/nas/Anime', 'type' => 'anime', 'enabled' => false],
    ],
]);
$movieEntryIdI = addLibraryEntry($storeI, [
    'root_key' => 'movies',
    'path' => $moviePathI,
    'relative_path' => 'Dune Part Two (2024)/Dune Part Two (2024).mkv',
    'filename' => basename($moviePathI),
    'media_type' => 'movie',
    'title' => 'Dune: Part Two',
    'title_normalized' => 'dune part two',
    'year' => 2024,
    'media_key' => 'movie:tmdb:693134',
    'tmdb_id' => 693134,
    'imdb_id' => 'tt15239678',
    'size' => 512,
]);
$splitEntryIdI = addLibraryEntry($storeI, [
    'root_key' => 'series',
    'path' => $splitPathI,
    'relative_path' => 'Split Show (2024)/Season 02/Split Show (2024) - S02E01.mkv',
    'filename' => basename($splitPathI),
    'media_type' => 'series',
    'title' => 'Split Show',
    'title_normalized' => 'split show',
    'year' => 2024,
    'season' => 2,
    'episode' => 1,
    'media_key' => 'series:tmdb:900001:s02e01',
    'tmdb_id' => 900001,
    'imdb_id' => 'tt9000001',
    'size' => 512,
    'show_folder' => 'Split Show (2024)',
]);
$stateI = new JellyfinStateService($storageI);
$numberingI = new FunctionalJellyfinNumberingService();
$numberingI->mode = 'local';
$makeLibraryI = static function () use ($storeI, $settingsI, $stateI, $numberingI): JellyfinLibraryService {
    return new JellyfinLibraryService(
        $storeI,
        $settingsI,
        $stateI,
        new JellyfinMediaService($settingsI, new ArrayAdapter()),
        new FunctionalJellyfinMetadataService(),
        $numberingI
    );
};
$libraryLocalI = $makeLibraryI();
$viewsI = $libraryLocalI->views();
eq(2, $viewsI['TotalRecordCount'], 'I two enabled roots with entries are exposed');
eq('movies', $viewsI['Items'][0]['CollectionType'], 'I movie root maps to movies');
eq('tvshows', $viewsI['Items'][1]['CollectionType'], 'I series root maps to tvshows');
$moviePageI = $libraryLocalI->items(['parentId' => JellyfinIds::library('movies'), 'includeItemTypes' => 'Movie', 'limit' => '50'], 'user');
eq(1, $moviePageI['TotalRecordCount'], 'I movie library returns one movie');
$movieI = $moviePageI['Items'][0];
eq('Movie', $movieI['Type'], 'I movie item type is Movie');
eq('Dune: Part Two', $movieI['Name'], 'I movie item name is correct');
eq('693134', $movieI['ProviderIds']['Tmdb'], 'I movie item exposes TMDB id');
eq(JellyfinIds::movie('movies', 'tmdb:693134'), $movieI['Id'], 'I movie id is stable');
$moviePageI2 = $libraryLocalI->items(['parentId' => JellyfinIds::library('movies'), 'includeItemTypes' => 'Movie'], 'user');
eq($movieI['Id'], $moviePageI2['Items'][0]['Id'] ?? null, 'I movie id is stable across calls');
$movieFullI = $libraryLocalI->item($movieI['Id'], 'user', true, true, true);
check($movieFullI !== null && !empty($movieFullI['MediaSources']), 'I movie item exposes media sources');
$movieStreamI = $libraryLocalI->streamEntries($movieI['Id'], $movieFullI['MediaSources'][0]['Id']);
check($movieStreamI !== null && $movieStreamI['entry']['path'] === $moviePathI, 'I movie stream resolves to the local file');
$playbackI = $libraryLocalI->playbackInfo($movieI['Id']);
check($playbackI !== null && isset($playbackI['PlaySessionId']), 'I movie playback info is available');
$seriesPageI = $libraryLocalI->items(['parentId' => JellyfinIds::library('series'), 'includeItemTypes' => 'Series'], 'user');
eq(1, $seriesPageI['TotalRecordCount'], 'I series library returns one series');
$seriesI = $seriesPageI['Items'][0];
eq('Series', $seriesI['Type'], 'I series item type is Series');
$localSeasonI = $libraryLocalI->episodes($seriesI['Id'], 'user', 2);
eq(1, $localSeasonI['TotalRecordCount'], 'I local mode exposes the release season 2 episode');
eq(1, $localSeasonI['Items'][0]['IndexNumber'], 'I local mode keeps release episode 1');
eq(2, $localSeasonI['Items'][0]['ParentIndexNumber'], 'I local mode keeps release season 2');
eq(JellyfinIds::episode($splitEntryIdI), $localSeasonI['Items'][0]['Id'], 'I episode id is stable');
$localPlaybackI = $libraryLocalI->playbackInfo($localSeasonI['Items'][0]['Id']);
check($localPlaybackI !== null && !empty($localPlaybackI['MediaSources']), 'I local episode exposes playback info');
$localStreamI = $libraryLocalI->streamEntries($localSeasonI['Items'][0]['Id'], $localPlaybackI['MediaSources'][0]['Id']);
check($localStreamI !== null && $localStreamI['entry']['path'] === $splitPathI, 'I local episode streams the local file');
$numberingI->mode = 'tmdb';
$libraryTmdbI = $makeLibraryI();
$tmdbSeasonOneI = $libraryTmdbI->episodes($seriesI['Id'], 'user', 1);
eq(1, $tmdbSeasonOneI['TotalRecordCount'], 'I TMDB mode exposes the virtual season 1 episode');
eq(25, $tmdbSeasonOneI['Items'][0]['IndexNumber'], 'I TMDB mode maps release S02E01 to TMDB S01E25');
eq(1, $tmdbSeasonOneI['Items'][0]['ParentIndexNumber'], 'I TMDB mode uses TMDB season 1');
$tmdbPlaybackI = $libraryTmdbI->playbackInfo($tmdbSeasonOneI['Items'][0]['Id']);
check($tmdbPlaybackI !== null && !empty($tmdbPlaybackI['MediaSources']), 'I TMDB episode exposes playback info');
$tmdbStreamI = $libraryTmdbI->streamEntries($tmdbSeasonOneI['Items'][0]['Id'], $tmdbPlaybackI['MediaSources'][0]['Id']);
check($tmdbStreamI !== null && $tmdbStreamI['entry']['path'] === $splitPathI, 'I TMDB virtual episode streams the local split file');
eq(0, $libraryTmdbI->episodes($seriesI['Id'], 'user', 2)['TotalRecordCount'], 'I TMDB mode hides the local release season 2');
removeTree($projectI);

echo "J. Ignored intent maintenance\n";
$projectJ = tempProject('functional-j');
$storeJ = new AutomationStore($projectJ);
$settingsJ = new AutomationSettings($storeJ);
$storageJ = storageAt($projectJ . '/var/storage');
$createIntentJ = static function (string $key, string $type, array $extra = []) use ($storeJ): int {
    $created = $storeJ->createIntent(array_merge([
        'media_key' => $key,
        'media_type' => $type,
        'title' => 'Ignored Smoke ' . $key,
        'year' => 2024,
    ], $extra));

    return (int) $created['intent']['id'];
};
$ignoredOneJ = $createIntentJ('movie:tmdb:8101', 'movie', ['tmdb_id' => 8101]);
$ignoredTwoJ = $createIntentJ('movie:tmdb:8102', 'movie', ['tmdb_id' => 8102]);
$errorJ = $createIntentJ('movie:tmdb:8103', 'movie', ['tmdb_id' => 8103]);
$successJ = $createIntentJ('movie:tmdb:8104', 'movie', ['tmdb_id' => 8104]);
foreach ([$ignoredOneJ, $ignoredTwoJ] as $id) {
    $storeJ->updateIntent($id, [
        'status' => 'ignored',
        'attempts' => 2,
        'download_id' => 'old-download-' . $id,
        'info_hash' => str_repeat('f', 40),
        'file_idx' => 4,
        'error_message' => 'Ignored manually.',
    ]);
}
$storeJ->updateIntent($errorJ, ['status' => 'error', 'attempts' => 1, 'error_message' => 'Still failing.']);
$storeJ->updateIntent($successJ, ['status' => 'success', 'download_id' => 'kept-success']);
$resolverJ = (new ReflectionClass(ReleaseResolverService::class))->newInstanceWithoutConstructor();
$intentsJ = new ReleaseIntentService($storeJ, $settingsJ, $resolverJ, new AutomationNotifier($storageJ), $storageJ, $projectJ);
$restoredJ = $intentsJ->restoreAllIgnored();
eq(2, $restoredJ['restored'] ?? null, 'J restore all ignored restores every ignored intent');
$restoredOneJ = $storeJ->getIntent($ignoredOneJ);
eq('pending', $restoredOneJ['status'] ?? null, 'J restored intent is pending');
eq(0, (int) ($restoredOneJ['attempts'] ?? -1), 'J restored intent attempts are reset');
check(array_key_exists('download_id', $restoredOneJ) && $restoredOneJ['download_id'] === null, 'J restored intent download id is cleared');
check(array_key_exists('info_hash', $restoredOneJ) && $restoredOneJ['info_hash'] === null, 'J restored intent info hash is cleared');
check(array_key_exists('file_idx', $restoredOneJ) && $restoredOneJ['file_idx'] === null, 'J restored intent file index is cleared');
check(array_key_exists('error_message', $restoredOneJ) && $restoredOneJ['error_message'] === null, 'J restored intent error is cleared');
eq('error', $storeJ->getIntent($errorJ)['status'] ?? null, 'J restore all does not touch error intents');
eq('success', $storeJ->getIntent($successJ)['status'] ?? null, 'J restore all does not touch success intents');
eq(0, $intentsJ->restoreAllIgnored()['restored'] ?? null, 'J restore all is idempotent');
$intentsJ->ignoreIntent($errorJ, true);
eq('ignored', $storeJ->getIntent($errorJ)['status'] ?? null, 'J intent can be ignored before deletion');
$deletedJ = $intentsJ->deleteIgnoredIntent($errorJ);
check(!empty($deletedJ['success']), 'J ignored intent can be deleted', $deletedJ);
eq(null, $storeJ->getIntent($errorJ), 'J deleted ignored intent is gone');
$notIgnoredJ = $intentsJ->deleteIgnoredIntent($successJ);
check(empty($notIgnoredJ['success']) && ($notIgnoredJ['reason'] ?? null) === 'not_ignored', 'J non-ignored intent cannot be deleted');
check($storeJ->getIntent($successJ) !== null, 'J non-ignored intent remains after refused deletion');
$missingJ = $intentsJ->deleteIgnoredIntent(999999);
check(empty($missingJ['success']) && ($missingJ['reason'] ?? null) === 'not_found', 'J missing intent deletion is rejected');
removeTree($projectJ);

echo "K. Global intent reconciliation\n";
$projectK = tempProject('functional-k');
$storeK = new AutomationStore($projectK);
$settingsK = new AutomationSettings($storeK);
$storageK = storageAt($projectK . '/var/storage');
$sourcesK = [
    ['id' => 'scrob', 'provider' => 'scrob', 'label' => 'Scrob', 'enabled' => true, 'config' => ['api_key' => 'test', 'list_ids' => ['1']]],
    ['id' => 'mdblist', 'provider' => 'mdblist', 'label' => 'MDBList', 'enabled' => true, 'config' => ['api_key' => 'test', 'list_ids' => ['2']]],
];
$settingsK->save(['sources' => $sourcesK]);
$runScrobK = seedListRun($storeK, 'scrob', 3);
$runMdbK = seedListRun($storeK, 'mdblist', 2);
seedListItem($storeK, $runScrobK, [
    'remote_key' => 'k-movie-desired',
    'media_type' => 'movie',
    'tmdb_id' => 9101,
    'title' => 'Desired Movie',
    'year' => 2024,
    'list_ref' => '1',
], ['status' => 'missing']);
seedListItem($storeK, $runScrobK, [
    'remote_key' => 'k-series-desired',
    'media_type' => 'series',
    'tmdb_id' => 9102,
    'imdb_id' => 'tt9102002',
    'title' => 'Desired Show',
    'year' => 2024,
    'list_ref' => '1',
], [
    'status' => 'missing',
    'missing_episodes' => ['S01E01', 'S01E02'],
    'episodes_total' => 2,
    'episodes_present' => 0,
    'episodes_missing' => 2,
]);
seedListItem($storeK, $runScrobK, [
    'remote_key' => 'k-movie-wrong-list',
    'media_type' => 'movie',
    'tmdb_id' => 9103,
    'title' => 'Wrong List Movie',
    'year' => 2024,
    'list_ref' => '3',
], ['status' => 'missing']);
seedListItem($storeK, $runMdbK, [
    'remote_key' => 'k-movie-mdb',
    'source_id' => 'mdblist',
    'provider' => 'mdblist',
    'media_type' => 'movie',
    'tmdb_id' => 9104,
    'title' => 'MDB Movie',
    'year' => 2024,
    'list_ref' => '2',
], ['status' => 'missing']);
seedListItem($storeK, $runMdbK, [
    'remote_key' => 'k-movie-mdb-wrong-list',
    'source_id' => 'mdblist',
    'provider' => 'mdblist',
    'media_type' => 'movie',
    'tmdb_id' => 9105,
    'title' => 'MDB Wrong List',
    'year' => 2024,
    'list_ref' => '9',
], ['status' => 'missing']);

$createIntentK = static function (string $key, string $type, array $extra = []) use ($storeK): int {
    $created = $storeK->createIntent(array_merge([
        'media_key' => $key,
        'media_type' => $type,
        'title' => 'Reconcile Smoke ' . $key,
        'year' => 2024,
    ], $extra));

    return (int) $created['intent']['id'];
};
$desiredMovieK = $createIntentK('movie:tmdb:9101', 'movie', ['tmdb_id' => 9101, 'title' => 'Desired Movie']);
$desiredEpisodeOneK = $createIntentK('series:tmdb:9102:s01e01', 'series', ['tmdb_id' => 9102, 'imdb_id' => 'tt9102002', 'title' => 'Desired Show', 'season' => 1, 'episode' => 1]);
$desiredEpisodeTwoK = $createIntentK('series:tmdb:9102:s01e02', 'series', ['tmdb_id' => 9102, 'imdb_id' => 'tt9102002', 'title' => 'Desired Show', 'season' => 1, 'episode' => 2]);
$undesiredEpisodeK = $createIntentK('series:tmdb:9102:s01e03', 'series', ['tmdb_id' => 9102, 'imdb_id' => 'tt9102002', 'title' => 'Desired Show', 'season' => 1, 'episode' => 3]);
$wrongListMovieK = $createIntentK('movie:tmdb:9103', 'movie', ['tmdb_id' => 9103, 'title' => 'Wrong List Movie']);
$legacyKeyMovieK = $createIntentK('movie:title:legacy title:2024', 'movie', ['tmdb_id' => 9104, 'title' => 'Legacy Title']);
$wrongListMdbK = $createIntentK('movie:tmdb:9105', 'movie', ['tmdb_id' => 9105, 'title' => 'MDB Wrong List']);
$successK = $createIntentK('movie:tmdb:9106', 'movie', ['tmdb_id' => 9106, 'title' => 'Success Movie']);
$queuedK = $createIntentK('movie:tmdb:9107', 'movie', ['tmdb_id' => 9107, 'title' => 'Queued Movie']);
$downloadingK = $createIntentK('movie:tmdb:9108', 'movie', ['tmdb_id' => 9108, 'title' => 'Downloading Movie']);
$resolvingK = $createIntentK('movie:tmdb:9109', 'movie', ['tmdb_id' => 9109, 'title' => 'Resolving Movie']);
$noSourceK = $createIntentK('movie:tmdb:9110', 'movie', ['tmdb_id' => 9110, 'title' => 'No Source Movie']);
$errorK = $createIntentK('movie:tmdb:9111', 'movie', ['tmdb_id' => 9111, 'title' => 'Error Movie']);
$storeK->updateIntent($desiredEpisodeOneK, ['status' => 'ignored', 'error_message' => 'Ignored manually.']);
$storeK->updateIntent($desiredEpisodeTwoK, ['status' => 'error']);
$storeK->updateIntent($successK, ['status' => 'success']);
$storeK->updateIntent($queuedK, ['status' => 'queued']);
$storeK->updateIntent($downloadingK, ['status' => 'downloading']);
$storeK->updateIntent($resolvingK, ['status' => 'resolving']);
$storeK->updateIntent($noSourceK, ['status' => 'no_source']);
$storeK->updateIntent($errorK, ['status' => 'error']);

$resolverK = (new ReflectionClass(ReleaseResolverService::class))->newInstanceWithoutConstructor();
$intentsK = new ReleaseIntentService($storeK, $settingsK, $resolverK, new AutomationNotifier($storageK), $storageK, $projectK);
$totalBeforeK = $storeK->countIntents();
$previewK = $intentsK->reconcileFromLists(true);
check(!empty($previewK['success']), 'K reconciliation dry-run succeeds', $previewK);
eq(5, $previewK['stats']['would_delete'] ?? null, 'K dry-run finds five non-desired actionable intents');
eq(0, $previewK['stats']['deleted'] ?? null, 'K dry-run deletes nothing');
eq($totalBeforeK, $storeK->countIntents(), 'K dry-run leaves intent count unchanged');
$candidateIdsK = array_column($previewK['candidates'] ?? [], 'id');
check(in_array($undesiredEpisodeK, $candidateIdsK, true), 'K non-desired episode is a deletion candidate');
check(in_array($wrongListMovieK, $candidateIdsK, true), 'K wrong-list movie is a deletion candidate');
check(in_array($wrongListMdbK, $candidateIdsK, true), 'K wrong-list MDBList movie is a deletion candidate');
check(in_array($noSourceK, $candidateIdsK, true), 'K no_source movie is a deletion candidate');
check(in_array($errorK, $candidateIdsK, true), 'K error movie is a deletion candidate');
check(!in_array($successK, $candidateIdsK, true), 'K success intent is protected');
check(!in_array($queuedK, $candidateIdsK, true), 'K queued intent is protected');
check(!in_array($downloadingK, $candidateIdsK, true), 'K downloading intent is protected');
check(!in_array($resolvingK, $candidateIdsK, true), 'K resolving intent is protected');
check(!in_array($desiredEpisodeOneK, $candidateIdsK, true), 'K desired ignored intent is kept');
check(!in_array($legacyKeyMovieK, $candidateIdsK, true), 'K legacy media_key is matched through TMDB identity');

$appliedK = $intentsK->reconcileFromLists(false);
check(!empty($appliedK['success']), 'K reconciliation apply succeeds', $appliedK);
eq(5, $appliedK['stats']['deleted'] ?? null, 'K apply deletes five non-desired actionable intents');
eq($totalBeforeK - 5, $storeK->countIntents(), 'K apply keeps the expected intents');
eq(null, $storeK->getIntent($undesiredEpisodeK), 'K non-desired episode is deleted');
eq(null, $storeK->getIntent($wrongListMovieK), 'K wrong-list movie is deleted');
eq(null, $storeK->getIntent($wrongListMdbK), 'K wrong-list MDBList movie is deleted');
eq(null, $storeK->getIntent($noSourceK), 'K no_source movie is deleted');
eq(null, $storeK->getIntent($errorK), 'K error movie is deleted');
eq('pending', $storeK->getIntent($desiredMovieK)['status'] ?? null, 'K desired pending movie is kept');
eq('ignored', $storeK->getIntent($desiredEpisodeOneK)['status'] ?? null, 'K desired ignored episode stays ignored');
eq('success', $storeK->getIntent($successK)['status'] ?? null, 'K success history is kept even if non-desired');
eq('queued', $storeK->getIntent($queuedK)['status'] ?? null, 'K queued intent is kept');
eq('downloading', $storeK->getIntent($downloadingK)['status'] ?? null, 'K downloading intent is kept');
eq('resolving', $storeK->getIntent($resolvingK)['status'] ?? null, 'K resolving intent is kept');
eq('pending', $storeK->getIntent($legacyKeyMovieK)['status'] ?? null, 'K legacy-key desired movie is kept');

$sourcesK[1]['enabled'] = false;
$settingsK->save(['sources' => $sourcesK]);
$disabledSourceK = $intentsK->reconcileFromLists(false);
eq(1, $disabledSourceK['stats']['deleted'] ?? null, 'K disabling a source deletes its non-desired intents');
eq(null, $storeK->getIntent($legacyKeyMovieK), 'K disabled-source movie is deleted');
eq(0, $intentsK->reconcileFromLists(false)['stats']['deleted'] ?? null, 'K reconciliation is idempotent');

$sourcesK[0]['enabled'] = false;
$settingsK->save(['sources' => $sourcesK]);
$remainingK = $storeK->countIntents();
$skippedK = $intentsK->reconcileFromLists(false);
check(empty($skippedK['success']) && !empty($skippedK['skipped']), 'K reconciliation is skipped when no source is enabled');
eq($remainingK, $storeK->countIntents(), 'K skipped reconciliation deletes nothing');
$reconcileCommandK = new ReleaseIntentReconcileCommand($intentsK);
$reconcileTesterK = new CommandTester($reconcileCommandK);
eq(0, $reconcileTesterK->execute(['--dry-run' => true]), 'K reconciliation command exits successfully when skipped');
check(str_contains($reconcileTesterK->getDisplay(), 'skipped'), 'K reconciliation command reports the skip reason', $reconcileTesterK->getDisplay());
removeTree($projectK);

echo "L. Title refresh and empty-folder cleanup\n";
$projectL = tempProject('functional-l');
$moviesRootL = $projectL . '/nas/Movies';
$seriesRootL = $projectL . '/nas/TV Shows';
$moviePathL = $moviesRootL . '/Alien, le huitième passager (1979)/Alien, le huitième passager (1979).mkv';
$seriesPathL = $seriesRootL . '/Old Show Folder/Season 01/Old.Show.S01E01.mkv';
writeFile($moviePathL, str_repeat('m', 512));
writeFile($seriesPathL, str_repeat('s', 512));
mkdir($moviesRootL . '/Old Empty/Nested Empty', 0777, true);
mkdir($moviesRootL . '/Deep/A/B', 0777, true);
mkdir($moviesRootL . '/Non Empty/Keep (2020)', 0777, true);
writeFile($moviesRootL . '/Non Empty/Keep (2020)/Keep.mkv', 'video');
mkdir($moviesRootL . '/.hidden-empty', 0777, true);
$storeL = new AutomationStore($projectL);
$settingsL = new AutomationSettings($storeL);
$storageL = storageAt($projectL . '/var/storage');
$settingsL->save([
    'roots' => [
        'movies' => ['label' => 'Movies', 'path' => $moviesRootL, 'type' => 'movie', 'enabled' => true],
        'series' => ['label' => 'TV Shows', 'path' => $seriesRootL, 'type' => 'series', 'enabled' => true],
        'anime' => ['label' => 'Anime', 'path' => $projectL . '/nas/Anime', 'type' => 'anime', 'enabled' => false],
    ],
    'metadata' => ['title_language' => 'en-US'],
]);
addLibraryEntry($storeL, [
    'root_key' => 'movies',
    'path' => $moviePathL,
    'relative_path' => 'Alien, le huitième passager (1979)/Alien, le huitième passager (1979).mkv',
    'filename' => basename($moviePathL),
    'media_type' => 'movie',
    'title' => 'Alien, le huitième passager',
    'title_normalized' => 'alien le huitieme passager',
    'year' => 1979,
    'tmdb_id' => 348,
    'imdb_id' => 'tt0078748',
    'media_key' => 'movie:tmdb:348',
    'size' => 512,
]);
addLibraryEntry($storeL, [
    'root_key' => 'series',
    'path' => $seriesPathL,
    'relative_path' => 'Old Show Folder/Season 01/' . basename($seriesPathL),
    'filename' => basename($seriesPathL),
    'media_type' => 'series',
    'title' => 'Ancien Titre',
    'title_normalized' => 'ancien titre',
    'year' => 2022,
    'season' => 1,
    'episode' => 1,
    'tmdb_id' => 114410,
    'imdb_id' => 'tt13616990',
    'media_key' => 'series:tmdb:114410:s01e01',
    'size' => 512,
    'show_folder' => 'Old Show Folder',
]);
$tmdbL = new FunctionalFakeTmdb();
$tmdbL->detailsMap[348] = ['id' => 348, 'type' => 'movie', 'title' => 'Alien', 'year' => '1979'];
$tmdbL->detailsMap[114410] = ['id' => 114410, 'type' => 'series', 'title' => 'Chainsaw Man', 'year' => '2022'];
$titleRefreshL = new LibraryTitleRefreshService($storeL, $settingsL, $tmdbL, $storageL, $projectL);
$refreshL = $titleRefreshL->refresh(50, 'en-US', null, true);
check(!empty($refreshL['success']), 'L title refresh succeeds', $refreshL);
eq(2, $refreshL['state']['updated'] ?? null, 'L title refresh updates movie and series entries');
$movieRowL = $storeL->getPdo()->query("SELECT title, show_folder FROM library_entries WHERE media_key = 'movie:tmdb:348'")->fetch();
eq('Alien', $movieRowL['title'] ?? null, 'L movie title is refreshed');
$seriesRowL = $storeL->getPdo()->query("SELECT title, show_folder, path FROM library_entries WHERE media_key = 'series:tmdb:114410:s01e01'")->fetch();
eq('Chainsaw Man', $seriesRowL['title'] ?? null, 'L series title is refreshed');
eq('Chainsaw Man (2022)', $seriesRowL['show_folder'] ?? null, 'L series show folder is refreshed');
eq($seriesPathL, $seriesRowL['path'] ?? null, 'L title refresh does not move files');

$renameL = new LibraryRenameService($storeL, $settingsL, $tmdbL);
$prunePreviewL = $renameL->pruneEmptyFolders(['movies'], true);
check(!empty($prunePreviewL['success']), 'L empty-folder preview succeeds', $prunePreviewL);
eq(5, $prunePreviewL['stats']['candidates'] ?? null, 'L preview finds nested empty folders');
check(is_dir($moviesRootL . '/Old Empty/Nested Empty'), 'L preview does not delete folders');
$pruneApplyL = $renameL->pruneEmptyFolders(['movies'], false);
check(!empty($pruneApplyL['success']), 'L empty-folder cleanup succeeds', $pruneApplyL);
eq(5, $pruneApplyL['stats']['deleted'] ?? null, 'L cleanup deletes empty folder trees');
check(!is_dir($moviesRootL . '/Old Empty'), 'L empty parent folder is removed');
check(!is_dir($moviesRootL . '/Deep'), 'L deeply nested empty folder is removed');
check(is_dir($moviesRootL . '/Non Empty/Keep (2020)'), 'L non-empty folder is preserved');
check(is_file($moviesRootL . '/Non Empty/Keep (2020)/Keep.mkv'), 'L non-empty folder keeps its file');
check(is_dir($moviesRootL . '/.hidden-empty'), 'L hidden folders are preserved');
removeTree($projectL);

echo "\n$passes passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
