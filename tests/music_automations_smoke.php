<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\EventSubscriber\AuthSubscriber;
use App\EventSubscriber\CsrfSubscriber;
use App\Service\AuthService;
use App\Service\CsrfTokenService;
use App\Service\JsonStorage;
use App\Service\MediaTypeHelper;
use App\Service\MusicAutomation\MusicAutomationRunner;
use App\Service\MusicAutomation\MusicAutomationScheduler;
use App\Service\MusicAutomation\MusicAutomationStore;
use App\Service\MusicAutomation\MusicLibraryIndexInterface;
use App\Service\MusicAutomation\MusicQueueService;
use App\Service\MusicAutomation\MusicTrackMatcher;
use App\Service\MusicAutomation\PlaylistMetadataProviderInterface;
use App\Service\MusicAutomation\PlaylistSearchService;
use App\Service\MusicAutomation\SpotifyUserPlaylistMetadataProvider;
use App\Service\MusicAutomation\TaggedMusicLibraryIndex;
use App\Service\NavigationService;
use App\Service\QueueManager;
use App\Service\ServerNotificationService;
use App\Service\SpotifyService;
use App\Service\SpotifyUserService;
use App\Command\MusicAutomationsCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

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

function tempDir(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

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

function storageAt(string $dir, array $config = []): JsonStorage
{
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    if ($config !== []) {
        file_put_contents($dir . '/config.json', json_encode($config));
    }
    $storage = (new ReflectionClass(JsonStorage::class))->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(JsonStorage::class, 'storageDir');
    $property->setAccessible(true);
    $property->setValue($storage, $dir);

    return $storage;
}

function routerInventory(): array
{
    $process = new Process([PHP_BINARY, 'bin/console', 'debug:router', '--format=json'], dirname(__DIR__));
    $process->setTimeout(60);
    $process->run();
    if (!$process->isSuccessful()) {
        return [];
    }
    $routes = json_decode($process->getOutput(), true);

    return is_array($routes) ? $routes : [];
}

function methodHas(string $method, string $verb): bool
{
    return strtoupper($method) === 'ANY' || preg_match('/\b' . $verb . '\b/', strtoupper($method)) === 1;
}

function makeRule(MusicAutomationStore $store, array $overrides = []): array
{
    $scheduler = new MusicAutomationScheduler();
    $created = '2026-01-01 00:00:00';
    $data = array_merge([
        'playlist_id' => 'PL' . substr(bin2hex(random_bytes(12)), 0, 20),
        'playlist_url' => 'https://open.spotify.com/playlist/' . 'PL' . substr(bin2hex(random_bytes(12)), 0, 20),
        'name' => 'Smoke Playlist',
        'owner' => 'Tester',
        'visibility' => 'public',
        'frequency' => 'daily',
        'run_time' => '03:00',
        'weekday' => null,
        'timezone' => 'UTC',
        'enabled' => true,
        'created_at' => $created,
        'last_run_at' => null,
        'next_run_at' => '2020-01-01 00:00:00',
    ], $overrides);
    $data['playlist_url'] = 'https://open.spotify.com/playlist/' . $data['playlist_id'];
    $id = $store->createRule($data);

    return $store->getRule($id);
}

final class FakeSpotifyService extends SpotifyService
{
    public ?array $results = [];
    public ?string $error = null;

    public function __construct()
    {
    }

    public function search(string $query, string $type = 'all', int $limit = 15): ?array
    {
        return $this->results;
    }

    public function getLastError(): ?string
    {
        return $this->error;
    }
}

final class FakeSpotifyUserService extends SpotifyUserService
{
    public array $response = ['success' => true, 'playlists' => []];

    public function __construct()
    {
    }

    public function listPlaylists(bool $force = false): array
    {
        return $this->response;
    }
}

final class FakePlaylistMetadataProvider implements PlaylistMetadataProviderInterface
{
    public array $playlists = [];
    public ?string $error = null;

    public function getPlaylist(string $playlistId): ?array
    {
        if (str_starts_with($playlistId, 'FAIL')) {
            throw new RuntimeException('Spotify user token unavailable.');
        }

        return $this->playlists[$playlistId] ?? null;
    }

    public function getLastError(): ?string
    {
        return $this->error;
    }
}

final class FakeMusicLibraryIndex implements MusicLibraryIndexInterface
{
    public array $index = ['ids' => [], 'keys' => [], 'loose' => [], 'generated_at' => 0, 'cached' => false];
    public int $calls = 0;

    public function getIndex(bool $refresh = false): array
    {
        $this->calls++;

        return $this->index;
    }
}

final class RecordingQueueManager extends QueueManager
{
    public array $items = [];

    public function __construct()
    {
    }

    public function enqueue(array $item, string $type = 'video'): void
    {
        $item['type'] = $type;
        $this->items[] = $item;
    }
}

final class AuthSmokeRouter implements RouterInterface
{
    private RequestContext $context;

    public function __construct()
    {
        $this->context = new RequestContext();
    }

    public function setContext(RequestContext $context): void
    {
        $this->context = $context;
    }

    public function getContext(): RequestContext
    {
        return $this->context;
    }

    public function getRouteCollection(): RouteCollection
    {
        return new RouteCollection();
    }

    public function match(string $pathinfo): array
    {
        throw new \Symfony\Component\Routing\Exception\ResourceNotFoundException($pathinfo);
    }

    public function generate(string $name, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        return $name === 'login' ? '/login' : '';
    }
}

final class AuthSmokeKernel implements HttpKernelInterface
{
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): \Symfony\Component\HttpFoundation\Response
    {
        return new \Symfony\Component\HttpFoundation\Response();
    }
}

