<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\Automation\AutomationNotifier;
use App\Service\Automation\AutomationSettings;
use App\Service\Automation\AutomationStore;
use App\Service\Automation\ListMatcher;
use App\Service\Automation\ReleaseIntentService;
use App\Service\Automation\ReleaseIntegrityService;
use App\Service\JsonStorage;
use App\Service\MediaDownloadService;
use App\Service\TmdbService;

$passes = 0;
$failures = 0;

function check(bool $condition, string $label): void
{
    global $passes, $failures;
    if ($condition) {
        $passes++;
        echo "  ok  $label\n";
        return;
    }
    $failures++;
    echo "  FAIL $label\n";
}

function eq(mixed $expected, mixed $actual, string $label): void
{
    check($expected === $actual, $label . ' => ' . var_export($actual, true));
}

function tempProject(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(4));
    mkdir($dir . '/var/db', 0700, true);
    mkdir($dir . '/var/storage', 0700, true);
    mkdir($dir . '/files', 0700, true);

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

function sparseFile(string $path, int $size, string $head = '<mkv>'): void
{
    $handle = fopen($path, 'wb');
    fwrite($handle, $head);
    ftruncate($handle, $size);
    fclose($handle);
}

function addHistory(JsonStorage $storage, string $downloadId, string $path, int $size, bool $legacy = false): void
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
    if (!$legacy) {
        $entry['actual_size'] = $size;
    }
    $history[] = $entry;
    $storage->set('history', $history);
}

class BackfillFakeTmdb extends TmdbService
{
    public function __construct()
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function details(string $type, int $id, ?string $language = null): ?array
    {
        if ($type === 'series' && $id === 6001) {
            return ['id' => 6001, 'seasons' => [['season_number' => 1, 'episode_count' => 416]]];
        }

        return null;
    }

    public function season(int $tvId, int $seasonNumber): array
    {
        if ($tvId !== 6001 || $seasonNumber !== 1) {
            return [];
        }

        $episodes = [];
        for ($i = 1; $i <= 416; $i++) {
            $episodes[] = ['episode_number' => $i, 'name' => 'Episode ' . $i, 'air_date' => '2020-01-01'];
        }

        return $episodes;
    }
}

$project = tempProject('backfill-smoke');
$storage = storageAt($project . '/var/storage');
$store = new AutomationStore($project);

echo "Release verification\n";
$goodFile = $project . '/files/Good Movie.mkv';
sparseFile($goodFile, 2 * 1024 * 1024);
$good = $store->createIntent([
    'media_key' => 'movie:tmdb:1001',
    'media_type' => 'movie',
    'tmdb_id' => 1001,
    'title' => 'Good Movie',
    'year' => 2020,
]);
$goodId = (int) $good['intent']['id'];
$store->updateIntent($goodId, [
    'status' => 'success',
    'download_id' => 'dl-good',
    'release_explanation' => '1080p x265 · 0.3 Go',
]);
addHistory($storage, 'dl-good', $goodFile, filesize($goodFile));

$integrity = new ReleaseIntegrityService($store, $storage);
$preview = $integrity->verifySuccessfulIntents(false, false, 10);
eq(1, $preview['stats']['checked'], 'preview checks one success intent');
eq(0, $preview['stats']['invalid'], 'rounded release label does not flag a valid file');
eq('ok', $preview['stats']['details'][0]['status'] ?? null, 'valid file is reported ok');

$truncatedFile = $project . '/files/Truncated Movie.mkv';
sparseFile($truncatedFile, (int) (1.5 * 1024 * 1024));
$truncated = $store->createIntent([
    'media_key' => 'movie:tmdb:1002',
    'media_type' => 'movie',
    'tmdb_id' => 1002,
    'title' => 'Truncated Movie',
    'year' => 2021,
]);
$truncatedId = (int) $truncated['intent']['id'];
$store->updateIntent($truncatedId, ['status' => 'success', 'download_id' => 'dl-truncated']);
addHistory($storage, 'dl-truncated', $truncatedFile, 2 * 1024 * 1024);

$missing = $store->createIntent([
    'media_key' => 'movie:tmdb:1003',
    'media_type' => 'movie',
    'tmdb_id' => 1003,
    'title' => 'Missing Movie',
    'year' => 2022,
]);
$missingId = (int) $missing['intent']['id'];
$store->updateIntent($missingId, ['status' => 'success', 'download_id' => 'dl-missing']);
addHistory($storage, 'dl-missing', $project . '/files/Missing Movie.mkv', 2 * 1024 * 1024);

$htmlFile = $project . '/files/Html Movie.mkv';
sparseFile($htmlFile, 2 * 1024 * 1024, '<html><body>expired</body></html>');
$html = $store->createIntent([
    'media_key' => 'movie:tmdb:1004',
    'media_type' => 'movie',
    'tmdb_id' => 1004,
    'title' => 'HTML Movie',
    'year' => 2023,
]);
$htmlId = (int) $html['intent']['id'];
$store->updateIntent($htmlId, ['status' => 'success', 'download_id' => 'dl-html']);
addHistory($storage, 'dl-html', $htmlFile, filesize($htmlFile));

$legacyFile = $project . '/files/Legacy Movie.mkv';
sparseFile($legacyFile, 2 * 1024 * 1024);
$legacy = $store->createIntent([
    'media_key' => 'movie:tmdb:1005',
    'media_type' => 'movie',
    'tmdb_id' => 1005,
    'title' => 'Legacy Movie',
    'year' => 2024,
]);
$legacyId = (int) $legacy['intent']['id'];
$store->updateIntent($legacyId, ['status' => 'success', 'download_id' => 'dl-legacy']);
addHistory($storage, 'dl-legacy', $legacyFile, 300 * 1024 * 1024, true);

$preview = $integrity->verifySuccessfulIntents(false, false, 10);
eq(3, $preview['stats']['invalid'], 'truncated, missing and HTML files are invalid');
$legacyDetail = null;
foreach ($preview['stats']['details'] as $detail) {
    if ((int) ($detail['id'] ?? 0) === $legacyId) {
        $legacyDetail = $detail;
        break;
    }
}
eq('ok', $legacyDetail['status'] ?? null, 'legacy history without actual_size is not compared to a pack label');
$reasons = array_column($preview['stats']['details'], 'reason', 'id');
check(str_contains((string) ($reasons[$htmlId] ?? ''), 'HTML'), 'HTML payload is detected');
check(str_contains((string) ($reasons[$missingId] ?? ''), 'missing'), 'missing file is detected');
check(str_contains((string) ($reasons[$truncatedId] ?? ''), 'exact history size'), 'truncated file is detected against exact history size');

$refused = $integrity->verifySuccessfulIntents(true, false, 10);
check(empty($refused['success']), 'apply without quarantine is refused');
check(is_file($truncatedFile), 'refused apply leaves file untouched');
eq('success', $store->getIntent($truncatedId)['status'] ?? null, 'refused apply leaves intent untouched');

$applied = $integrity->verifySuccessfulIntents(true, true, 10);
check(!empty($applied['success']), 'apply with quarantine succeeds');
check(!is_file($truncatedFile), 'invalid file is moved by quarantine');
check(count(glob($truncatedFile . '.invalid-*') ?: []) === 1, 'quarantine target exists');
$reintent = $store->getIntent($truncatedId);
eq('pending', $reintent['status'] ?? null, 'invalid intent is reactivated');
eq(null, $reintent['download_id'] ?? null, 'download id is cleared');
eq(0, (int) ($reintent['attempts'] ?? -1), 'attempts are reset');
check(is_file($goodFile), 'valid file is never quarantined');
removeTree($project);