echo "Playlist validation\n";
check(PlaylistSearchService::isValidPlaylistId(str_repeat('a', 22)), '22 character playlist ID is valid');
check(!PlaylistSearchService::isValidPlaylistId('short'), 'short playlist ID is rejected');
check(PlaylistSearchService::isValidPlaylistUrl('https://open.spotify.com/playlist/' . str_repeat('a', 22)), 'open.spotify.com playlist URL is valid');
check(!PlaylistSearchService::isValidPlaylistUrl('https://evil.example/playlist/' . str_repeat('a', 22)), 'foreign playlist URL is rejected');
check(!PlaylistSearchService::isValidPlaylistUrl('javascript:alert(1)'), 'non HTTP playlist URL is rejected');

echo "Playlist search\n";
$public = new FakeSpotifyService();
$public->results = [
    ['id' => str_repeat('p', 22), 'type' => 'playlist', 'name' => 'Public Mix', 'subtitle' => 'Par Alice', 'meta' => '12 titres', 'image' => 'https://img/1.jpg', 'url' => 'https://open.spotify.com/playlist/' . str_repeat('p', 22)],
    ['id' => str_repeat('t', 22), 'type' => 'track', 'name' => 'Not a playlist'],
    ['id' => 'short', 'type' => 'playlist', 'name' => 'Invalid'],
];
$user = new FakeSpotifyUserService();
$user->response = [
    'success' => true,
    'playlists' => [
        ['id' => str_repeat('m', 22), 'name' => 'Mes Favoris', 'owner' => 'Bob', 'track_count' => 5, 'public' => false, 'url' => 'https://open.spotify.com/playlist/' . str_repeat('m', 22), 'image' => null],
        ['id' => str_repeat('n', 22), 'name' => 'Public Account', 'owner' => 'Bob', 'track_count' => 8, 'public' => true, 'url' => 'https://open.spotify.com/playlist/' . str_repeat('n', 22), 'image' => null],
        ['id' => str_repeat('p', 22), 'name' => 'Public Mix', 'owner' => 'Alice', 'track_count' => 12, 'public' => true, 'url' => 'https://open.spotify.com/playlist/' . str_repeat('p', 22), 'image' => null],
    ],
];
$search = new PlaylistSearchService($public, $user);
$all = $search->search('', 'all', 20);
eq(3, $all['count'], 'all scope merges and deduplicates playlists');
eq('private', $all['results'][0]['visibility'] ?? null, 'private playlists keep the private badge');
eq('public', $all['results'][2]['visibility'] ?? null, 'public account playlists keep the public badge');
$privateOnly = $search->search('favoris', 'private', 20);
eq(1, $privateOnly['count'], 'private scope filters local playlist names');
eq(str_repeat('m', 22), $privateOnly['results'][0]['id'], 'private search returns the matching playlist');
$publicOnly = $search->search('mix', 'public', 20);
eq(1, $publicOnly['count'], 'public scope only uses client credentials search');
eq('Public Mix', $publicOnly['results'][0]['name'], 'public results are normalized');
eq('Alice', $publicOnly['results'][0]['owner'], 'public owner is normalized');
eq(12, $publicOnly['results'][0]['track_count'], 'public track count is normalized');
$limited = $search->search('', 'all', 1);
eq(1, $limited['count'], 'search limit is applied');
$user->response = ['success' => false, 'error' => 'Spotify credentials file not found'];
$broken = $search->search('x', 'private', 20);
check($broken['success'] === false, 'missing user token returns a clear error');
check(str_contains((string) $broken['message'], 'credentials'), 'missing user token message is explicit', $broken['message']);