echo "Orphan success reactivation\n";
$orphanProject = tempProject('backfill-orphan-smoke');
$orphanStore = new AutomationStore($orphanProject);
$movieData = [
    'media_key' => 'movie:tmdb:2001',
    'media_type' => 'movie',
    'tmdb_id' => 2001,
    'imdb_id' => 'tt2001001',
    'title' => 'Orphan Movie',
    'year' => 2020,
];
$movieIntent = $orphanStore->createIntent($movieData);
$movieId = (int) $movieIntent['intent']['id'];
$orphanStore->updateIntent($movieId, [
    'status' => 'success',
    'attempts' => 3,
    'download_id' => 'old-dl',
    'info_hash' => 'old-hash',
    'file_idx' => 7,
]);
$reactivated = $orphanStore->createIntent($movieData, true);
eq('reactivated', $reactivated['status'], 'missing movie success intent is reactivated');
eq('pending', $reactivated['intent']['status'] ?? null, 'reactivated movie is pending');
eq(0, (int) ($reactivated['intent']['attempts'] ?? -1), 'reactivated movie attempts are reset');
eq(null, $reactivated['intent']['download_id'] ?? null, 'reactivated movie download id is cleared');
eq(null, $reactivated['intent']['info_hash'] ?? null, 'reactivated movie info hash is cleared');
eq(null, $reactivated['intent']['file_idx'] ?? null, 'reactivated movie file index is cleared');
eq($movieData['media_key'], $reactivated['intent']['media_key'] ?? null, 'reactivated movie keeps its media key');
eq($movieData['title'], $reactivated['intent']['title'] ?? null, 'reactivated movie keeps its title');

$moviePath = $orphanProject . '/files/Orphan Movie (2020).mkv';
sparseFile($moviePath, 2 * 1024 * 1024);
$orphanStore->upsertLibraryEntry([
    'root_key' => 'movies',
    'path' => $moviePath,
    'relative_path' => 'Orphan Movie (2020)/Orphan Movie (2020).mkv',
    'folder_path' => 'Orphan Movie (2020)',
    'filename' => basename($moviePath),
    'extension' => 'mkv',
    'media_type' => 'movie',
    'title' => 'Orphan Movie',
    'title_normalized' => 'orphan movie',
    'year' => 2020,
    'season' => null,
    'episode' => null,
    'quality' => '1080p',
    'size' => filesize($moviePath),
    'mtime' => filemtime($moviePath),
    'media_key' => 'movie:tmdb:2001',
    'tmdb_id' => 2001,
    'imdb_id' => 'tt2001001',
    'confidence' => 0.99,
    'parse_source' => 'filename',
]);
$orphanStore->updateIntent($movieId, ['status' => 'success', 'download_id' => 'active-dl']);
$present = $orphanStore->createIntent($movieData, true);
eq('existing', $present['status'], 'active movie entry prevents reactivation');
eq('success', $present['intent']['status'] ?? null, 'present movie intent remains success');

$episodeData = [
    'media_key' => 'series:tmdb:3001:s01e01',
    'media_type' => 'series',
    'tmdb_id' => 3001,
    'imdb_id' => 'tt3001001',
    'title' => 'Orphan Show',
    'year' => 2021,
    'season' => 1,
    'episode' => 1,
    'release_season' => 2,
    'release_episode' => 1,
];
$episodeIntent = $orphanStore->createIntent($episodeData);
$episodeId = (int) $episodeIntent['intent']['id'];
$orphanStore->updateIntent($episodeId, ['status' => 'success', 'attempts' => 2, 'download_id' => 'old-ep']);
$episodeReactivated = $orphanStore->createIntent($episodeData, true);
eq('reactivated', $episodeReactivated['status'], 'missing episode success intent is reactivated');
eq(2, (int) ($episodeReactivated['intent']['release_season'] ?? 0), 'reactivated episode keeps release season');
eq(1, (int) ($episodeReactivated['intent']['release_episode'] ?? 0), 'reactivated episode keeps release episode');
$episodePath = $orphanProject . '/files/Orphan Show (2021)/Season 02/Orphan Show (2021) - S02E01.mkv';
@mkdir(dirname($episodePath), 0700, true);
sparseFile($episodePath, 2 * 1024 * 1024);
$orphanStore->upsertLibraryEntry([
    'root_key' => 'series',
    'path' => $episodePath,
    'relative_path' => 'Orphan Show (2021)/Season 02/Orphan Show (2021) - S02E01.mkv',
    'folder_path' => 'Orphan Show (2021)/Season 02',
    'filename' => basename($episodePath),
    'extension' => 'mkv',
    'media_type' => 'series',
    'title' => 'Orphan Show',
    'title_normalized' => 'orphan show',
    'year' => 2021,
    'season' => 2,
    'episode' => 1,
    'quality' => '1080p',
    'size' => filesize($episodePath),
    'mtime' => filemtime($episodePath),
    'media_key' => 'series:tmdb:3001:s02e01',
    'tmdb_id' => 3001,
    'imdb_id' => 'tt3001001',
    'confidence' => 0.99,
    'parse_source' => 'filename',
    'show_folder' => 'Orphan Show (2021)',
]);
$orphanStore->updateIntent($episodeId, ['status' => 'success', 'download_id' => 'active-ep']);
$episodePresent = $orphanStore->createIntent($episodeData, true);
eq('existing', $episodePresent['status'], 'active split episode prevents reactivation');
removeTree($orphanProject);

echo "Stale queued/downloading recovery\n";
$staleProject = tempProject('backfill-stale-smoke');
$staleStorage = storageAt($staleProject . '/var/storage');
$staleStore = new AutomationStore($staleProject);
$staleSettings = new AutomationSettings($staleStore);
$staleSettings->save(['intents' => ['retry_backoff_minutes' => 60, 'max_attempts' => 5]]);
$staleService = (new ReflectionClass(ReleaseIntentService::class))->newInstanceWithoutConstructor();
foreach (['store' => $staleStore, 'settings' => $staleSettings, 'storage' => $staleStorage] as $property => $value) {
    $ref = new ReflectionProperty(ReleaseIntentService::class, $property);
    $ref->setAccessible(true);
    $ref->setValue($staleService, $value);
}
$makeStaleIntent = static function (string $key, string $status, ?string $downloadId, int $attempts) use ($staleStore): int {
    $result = $staleStore->createIntent([
        'media_key' => $key,
        'media_type' => 'movie',
        'tmdb_id' => abs(crc32($key)),
        'title' => $key,
        'year' => 2020,
    ]);
    $id = (int) $result['intent']['id'];
    $staleStore->updateIntent($id, [
        'status' => $status,
        'download_id' => $downloadId,
        'attempts' => $attempts,
    ]);
    $staleStore->getPdo()->exec("UPDATE intents SET updated_at = '2020-01-01 00:00:00' WHERE id = " . $id);

    return $id;
};
$orphanQueuedId = $makeStaleIntent('movie:tmdb:4001', 'queued', 'orphan-queued', 2);
$activeDownloadingId = $makeStaleIntent('movie:tmdb:4002', 'downloading', 'active-download', 1);
$historyQueuedId = $makeStaleIntent('movie:tmdb:4003', 'queued', 'history-download', 1);
$noIdDownloadingId = $makeStaleIntent('movie:tmdb:4004', 'downloading', null, 1);
$staleStorage->set('active_worker_task', ['download_id' => 'active-download']);
$staleStorage->set('history', [['download_id' => 'history-download', 'status' => 'success', 'date' => date('Y-m-d H:i:s')]]);
$recovery = $staleService->recoverStaleDownloads(2, false);
eq(2, $recovery['recovered'], 'orphan queued and downloadless stale intents are recovered');
eq(2, $recovery['skipped'], 'active and historical downloads are preserved');
eq('pending', $staleStore->getIntent($orphanQueuedId)['status'] ?? null, 'orphan queued intent is pending');
eq(null, $staleStore->getIntent($orphanQueuedId)['download_id'] ?? null, 'orphan queued download id is cleared');
check(!empty($staleStore->getIntent($orphanQueuedId)['next_attempt_at']), 'orphan queued intent has a backoff date');
eq('pending', $staleStore->getIntent($noIdDownloadingId)['status'] ?? null, 'downloadless stale downloading intent is pending');
eq('downloading', $staleStore->getIntent($activeDownloadingId)['status'] ?? null, 'active downloading intent is preserved');
eq('queued', $staleStore->getIntent($historyQueuedId)['status'] ?? null, 'historical queued intent is preserved');
removeTree($staleProject);