echo "Scheduler\n";
$scheduler = new MusicAutomationScheduler();
$base = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
eq('2026-01-01 06:00:00', $scheduler->computeNextRun(['frequency' => '6h', 'created_at' => '2026-01-01 00:00:00', 'timezone' => 'UTC'], $base), '6h rule waits six hours from creation');
eq('2026-01-01 16:00:00', $scheduler->computeNextRun(['frequency' => '6h', 'last_run_at' => '2026-01-01 10:00:00', 'timezone' => 'UTC'], $base), '6h rule uses the last run');
eq('2026-01-01 12:00:00', $scheduler->computeNextRun(['frequency' => '12h', 'last_run_at' => '2026-01-01 00:00:00', 'timezone' => 'UTC'], $base), '12h rule uses the last run');
eq('2026-01-01 09:00:00', $scheduler->computeNextRun(['frequency' => 'daily', 'run_time' => '09:00', 'timezone' => 'UTC'], new DateTimeImmutable('2026-01-01 08:00:00', new DateTimeZone('UTC'))), 'daily rule runs later the same day');
eq('2026-01-02 09:00:00', $scheduler->computeNextRun(['frequency' => 'daily', 'run_time' => '09:00', 'timezone' => 'UTC'], new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC'))), 'daily rule rolls to tomorrow');
eq('2026-01-05 09:00:00', $scheduler->computeNextRun(['frequency' => 'weekly', 'run_time' => '09:00', 'weekday' => 1, 'timezone' => 'UTC'], new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC'))), 'weekly rule finds the next Monday');
eq('2026-01-01 08:00:00', $scheduler->computeNextRun(['frequency' => 'daily', 'run_time' => '09:00', 'timezone' => 'Europe/Paris'], new DateTimeImmutable('2026-01-01 07:30:00', new DateTimeZone('UTC'))), 'timezone is applied to daily rules');
eq('2026-03-29 07:00:00', $scheduler->computeNextRun(['frequency' => 'daily', 'run_time' => '09:00', 'timezone' => 'Europe/Paris'], new DateTimeImmutable('2026-03-28 09:00:00', new DateTimeZone('UTC'))), 'DST transition keeps the local run time');
check($scheduler->isDue(['enabled' => true, 'next_run_at' => '2026-01-01 00:00:00'], new DateTimeImmutable('2026-01-01 01:00:00', new DateTimeZone('UTC'))), 'due rule is detected');
check(!$scheduler->isDue(['enabled' => false, 'next_run_at' => '2026-01-01 00:00:00'], new DateTimeImmutable('2026-01-01 01:00:00', new DateTimeZone('UTC'))), 'paused rule is never due');
check(!$scheduler->isDue(['enabled' => true, 'next_run_at' => '2026-01-02 00:00:00'], new DateTimeImmutable('2026-01-01 01:00:00', new DateTimeZone('UTC'))), 'future rule is not due');

echo "Track matcher\n";
$matcher = new MusicTrackMatcher();
eq('a, b, c', $matcher->normalize('A / B ; C'), 'artist separators are normalized');
eq('cafe', $matcher->normalize('Café'), 'accents are normalized');
$trackA = ['id' => str_repeat('a', 22), 'url' => 'https://open.spotify.com/track/' . str_repeat('a', 22), 'artist' => 'Artist', 'album' => 'Album', 'song_name' => 'Song'];
$trackB = ['id' => str_repeat('b', 22), 'url' => 'https://open.spotify.com/track/' . str_repeat('b', 22), 'artist' => 'Artist', 'album' => 'Other Album', 'song_name' => 'Song'];
$deduped = $matcher->deduplicate([$trackA, $trackA, $trackB]);
eq(2, count($deduped), 'internal playlist duplicates are removed by track ID');
$archive = $matcher->parseArchiveLines([
    'https://open.spotify.com/track/' . str_repeat('a', 22),
    'Artist - Archived Song',
]);
check($matcher->isKnown($trackA, $archive, ['ids' => [], 'keys' => [], 'loose' => []], ['ids' => [], 'keys' => [], 'loose' => []]), 'archive track ID is treated as present');
check($matcher->isKnown(['artist' => 'Artist', 'album' => '', 'song_name' => 'Archived Song'], $archive, ['ids' => [], 'keys' => [], 'loose' => []], ['ids' => [], 'keys' => [], 'loose' => []]), 'archive artist/title line is treated as present');
$library = $matcher->indexTracks([['artist' => 'Artist', 'album' => 'Album', 'song_name' => 'Song']]);
check($matcher->isKnown($trackA, ['ids' => [], 'keys' => [], 'loose' => []], $library, ['ids' => [], 'keys' => [], 'loose' => []]), 'library triple match is treated as present');
check(!$matcher->isKnown($trackB, ['ids' => [], 'keys' => [], 'loose' => []], $library, ['ids' => [], 'keys' => [], 'loose' => []]), 'same artist/title on another album is not a false positive');
$history = $matcher->historyIndex([['expected_tracks' => [['artist' => 'Artist', 'album' => 'Album', 'song_name' => 'Song']]]]);
check($matcher->isKnown($trackA, ['ids' => [], 'keys' => [], 'loose' => []], ['ids' => [], 'keys' => [], 'loose' => []], $history), 'history track is treated as present');

$pythonAvailable = trim((string) @shell_exec('command -v python3 2>/dev/null')) !== '';
if ($pythonAvailable) {
    echo "Python playlist mapping\n";
    $script = 'import sys,json; sys.path.insert(0,"cli"); import spotify_user; info={"name":"Mix","snapshot_id":"snap-123","external_urls":{"spotify":"https://open.spotify.com/playlist/' . str_repeat('p', 22) . '"}}; track={"id":"' . str_repeat('t', 22) . '","external_urls":{"spotify":"https://open.spotify.com/track/' . str_repeat('t', 22) . '"},"duration_ms":1234,"artists":[{"name":"A"}],"album":{"name":"Alb"},"name":"Song"}; print(json.dumps(spotify_user.map_playlist_metadata("' . str_repeat('p', 22) . '", info, [spotify_user.map_playlist_track(track, "A")], "A")))';
    $process = new Process(['python3', '-c', $script], dirname(__DIR__));
    $process->setTimeout(30);
    $process->run();
    $mapped = json_decode(trim($process->getOutput()), true);
    check($process->isSuccessful() && is_array($mapped), 'spotify_user mapping runs without network or librespot', $process->getErrorOutput());
    if (is_array($mapped)) {
        eq('snap-123', $mapped['playlist']['snapshot_id'] ?? null, 'Python mapping exposes snapshot_id');
        eq(str_repeat('t', 22), $mapped['playlist']['tracks'][0]['id'] ?? null, 'Python mapping exposes track id');
        eq('https://open.spotify.com/track/' . str_repeat('t', 22), $mapped['playlist']['tracks'][0]['url'] ?? null, 'Python mapping exposes track url');
        eq(1234, $mapped['playlist']['tracks'][0]['duration_ms'] ?? null, 'Python mapping exposes duration_ms');
    }
}

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "Store\n";
    $project = tempDir('ma-store');
    $store = new MusicAutomationStore($project);
    $rule = makeRule($store, ['playlist_id' => str_repeat('r', 22), 'name' => 'Stored Rule']);
    check((int) $rule['id'] > 0, 'rule is created');
    eq('Stored Rule', $rule['name'], 'rule name is stored');
    eq(true, $rule['enabled'], 'rule is active by default');
    $duplicated = false;
    try {
        makeRule($store, ['playlist_id' => str_repeat('r', 22)]);
    } catch (InvalidArgumentException $e) {
        $duplicated = true;
    }
    check($duplicated, 'playlist_id is unique');
    $store->updateRule((int) $rule['id'], ['enabled' => false, 'name' => 'Paused Rule']);
    $updated = $store->getRule((int) $rule['id']);
    eq(false, $updated['enabled'], 'rule can be paused');
    eq('Paused Rule', $updated['name'], 'rule can be updated');
    eq(0, count($store->getRules(false)), 'enabled filter excludes paused rules');
    $runId = $store->startRun((int) $rule['id'], 'manual');
    $store->finishRun($runId, ['found' => 3, 'new' => 1, 'skipped' => 2, 'queued' => 1], 'success');
    $runs = $store->getRuns(10, (int) $rule['id']);
    eq(1, count($runs), 'run is recorded');
    eq(1, $runs[0]['queued'], 'run counters are recorded');
    eq(1, $runs[0]['summary']['new'], 'run summary JSON is decoded');
    $store->getPdo()->exec("UPDATE runs SET started_at = '2020-01-01 00:00:00', status = 'running' WHERE id = " . (int) $runId);
    eq(1, $store->markStaleRuns(7200), 'stale runs are marked as error');
    eq('error', $store->getRuns(1)[0]['status'], 'stale run status is error');
    check(is_file($store->getDbPath()), 'SQLite database is created');
    eq('0600', substr(sprintf('%o', fileperms($store->getDbPath())), -4), 'SQLite database permissions are 0600');
    $store->deleteRule((int) $rule['id']);
    check($store->getRule((int) $rule['id']) === null, 'rule can be deleted');
    removeTree($project);

    echo "Runner\n";
    $project = tempDir('ma-runner');
    $store = new MusicAutomationStore($project);
    $archiveFile = $project . '/archive.txt';
    file_put_contents($archiveFile, "https://open.spotify.com/track/" . str_repeat('z', 22) . "\n");
    $config = [
        'music_root_path' => $project . '/music',
        'music_library_path' => $project . '/library',
        'music_archive' => $archiveFile,
        'music_automation_max_per_run' => 50,
        'music_automation_queue_guard' => 20,
    ];
    mkdir($config['music_root_path'], 0777, true);
    mkdir($config['music_library_path'], 0777, true);
    $storage = storageAt($project . '/var/storage', $config);
    $storage->set('history', []);
    $storage->set('server_queue', []);
    $rule = makeRule($store, ['playlist_id' => str_repeat('q', 22), 'name' => 'Runner Playlist', 'next_run_at' => '2020-01-01 00:00:00']);

    $provider = new FakePlaylistMetadataProvider();
    $tracks = [
        ['id' => str_repeat('z', 22), 'url' => 'https://open.spotify.com/track/' . str_repeat('z', 22), 'artist' => 'Archived', 'album' => 'A', 'song_name' => 'Old', 'duration_ms' => 1000],
        ['id' => str_repeat('y', 22), 'url' => 'https://open.spotify.com/track/' . str_repeat('y', 22), 'artist' => 'Library', 'album' => 'L', 'song_name' => 'Known', 'duration_ms' => 1001],
        ['id' => str_repeat('x', 22), 'url' => 'https://open.spotify.com/track/' . str_repeat('x', 22), 'artist' => 'New Artist', 'album' => 'New Album', 'song_name' => 'New Song', 'duration_ms' => 1002],
    ];
    $provider->playlists[str_repeat('q', 22)] = ['name' => 'Runner Playlist', 'snapshot_id' => 'snap-run-1', 'tracks' => $tracks];
    $library = new FakeMusicLibraryIndex();
    $library->index = $matcher->indexTracks([['artist' => 'Library', 'album' => 'L', 'song_name' => 'Known']]);
    $recordingQueue = new RecordingQueueManager();
    $queueService = new MusicQueueService($recordingQueue, $storage);
    $runner = new MusicAutomationRunner($store, $scheduler, $provider, $matcher, $library, $queueService, $storage, new ServerNotificationService());

    $dry = $runner->runRule($rule, true, false, 'manual');
    eq('dry_run', $dry['status'], 'dry run status is reported');
    eq(3, $dry['found'], 'dry run counts found tracks');
    eq(1, $dry['new'], 'dry run counts new tracks');
    eq(2, $dry['skipped'], 'dry run counts skipped tracks');
    eq(1, $dry['would_queue'], 'dry run reports would queue');
    eq(0, count($recordingQueue->items), 'dry run enqueues nothing');
    eq(1, count($dry['preview']), 'dry run preview is limited to new tracks');

    $done = $runner->runRule($store->getRule((int) $rule['id']), false, false, 'manual');
    eq('success', $done['status'], 'real run succeeds');
    eq(1, $done['queued'], 'real run queues the new track');
    eq(1, count($recordingQueue->items), 'one queue item is created');
    $item = $recordingQueue->items[0];
    eq('https://open.spotify.com/track/' . str_repeat('x', 22), $item['url'], 'queue url is the Spotify track URL');
    eq('Spotify: New Artist - New Song', $item['filename'], 'queue filename follows the music contract');
    eq($config['music_root_path'], $item['path'], 'queue path uses music_root_path');
    eq('music', $item['type'], 'queue type is music');
    eq([['artist' => 'New Artist', 'album' => 'New Album', 'song_name' => 'New Song']], $item['expected_tracks'], 'expected_tracks contains exactly one track');
    check(!empty($item['download_id']) && ctype_xdigit((string) $item['download_id']), 'download_id is hexadecimal');
    $refreshed = $store->getRule((int) $rule['id']);
    eq('snap-run-1', $refreshed['last_snapshot_id'], 'snapshot_id is stored');
    eq('success', $refreshed['last_status'], 'last status is stored');
    check((string) $refreshed['next_run_at'] > '2026-01-01', 'next run is recomputed', $refreshed['next_run_at']);
    $notifications = $storage->get('server_notifications', []);
    eq(1, count($notifications), 'a summary notification is emitted');
    $historyFile = $storage->getStorageDir() . '/logs/history_' . $item['download_id'] . '.log';
    check(is_file($historyFile) && str_contains((string) file_get_contents($historyFile), 'Runner Playlist'), 'enqueue history log is written');

    $skip = $runner->runRule($refreshed, false, false, 'schedule');
    eq('skipped', $skip['status'], 'unchanged snapshot skips the run');
    eq(0, $skip['queued'], 'snapshot skip queues nothing');
    $forced = $runner->runRule($refreshed, true, true, 'manual');
    eq('dry_run', $forced['status'], 'forced dry run ignores an unchanged snapshot');

    $store->updateRule((int) $rule['id'], ['last_snapshot_id' => null]);
    $storage->set('server_queue', [['url' => 'https://open.spotify.com/track/' . str_repeat('x', 22)]]);
    $guarded = $runner->runRule($store->getRule((int) $rule['id']), false, true, 'manual');
    eq(0, $guarded['queued'], 'tracks already in server_queue are not queued again');

    $capRule = makeRule($store, ['playlist_id' => str_repeat('c', 22), 'name' => 'Cap Playlist', 'next_run_at' => '2020-01-01 00:00:00']);
    $provider->playlists[str_repeat('c', 22)] = ['name' => 'Cap Playlist', 'snapshot_id' => 'cap-1', 'tracks' => [
        ['id' => str_repeat('1', 22), 'artist' => 'A', 'album' => 'X', 'song_name' => 'One'],
        ['id' => str_repeat('2', 22), 'artist' => 'A', 'album' => 'X', 'song_name' => 'Two'],
        ['id' => str_repeat('3', 22), 'artist' => 'A', 'album' => 'X', 'song_name' => 'Three'],
    ]];
    $storage->set('server_queue', []);
    $storage->set('config', array_merge($config, ['music_automation_max_per_run' => 2]));
    $recordingQueue->items = [];
    $capped = $runner->runRule($capRule, false, true, 'manual');
    eq(2, $capped['queued'], 'per-run cap limits the enqueue batch');

    $storage->set('config', array_merge($config, ['music_automation_queue_guard' => 1]));
    $storage->set('server_queue', [['url' => 'https://open.spotify.com/track/' . str_repeat('9', 22)]]);
    $recordingQueue->items = [];
    $guardRule = makeRule($store, ['playlist_id' => str_repeat('g', 22), 'name' => 'Guard Playlist', 'next_run_at' => '2020-01-01 00:00:00']);
    $provider->playlists[str_repeat('g', 22)] = ['name' => 'Guard Playlist', 'snapshot_id' => 'guard-1', 'tracks' => [
        ['id' => str_repeat('4', 22), 'artist' => 'B', 'album' => 'Y', 'song_name' => 'Guarded'],
    ]];
    $guardedRun = $runner->runRule($guardRule, false, true, 'manual');
    eq(0, $guardedRun['queued'], 'queue guard prevents enqueueing');

    echo "Command\n";
    $storage->set('config', $config);
    $storage->set('server_queue', []);
    $command = new MusicAutomationsCommand($store, $runner);
    $application = new Application();
    $application->add($command);
    $tester = new CommandTester($command);
    $tester->execute(['--once' => true, '--dry-run' => true, '--rule' => (string) $rule['id']]);
    eq(0, $tester->getStatusCode(), 'command --once --dry-run --rule exits successfully');
    check(str_contains($tester->getDisplay(), 'Runner Playlist'), 'command prints the selected rule');
    $beforeCommandRuns = count($store->getRuns(200));
    $validDue = makeRule($store, ['playlist_id' => str_repeat('v', 22), 'name' => 'Valid Due Playlist', 'next_run_at' => '2020-01-01 00:00:00']);
    $provider->playlists[str_repeat('v', 22)] = ['name' => 'Valid Due Playlist', 'snapshot_id' => 'valid-1', 'tracks' => []];
    makeRule($store, ['playlist_id' => 'FAIL' . substr(str_repeat('f', 20), 0, 18), 'name' => 'Failing Playlist', 'next_run_at' => '2020-01-01 00:00:00']);
    $tester->execute(['--once' => true, '--dry-run' => true, '--force' => true]);
    eq(1, $tester->getStatusCode(), 'command reports a failing rule with a non-zero exit code');
    $runRows = $store->getRuns(200);
    $statuses = array_map(static fn (array $row): string => (string) $row['status'], $runRows);
    check(in_array('error', $statuses, true), 'failing rule is recorded as error');
    check(count($runRows) >= $beforeCommandRuns + 2, 'other rules still run after a failure', count($runRows));
    check(in_array('dry_run', $statuses, true), 'other rules are processed in the same cycle');

    removeTree($project);
} else {
    echo "Store and runner\n  skip (pdo_sqlite not available)\n";
}

echo "Routes\n";
$routes = routerInventory();
check($routes !== [], 'debug:router inventory is readable');
$expectedRoutes = [
    'music_automations' => ['/music/automations', 'GET'],
    'music_automations_search' => ['/music/automations/search', 'GET'],
    'music_automations_save' => ['/music/automations/save', 'POST'],
    'music_automations_delete' => ['/music/automations/delete', 'POST'],
    'music_automations_run' => ['/music/automations/run', 'POST'],
    'music_automations_status' => ['/music/automations/status', 'GET'],
];
foreach ($expectedRoutes as $name => [$path, $verb]) {
    check(isset($routes[$name]), 'route ' . $name . ' exists', array_keys($routes));
    if (isset($routes[$name])) {
        eq($path, $routes[$name]['path'] ?? null, 'route ' . $name . ' path');
        check(methodHas((string) ($routes[$name]['method'] ?? ''), $verb), 'route ' . $name . ' accepts ' . $verb);
    }
}

echo "Auth and CSRF\n";
$authProject = tempDir('ma-auth');
$authStorage = storageAt($authProject, ['admin_user' => 'admin', 'admin_password' => 'admin']);
$session = new Session(new MockArraySessionStorage());
$session->start();
$request = Request::create('/music/automations', 'GET');
$request->attributes->set('_route', 'music_automations');
$request->setSession($session);
$stack = new RequestStack();
$stack->push($request);
$auth = new AuthSubscriber(new AuthService($authStorage, $stack), new AuthSmokeRouter());
$event = new RequestEvent(new AuthSmokeKernel(), $request, HttpKernelInterface::MAIN_REQUEST);
$auth->onKernelRequest($event);
check($event->getResponse() !== null, 'unauthenticated music automations request is redirected');
$session->set('authenticated', true);
$session->set('username', 'admin');
$session->set('last_activity', time());
$event = new RequestEvent(new AuthSmokeKernel(), $request, HttpKernelInterface::MAIN_REQUEST);
$auth->onKernelRequest($event);
check($event->getResponse() === null, 'authenticated music automations request is allowed');

$csrf = new CsrfTokenService();
$csrfSession = new Session(new MockArraySessionStorage());
$csrfSession->start();
$tokenRequest = Request::create('/music/automations', 'GET');
$tokenRequest->setSession($csrfSession);
$token = $csrf->getToken($tokenRequest);
$subscriber = new CsrfSubscriber($csrf);
foreach (['/music/automations/save', '/music/automations/delete', '/music/automations/run'] as $path) {
    $missing = Request::create($path, 'POST');
    $missing->setSession($csrfSession);
    $response = $subscriber->validateRequest($missing);
    check($response !== null && $response->getStatusCode() === 403, 'POST ' . $path . ' without CSRF is rejected');
    $valid = Request::create($path, 'POST', ['_token' => $token]);
    $valid->setSession($csrfSession);
    check($subscriber->validateRequest($valid) === null, 'POST ' . $path . ' with CSRF is accepted');
}
removeTree($authProject);

echo "Navigation\n";
$navProject = tempDir('ma-nav');
$navStorage = storageAt($navProject, ['api_key' => 'key', 'default_path' => '/tmp']);
$navigation = new NavigationService($navStorage);
$groups = $navigation->getNavigation('music_automations');
$item = null;
foreach ($groups as $group) {
    foreach ($group['items'] as $candidate) {
        if ($candidate['key'] === 'music_automations') {
            $item = $candidate;
        }
    }
}
check($item !== null, 'music automations navigation item exists');
if ($item !== null) {
    check($item['enabled'], 'music automations navigation item is enabled');
    eq('music_automations', $item['route'], 'music automations navigation item links to its route');
    check($item['active'], 'music automations navigation item is active');
}
removeTree($navProject);

echo "\n$passes passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