echo "Movie intent cap\n";
$capProject = tempProject('backfill-cap-smoke');
$capStorage = storageAt($capProject . '/var/storage');
$capStore = new AutomationStore($capProject);
$capSettings = new AutomationSettings($capStore);
$capSettings->save([
    'sources' => [[
        'id' => 'scrob',
        'provider' => 'scrob',
        'label' => 'Scrob',
        'enabled' => true,
        'config' => ['auto_download' => false, 'list_ids' => []],
    ]],
    'backfill' => ['max_intents_per_run' => 1, 'queue_guard' => 20],
]);
$capRun = $capStore->startListRun('scrob', 'scrob', 'dry_run');
$capStore->finishListRun($capRun, ['items_seen' => 2], 'success');
foreach ([
    ['key' => 'cap-movie-1', 'tmdb' => 5001, 'title' => 'Cap Movie One'],
    ['key' => 'cap-movie-2', 'tmdb' => 5002, 'title' => 'Cap Movie Two'],
] as $movie) {
    $capStore->upsertListItem([
        'source_id' => 'scrob',
        'provider' => 'scrob',
        'list_ref' => '1',
        'remote_key' => $movie['key'],
        'media_type' => 'movie',
        'tmdb_id' => $movie['tmdb'],
        'title' => $movie['title'],
        'title_normalized' => strtolower($movie['title']),
        'year' => 2020,
        'raw' => [],
    ], $capRun);
    $itemId = (int) $capStore->getPdo()->query("SELECT id FROM list_items WHERE remote_key = '" . $movie['key'] . "'")->fetchColumn();
    $capStore->updateListItemMatch($itemId, [
        'status' => 'missing',
        'reason' => 'not_in_library',
        'episodes_total' => 0,
        'episodes_present' => 0,
        'episodes_missing' => 0,
        'missing_episodes' => [],
    ], $capRun);
}
$capService = (new ReflectionClass(ReleaseIntentService::class))->newInstanceWithoutConstructor();
foreach (['store' => $capStore, 'settings' => $capSettings, 'storage' => $capStorage] as $property => $value) {
    $ref = new ReflectionProperty(ReleaseIntentService::class, $property);
    $ref->setAccessible(true);
    $ref->setValue($capService, $value);
}
$capResult = $capService->queueMissingMovies(false);
eq(1, $capResult['stats']['created'], 'movie queue respects max_intents_per_run');
check(str_contains(implode(' ', $capResult['stats']['warnings']), 'Maximum intents per run'), 'movie cap emits a warning');
eq(1, (int) $capStore->getPdo()->query("SELECT COUNT(*) FROM intents WHERE media_type = 'movie'")->fetchColumn(), 'only one movie intent is created');
removeTree($capProject);

echo "Complete bounded missing_json\n";
$missingProject = tempProject('backfill-missing-smoke');
$missingStorage = storageAt($missingProject . '/var/storage');
$missingStore = new AutomationStore($missingProject);
$missingSettings = new AutomationSettings($missingStore);
$missingSettings->save([
    'sources' => [[
        'id' => 'scrob',
        'provider' => 'scrob',
        'label' => 'Scrob',
        'enabled' => true,
        'config' => ['auto_download' => false, 'auto_download_series' => true, 'list_ids' => []],
    ]],
    'backfill' => ['max_intents_per_run' => 50, 'queue_guard' => 20, 'exclude_specials' => true],
]);
$missingRun = $missingStore->startListRun('scrob', 'scrob', 'dry_run');
$missingStore->finishListRun($missingRun, ['items_seen' => 1], 'success');
$missingStore->upsertListItem([
    'source_id' => 'scrob',
    'provider' => 'scrob',
    'list_ref' => '1',
    'remote_key' => 'bleach-416',
    'media_type' => 'series',
    'tmdb_id' => 6001,
    'imdb_id' => 'tt6001001',
    'title' => 'Bleach 416',
    'title_normalized' => 'bleach 416',
    'year' => 2004,
    'raw' => [],
], $missingRun);
$missingItemId = (int) $missingStore->getPdo()->query("SELECT id FROM list_items WHERE remote_key = 'bleach-416'")->fetchColumn();
$missingItem = $missingStore->getListItem($missingItemId);
$matcher = new ListMatcher($missingStore, $missingSettings, new BackfillFakeTmdb(), null);
$matcher->beginRun(10);
$match = $matcher->match($missingItem);
eq('missing', $match['status'], '416-episode series is missing');
eq(416, (int) $match['episodes_total'], '416 aired episodes are counted');
eq(416, count($match['missing_episodes']), 'all 416 missing episodes are stored');
$missingStore->updateListItemMatch($missingItemId, $match, $missingRun);
$storedMissing = json_decode((string) $missingStore->getListItem($missingItemId)['missing_json'], true);
eq(416, count($storedMissing), 'missing_json keeps the complete bounded list');
$listsControllerSource = file_get_contents(dirname(__DIR__) . '/src/Controller/AutomationListsController.php');
check(str_contains($listsControllerSource, 'array_slice($missing, 0, 50)'), 'lists UI caps the missing episode preview at 50');
$missingService = (new ReflectionClass(ReleaseIntentService::class))->newInstanceWithoutConstructor();
foreach (['store' => $missingStore, 'settings' => $missingSettings, 'storage' => $missingStorage, 'numbering' => null] as $property => $value) {
    $ref = new ReflectionProperty(ReleaseIntentService::class, $property);
    $ref->setAccessible(true);
    $ref->setValue($missingService, $value);
}
$missingQueue = $missingService->queueMissingEpisodes(false);
eq(50, $missingQueue['stats']['created'], '416 missing episodes are queued in a 50-intent batch');
check(str_contains(implode(' ', $missingQueue['stats']['warnings']), 'Maximum intents per run'), '416 queue reports the per-run cap');
eq(50, (int) $missingStore->getPdo()->query("SELECT COUNT(*) FROM intents WHERE media_type = 'series'")->fetchColumn(), 'first batch contains exactly 50 intents');
removeTree($missingProject);

echo "Deterministic file selection\n";
$selector = (new ReflectionClass(MediaDownloadService::class))->newInstanceWithoutConstructor();
$selectFile = new ReflectionMethod(MediaDownloadService::class, 'selectFile');
$selectFile->setAccessible(true);
$packIndexed = [
    ['index' => 0, 'torrent_index' => 5, 'name' => 'Show.S01E03.mkv', 'path' => 'Show.S01E03.mkv', 'size' => 100, 'link' => 'a'],
    ['index' => 1, 'torrent_index' => 9, 'name' => 'Show.S01E04.mkv', 'path' => 'Show.S01E04.mkv', 'size' => 200, 'link' => 'b'],
];
$pick = $selectFile->invoke($selector, $packIndexed, 5, '', 1, 3, 'Show.S01E03');
check(($pick['name'] ?? null) === 'Show.S01E03.mkv', 'exact episode wins with matching torrent index');
$pick = $selectFile->invoke($selector, $packIndexed, 9, '', 1, 3, 'Show.S01E03');
check(($pick['name'] ?? null) === 'Show.S01E03.mkv', 'wrong torrent index does not override exact episode');
$packUnindexed = [
    ['index' => 0, 'name' => 'Show.S01E05.mkv', 'path' => 'Show.S01E05.mkv', 'size' => 100, 'link' => 'a'],
    ['index' => 1, 'name' => 'Show.S01E03.mkv', 'path' => 'Show.S01E03.mkv', 'size' => 200, 'link' => 'b'],
];
$pick = $selectFile->invoke($selector, $packUnindexed, 0, '', 1, 3, 'Show.S01E03');
check(($pick['name'] ?? null) === 'Show.S01E03.mkv', 'reindexed fileIdx is ignored without torrent index metadata');
$pick = $selectFile->invoke($selector, [$packUnindexed[0]], 0, '', 1, 3, 'Show.S01E03');
eq(null, $pick, 'pack without exact episode is refused');
$pick = $selectFile->invoke($selector, [['index' => 0, 'name' => 'Show.S01E03E04.mkv', 'path' => 'Show.S01E03E04.mkv', 'size' => 100, 'link' => 'a']], 0, '', 1, 3, 'Show.S01E03E04');
eq(null, $pick, 'multi-episode file is refused');
$pick = $selectFile->invoke($selector, [['index' => 0, 'name' => 'Show.S01.1080p.mkv', 'path' => 'Show.S01.1080p.mkv', 'size' => 100, 'link' => 'a']], 0, '', 1, 3, 'Show.S01.1080p');
eq(null, $pick, 'single-file season pack without episode marker is refused');
$pick = $selectFile->invoke($selector, [['index' => 0, 'name' => 'Show.1080p.mkv', 'path' => 'Show.1080p.mkv', 'size' => 100, 'link' => 'a']], 0, '', 1, 3, 'Show');
eq(null, $pick, 'unmarked single file is refused for an episode');

$movieFiles = [
    ['index' => 0, 'torrent_index' => 2, 'name' => 'Movie.mkv', 'path' => 'Movie.mkv', 'size' => 100, 'link' => 'a'],
    ['index' => 1, 'torrent_index' => 3, 'name' => 'Featurette.mkv', 'path' => 'Featurette.mkv', 'size' => 200, 'link' => 'b'],
];
$pick = $selectFile->invoke($selector, $movieFiles, 2, '', null, null, '');
check(($pick['name'] ?? null) === 'Movie.mkv', 'movie uses a reliable torrent index');
$pick = $selectFile->invoke($selector, $movieFiles, null, 'Featurette.mkv', null, null, '');
check(($pick['name'] ?? null) === 'Featurette.mkv', 'movie falls back to the requested filename');
$pick = $selectFile->invoke($selector, $movieFiles, null, '', null, null, '');
eq(null, $pick, 'ambiguous movie pack never falls back to the biggest file');
$pick = $selectFile->invoke($selector, [['index' => 0, 'name' => 'Movie.mkv', 'path' => 'Movie.mkv', 'size' => 100, 'link' => 'a']], 0, '', null, null, '');
check(($pick['name'] ?? null) === 'Movie.mkv', 'single-file movie is selected');
$pick = $selectFile->invoke($selector, [
    ['index' => 0, 'name' => 'Movie.mkv', 'path' => 'Movie.mkv', 'size' => 100, 'link' => 'a'],
    ['index' => 1, 'name' => 'Extra.mkv', 'path' => 'Extra.mkv', 'size' => 200, 'link' => 'b'],
], 0, '', null, null, '');
eq(null, $pick, 'ambiguous movie index without metadata is refused');

echo "Grouped download notifications\n";
$notifyProject = tempProject('backfill-notify-smoke');
$notifyStorage = storageAt($notifyProject . '/var/storage');
$notifier = new AutomationNotifier($notifyStorage);
for ($i = 1; $i <= 10; $i++) {
    $notifier->queueDownloadSummary('series', 'success', 'Show S01E' . str_pad((string) $i, 2, '0', STR_PAD_LEFT));
}
$notifier->queueDownloadSummary('series', 'error', 'Show S01E11', 'Source unavailable');
$notifier->queueDownloadSummary('movie', 'success', 'Some Movie (2020)');
check($notifier->flushDownloadSummary(false) === false, 'summary flush is debounced while downloads are recent');
check($notifier->flushDownloadSummary(true) === true, 'forced summary flush succeeds');
$notifications = $notifyStorage->get('server_notifications', []);
eq(1, count($notifications), 'multiple downloads produce one grouped notification');
$message = (string) ($notifications[0]['message'] ?? '');
check(str_contains($message, '11 success'), 'grouped notification counts successes');
check(str_contains($message, '1 error'), 'grouped notification counts errors');
check(str_contains($message, 'Series: 11'), 'grouped notification counts series');
check(str_contains($message, 'Movies: 1'), 'grouped notification counts movies');
check(str_contains($message, 'Source unavailable'), 'grouped notification keeps a grouped error sample');
removeTree($notifyProject);

echo "\n$passes passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
