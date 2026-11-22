<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Controller\SearchController;
use App\EventSubscriber\AuthSubscriber;
use App\EventSubscriber\CsrfSubscriber;
use App\Service\CsrfTokenService;
use App\Service\JsonStorage;
use App\Service\NavigationService;
use App\Service\Search\GlobalSearchService;
use App\Service\Search\Provider\ActionSearchProvider;
use App\Service\Search\Provider\HistorySearchProvider;
use App\Service\Search\Provider\MediaSearchProvider;
use App\Service\Search\Provider\MusicSearchProvider;
use App\Service\Search\Provider\TorrentSearchProvider;
use App\Service\Search\SearchProviderInterface;
use App\Service\SpotifyService;
use App\Service\TmdbService;
use App\Service\TorrentDbService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

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

function projectDir(string $prefix): string
{
    $dir = tempDir($prefix);
    mkdir($dir . '/var/storage', 0700, true);
    mkdir($dir . '/var/db', 0700, true);

    return $dir;
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

function routeMethodIncludesGet(string $method): bool
{
    $method = strtoupper($method);

    return $method === 'ANY' || preg_match('/\b(GET|HEAD)\b/', $method) === 1;
}

final class SearchSmokeRouter implements UrlGeneratorInterface
{
    public array $generated = [];

    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        $this->generated[] = ['name' => $name, 'parameters' => $parameters];

        $map = [
            'history' => '/history',
            'history_log' => '/history-log/' . ($parameters['downloadId'] ?? ''),
            'torrent' => '/torrent' . (isset($parameters['q']) ? '?' . http_build_query(['q' => $parameters['q']]) : ''),
            'media_details' => '/media/details/' . ($parameters['type'] ?? 'movie') . '/' . ($parameters['id'] ?? '0'),
            'media_index' => '/media' . (isset($parameters['tmdb']) ? '?tmdb=' . $parameters['tmdb'] : ''),
            'maintenance' => '/settings/maintenance',
            'video_integrity' => '/video/integrity',
            'statistics' => '/statistics',
            'automation' => '/automation',
            'config' => '/config',
        ];

        return $map[$name] ?? '/' . $name;
    }

    public function setContext(RequestContext $context): void
    {
    }

    public function getContext(): RequestContext
    {
        return new RequestContext();
    }
}

final class SearchSmokeProvider implements SearchProviderInterface
{
    public int $calls = 0;
    public array $queries = [];
    public array $limits = [];

    public function __construct(
        private string $keyName,
        private string $labelName,
        private string $iconName,
        private array $results = [],
        private ?Throwable $error = null
    ) {
    }

    public function key(): string
    {
        return $this->keyName;
    }

    public function label(): string
    {
        return $this->labelName;
    }

    public function icon(): string
    {
        return $this->iconName;
    }

    public function search(string $query, int $limit): array
    {
        $this->calls++;
        $this->queries[] = $query;
        $this->limits[] = $limit;

        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->results;
    }
}

final class SearchSmokeTmdb extends TmdbService
{
    public bool $configured = true;
    public int $calls = 0;
    public array $results = [];
    public ?Throwable $error = null;

    public function __construct()
    {
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function searchQuick(string $query, int $limit = 8, int $timeout = 4): array
    {
        $this->calls++;
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->results;
    }
}

final class SearchSmokeSpotify extends SpotifyService
{
    public bool $configured = true;
    public int $calls = 0;
    public ?array $results = [];
    public ?Throwable $error = null;

    public function __construct()
    {
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function searchQuick(string $query, int $limit = 8, int $timeout = 4): ?array
    {
        $this->calls++;
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->results;
    }
}

function searchResult(string $id, string $title, string $url = '/target'): array
{
    return [
        'id' => $id,
        'title' => $title,
        'subtitle' => 'Subtitle ' . $id,
        'meta' => 'Meta',
        'icon' => 'action',
        'url' => $url,
        'source' => 'ignored-by-service',
    ];
}

function makeProviders(): array
{
    $results = [
        'actions' => [searchResult('action-open', 'Open statistics', '/statistics')],
        'torrents' => [searchResult('torrent-one', 'Dune Part Two', '/torrent')],
        'media' => [searchResult('media-one', 'Dune', '/media/details/movie/1')],
        'music' => [searchResult('music-one', 'Dune OST', 'https://open.spotify.com/album/abc')],
        'history' => [searchResult('history-one', 'Dune.mkv', '/history-log/a1')],
    ];
    $labels = [
        'actions' => ['Quick actions', 'action'],
        'torrents' => ['Local torrent database', 'torrent'],
        'media' => ['Discover', 'video'],
        'music' => ['Music', 'music'],
        'history' => ['History', 'history'],
    ];

    $providers = [];
    foreach ($results as $key => $items) {
        $providers[] = new SearchSmokeProvider($key, $labels[$key][0], $labels[$key][1], $items);
    }

    return $providers;
}

$project = dirname(__DIR__);
$sandbox = tempDir('global-search-smoke');

try {
    echo "Aggregation and group order\n";
    $providers = makeProviders();
    $service = new GlobalSearchService($providers);
    $payload = $service->search('dune', 'all', 8);
    eq(true, $payload['success'], 'success flag is true');
    eq('dune', $payload['query'], 'query is echoed back');
    eq(false, $payload['partial'], 'complete aggregation is not partial');
    eq([], $payload['errors'], 'no errors for healthy providers');
    eq(['actions', 'torrents', 'media', 'music', 'history'], array_keys($payload['groups']), 'groups keep the stable order');
    eq(1, count($payload['groups']['actions']), 'actions are aggregated');
    eq(1, count($payload['groups']['torrents']), 'torrents are aggregated');
    eq(1, count($payload['groups']['media']), 'media are aggregated');
    eq(1, count($payload['groups']['music']), 'music is aggregated');
    eq(1, count($payload['groups']['history']), 'history is aggregated');
    eq('actions', $payload['groups']['actions'][0]['source'], 'source comes from the provider key, not provider data');
    eq(['id', 'title', 'subtitle', 'meta', 'icon', 'url', 'source'], array_keys($payload['groups']['actions'][0]), 'result shape is stable');

    echo "Scopes\n";
    $providers = makeProviders();
    $service = new GlobalSearchService($providers);
    $local = $service->search('dune', 'local', 8);
    check($local['groups']['actions'] !== [] && $local['groups']['torrents'] !== [] && $local['groups']['history'] !== [], 'local scope returns local groups');
    eq([], $local['groups']['media'], 'local scope skips Discover');
    eq([], $local['groups']['music'], 'local scope skips Music');
    eq(0, $providers[2]->calls, 'local scope does not call the media provider');
    eq(0, $providers[3]->calls, 'local scope does not call the music provider');

    $providers = makeProviders();
    $service = new GlobalSearchService($providers);
    $remote = $service->search('dune', 'remote', 8);
    eq([], $remote['groups']['actions'], 'remote scope skips actions');
    eq([], $remote['groups']['torrents'], 'remote scope skips torrents');
    eq([], $remote['groups']['history'], 'remote scope skips history');
    eq(1, count($remote['groups']['media']), 'remote scope returns Discover');
    eq(1, count($remote['groups']['music']), 'remote scope returns Music');
    eq(0, $providers[0]->calls, 'remote scope does not call the actions provider');

    $providers = makeProviders();
    $service = new GlobalSearchService($providers);
    $invalid = $service->search('dune', 'not-a-scope', 8);
    eq(1, count($invalid['groups']['actions']), 'invalid scope falls back to all: actions');
    eq(1, count($invalid['groups']['media']), 'invalid scope falls back to all: media');
    eq(5, array_sum(array_map('count', $invalid['groups'])), 'invalid scope queries every group');

    $providers = makeProviders();
    $service = new GlobalSearchService($providers);
    $emptyScope = $service->search('dune', '', 8);
    eq(5, array_sum(array_map('count', $emptyScope['groups'])), 'empty scope falls back to all');

    echo "Query normalization, limits and empty query\n";
    $providers = makeProviders();
    $service = new GlobalSearchService($providers);
    $trimmed = $service->search('   dune   ', 'all', 8);
    eq('dune', $trimmed['query'], 'query is trimmed');
    eq('dune', $providers[0]->queries[0] ?? null, 'providers receive the trimmed query');

    $long = str_repeat('a', 140);
    $providers = makeProviders();
    $service = new GlobalSearchService($providers);
    $truncated = $service->search($long, 'all', 8);
    eq(100, mb_strlen($truncated['query'], 'UTF-8'), 'query is truncated to 100 characters');
    eq(100, mb_strlen($providers[1]->queries[0] ?? '', 'UTF-8'), 'providers receive the truncated query');

    $providers = makeProviders();
    $service = new GlobalSearchService($providers);
    $service->search('dune', 'all', 0);
    eq(1, $providers[0]->limits[0] ?? null, 'limit lower bound is 1');

    $providers = makeProviders();
    $service = new GlobalSearchService($providers);
    $service->search('dune', 'all', 999);
    eq(20, $providers[0]->limits[0] ?? null, 'limit upper bound is 20');

    $providers = makeProviders();
    $service = new GlobalSearchService($providers);
    $service->search('dune', 'all', null);
    eq(8, $providers[0]->limits[0] ?? null, 'default limit is 8');

    $providers = makeProviders();
    $service = new GlobalSearchService($providers);
    $emptyQuery = $service->search('   ', 'all', 8);
    eq('', $emptyQuery['query'], 'empty query is normalized to an empty string');
    eq(0, array_sum(array_map(static fn (SearchSmokeProvider $provider): int => $provider->calls, $providers)), 'empty query does not call providers');
    eq(['actions', 'torrents', 'media', 'music', 'history'], array_keys($emptyQuery['groups']), 'empty query keeps stable groups');
    eq(0, array_sum(array_map('count', $emptyQuery['groups'])), 'empty query returns empty groups');

    echo "Limits and deduplication\n";
    $many = [];
    for ($i = 0; $i < 6; $i++) {
        $many[] = searchResult('many-' . $i, 'Many ' . $i);
    }
    $limited = new GlobalSearchService([
        new SearchSmokeProvider('actions', 'Quick actions', 'action', $many),
        new SearchSmokeProvider('torrents', 'Torrents', 'torrent', []),
        new SearchSmokeProvider('media', 'Discover', 'video', []),
        new SearchSmokeProvider('music', 'Music', 'music', []),
        new SearchSmokeProvider('history', 'History', 'history', []),
    ]);
    $limitedPayload = $limited->search('many', 'actions', 3);
    eq(3, count($limitedPayload['groups']['actions']), 'results are capped by the limit');

    $duplicates = new GlobalSearchService([
        new SearchSmokeProvider('actions', 'Quick actions', 'action', [
            searchResult('dup', 'First'),
            searchResult('dup', 'First duplicate'),
            searchResult('other', 'Second'),
        ]),
        new SearchSmokeProvider('torrents', 'Torrents', 'torrent', []),
        new SearchSmokeProvider('media', 'Discover', 'video', []),
        new SearchSmokeProvider('music', 'Music', 'music', []),
        new SearchSmokeProvider('history', 'History', 'history', []),
    ]);
    $deduped = $duplicates->search('dup', 'actions', 10);
    eq(2, count($deduped['groups']['actions']), 'duplicate ids are removed');
    eq('First', $deduped['groups']['actions'][0]['title'], 'first duplicate is kept');

    echo "Provider failures and URL safety\n";
    $failing = new GlobalSearchService([
        new SearchSmokeProvider('actions', 'Quick actions', 'action', [searchResult('action-ok', 'Healthy action')]),
        new SearchSmokeProvider('torrents', 'Torrents', 'torrent', [searchResult('torrent-ok', 'Healthy torrent')]),
        new SearchSmokeProvider('media', 'Discover', 'video', [], new RuntimeException('SECRET_TOKEN must not leak')),
        new SearchSmokeProvider('music', 'Music', 'music', [searchResult('music-ok', 'Healthy music')]),
        new SearchSmokeProvider('history', 'History', 'history', [searchResult('history-ok', 'Healthy history')]),
    ]);
    $partial = $failing->search('healthy', 'all', 8);
    eq(true, $partial['partial'], 'provider failure marks the response as partial');
    eq(1, count($partial['errors']), 'provider failure is reported');
    eq('media', $partial['errors'][0]['provider'] ?? null, 'failing provider is identified');
    eq(1, count($partial['groups']['actions']), 'other providers keep their results');
    eq(1, count($partial['groups']['music']), 'remote providers keep their results');
    eq([], $partial['groups']['media'], 'failing provider returns an empty group');
    check(!str_contains(json_encode($partial), 'SECRET_TOKEN'), 'provider exception details are not leaked');

    $unsafe = new GlobalSearchService([
        new SearchSmokeProvider('actions', 'Quick actions', 'action', [
            searchResult('js', 'Javascript URL', 'javascript:alert(1)'),
            searchResult('protocol', 'Protocol relative', '//evil.example/path'),
            searchResult('https', 'External unsafe', 'https://evil.example/path'),
            searchResult('spotify', 'Spotify safe', 'https://open.spotify.com/track/ok'),
            searchResult('relative', 'Relative safe', '/history'),
        ]),
        new SearchSmokeProvider('torrents', 'Torrents', 'torrent', []),
        new SearchSmokeProvider('media', 'Discover', 'video', []),
        new SearchSmokeProvider('music', 'Music', 'music', []),
        new SearchSmokeProvider('history', 'History', 'history', []),
    ]);
    $safe = $unsafe->search('url', 'actions', 10)['groups']['actions'];
    $urls = array_column($safe, 'url', 'id');
    eq(null, $urls['js'], 'javascript URLs are rejected');
    eq(null, $urls['protocol'], 'protocol-relative URLs are rejected');
    eq(null, $urls['https'], 'untrusted external URLs are rejected');
    eq('https://open.spotify.com/track/ok', $urls['spotify'], 'Spotify URLs are allowed');
    eq('/history', $urls['relative'], 'relative app URLs are allowed');

    echo "HistorySearchProvider\n";
    $historyDir = $sandbox . '/history-storage';
    mkdir($historyDir, 0700, true);
    $historyStorage = storageAt($historyDir);
    file_put_contents($historyDir . '/history.json', json_encode([
        [
            'download_id' => 'a1',
            'filename' => 'Dune.Part.Two.2160p.mkv',
            'type' => 'video',
            'status' => 'success',
            'date' => '2026-01-01 10:00:00',
            'path' => '/nas/Movies/Dune',
            'source_url' => 'https://secret.example/download?token=LEAK',
            'file_count' => 1,
            'size' => 2048,
        ],
        [
            'download_id' => 'b2',
            'filename' => 'Nujabes - Luv(sic).mp3',
            'type' => 'audio',
            'status' => 'warning',
            'date' => '2026-01-02 11:00:00',
            'path' => '/music/Nujabes',
            'source_url' => 'https://secret.example/private',
            'file_count' => 3,
            'size' => 1048576,
        ],
        [
            'download_id' => 'c3',
            'filename' => 'The Bear S04E01.mkv',
            'type' => 'video',
            'status' => 'error',
            'date' => '2026-01-03 12:00:00',
            'path' => '/nas/TV/The Bear',
            'source_url' => null,
            'file_count' => 0,
            'size' => null,
        ],
    ]));
    $historyRouter = new SearchSmokeRouter();
    $historyProvider = new HistorySearchProvider($historyStorage, $historyRouter);
    $historyResults = $historyProvider->search('dune', 10);
    eq(1, count($historyResults), 'history search matches filename case-insensitively');
    eq('Dune.Part.Two.2160p.mkv', $historyResults[0]['title'] ?? null, 'history title is the filename');
    eq('/history-log/a1', $historyResults[0]['url'] ?? null, 'history URL uses the history log route');
    check(str_contains((string) ($historyResults[0]['subtitle'] ?? ''), 'Success'), 'history subtitle exposes the status');
    check(!str_contains(json_encode($historyResults), 'LEAK'), 'history source_url is never returned');
    check(!str_contains(json_encode($historyResults), 'secret.example'), 'history source URLs are not leaked');

    $statusResults = $historyProvider->search('warning', 10);
    eq('Nujabes - Luv(sic).mp3', $statusResults[0]['title'] ?? null, 'history search matches status');

    $typeResults = $historyProvider->search('video', 10);
    eq(2, count($typeResults), 'history search matches type');
    eq('The Bear S04E01.mkv', $typeResults[0]['title'] ?? null, 'history results are newest first');

    $limitedHistory = $historyProvider->search('video', 1);
    eq(1, count($limitedHistory), 'history search honours the limit');

    $noHistory = new HistorySearchProvider(storageAt($sandbox . '/empty-history'), new SearchSmokeRouter());
    eq([], $noHistory->search('dune', 10), 'history search is empty without fixtures');

    echo "TorrentSearchProvider\n";
    $torrentProject = projectDir($sandbox . '/torrent-project');
    $torrentDb = $torrentProject . '/var/db/torrents.db';
    $pdo = new PDO('sqlite:' . $torrentDb);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE torrents (
        name TEXT,
        hash_info TEXT,
        size INTEGER,
        description TEXT,
        parent_category TEXT,
        category TEXT,
        category_slug TEXT
    )');
    $insert = $pdo->prepare('INSERT INTO torrents (name, hash_info, size, description, parent_category, category, category_slug) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insert->execute(['Dune Part Two 2160p HDR', 'hash-dune-2160', 45200000000, '', 'video', 'movies', 'video/movies']);
    $insert->execute(['Dune Part Two 1080p', 'hash-dune-1080', 18000000000, '', 'video', 'movies', 'video/movies']);
    $insert->execute(['Dune Part Two 1080p', 'hash-dune-1080-dup', 18000000000, '', 'video', 'movies', 'video/movies']);
    $insert->execute(['Severance S02 1080p', 'hash-severance', 18400000000, '', 'video', 'tv', 'video/tv']);
    $pdo = null;

    $torrentRouter = new SearchSmokeRouter();
    $torrentProvider = new TorrentSearchProvider(new TorrentDbService($torrentProject), $torrentRouter);
    $torrentResults = $torrentProvider->search('dune', 10);
    eq(2, count($torrentResults), 'torrent search finds matching rows and deduplicates identical names');
    eq('/torrent?q=Dune+Part+Two+2160p+HDR', $torrentResults[0]['url'] ?? null, 'torrent results open the torrent page with a prefilled query');
    eq('torrents', $torrentResults[0]['source'] ?? null, 'torrent source is stable');
    check(str_contains((string) ($torrentResults[0]['subtitle'] ?? ''), 'GB'), 'torrent subtitle contains a human readable size');
    eq(1, count($torrentProvider->search('dune', 1)), 'torrent search honours the limit');
    eq([], $torrentProvider->search('nothing-matches', 10), 'torrent search returns no false positives');

    $emptyTorrentProject = projectDir($sandbox . '/empty-torrent-project');
    $emptyTorrents = new TorrentSearchProvider(new TorrentDbService($emptyTorrentProject), new SearchSmokeRouter());
    eq([], $emptyTorrents->search('dune', 10), 'missing torrent DB is handled gracefully');

    echo "MediaSearchProvider\n";
    $mediaTmdb = new SearchSmokeTmdb();
    $mediaTmdb->configured = false;
    $mediaRouter = new SearchSmokeRouter();
    $mediaProvider = new MediaSearchProvider($mediaTmdb, $mediaRouter);
    eq([], $mediaProvider->search('matrix', 10), 'unconfigured TMDB is skipped cleanly');
    eq(0, $mediaTmdb->calls, 'unconfigured TMDB does not perform a search');

    $mediaTmdb->configured = true;
    $mediaTmdb->results = [
        ['id' => 603, 'type' => 'movie', 'title' => 'The Matrix', 'year' => '1999', 'rating' => 8.2],
        ['id' => 1399, 'type' => 'series', 'title' => 'Game of Thrones', 'year' => '2011', 'rating' => 8.4],
        ['id' => 0, 'type' => 'movie', 'title' => 'Invalid', 'year' => '', 'rating' => null],
    ];
    $mediaResults = $mediaProvider->search('matrix', 1);
    eq(1, count($mediaResults), 'media search honours the limit');
    eq('The Matrix', $mediaResults[0]['title'] ?? null, 'media title is mapped');
    eq('Movie · 1999', $mediaResults[0]['subtitle'] ?? null, 'media subtitle is mapped');
    eq('TMDB 8.2', $mediaResults[0]['meta'] ?? null, 'media meta is mapped');
    eq('/media?tmdb=movie-603', $mediaResults[0]['url'] ?? null, 'media URL opens the Discover detail view');
    eq('media', $mediaResults[0]['source'] ?? null, 'media source is stable');

    $mediaTmdb->error = new RuntimeException('TMDB exploded');
    $thrown = false;
    try {
        $mediaProvider->search('matrix', 10);
    } catch (RuntimeException $e) {
        $thrown = true;
    }
    check($thrown, 'media provider lets service-level errors bubble up for partial handling');

    echo "MusicSearchProvider\n";
    $musicSpotify = new SearchSmokeSpotify();
    $musicSpotify->configured = false;
    $musicProvider = new MusicSearchProvider($musicSpotify);
    eq([], $musicProvider->search('nujabes', 10), 'unconfigured Spotify is skipped cleanly');
    eq(0, $musicSpotify->calls, 'unconfigured Spotify does not perform a search');

    $musicSpotify->configured = true;
    $musicSpotify->results = [
        ['id' => 't1', 'type' => 'track', 'name' => 'Luv(sic)', 'subtitle' => 'Nujabes', 'meta' => 'Modal Soul', 'url' => 'https://open.spotify.com/track/t1'],
        ['id' => 'a1', 'type' => 'album', 'name' => 'Modal Soul', 'subtitle' => 'Nujabes', 'meta' => '14 tracks', 'url' => 'http://evil.example/album'],
    ];
    $musicResults = $musicProvider->search('nujabes', 1);
    eq(1, count($musicResults), 'music search honours the limit');
    eq('Luv(sic)', $musicResults[0]['title'] ?? null, 'music title is mapped');
    eq('Track · Nujabes', $musicResults[0]['subtitle'] ?? null, 'music subtitle is mapped');
    eq('Modal Soul', $musicResults[0]['meta'] ?? null, 'music meta is mapped');
    eq('https://open.spotify.com/track/t1', $musicResults[0]['url'] ?? null, 'Spotify URL is kept');
    eq('music', $musicResults[0]['source'] ?? null, 'music source is stable');

    $allMusic = $musicProvider->search('nujabes', 10);
    eq('https://open.spotify.com/album/a1', $allMusic[1]['url'] ?? null, 'unsafe music URL is rebuilt on the Spotify host');

    $musicSpotify->error = new RuntimeException('Spotify exploded');
    $musicThrown = false;
    try {
        $musicProvider->search('nujabes', 10);
    } catch (RuntimeException $e) {
        $musicThrown = true;
    }
    check($musicThrown, 'music provider lets service-level errors bubble up for partial handling');

    echo "ActionSearchProvider\n";
    $actionRouter = new SearchSmokeRouter();
    $actionProvider = new ActionSearchProvider($actionRouter);
    $allActions = $actionProvider->search('', 10);
    eq(5, count($allActions), 'all expected quick actions are exposed');
    $titles = array_column($allActions, 'title');
    foreach (['Update torrent DB', 'Check incomplete downloads', 'Open statistics', 'Run automation', 'Open settings'] as $expectedTitle) {
        check(in_array($expectedTitle, $titles, true), 'action ' . $expectedTitle . ' is present');
    }
    $generatedNames = array_column($actionRouter->generated, 'name');
    foreach (['maintenance', 'video_integrity', 'statistics', 'automation', 'config'] as $expectedRoute) {
        check(in_array($expectedRoute, $generatedNames, true), 'action URL is generated from route ' . $expectedRoute);
    }

    $statisticsAction = $actionProvider->search('statistics', 10);
    eq(1, count($statisticsAction), 'action search filters by query');
    eq('Open statistics', $statisticsAction[0]['title'] ?? null, 'matching action is returned');
    eq('/statistics', $statisticsAction[0]['url'] ?? null, 'action URL comes from the router');
    eq(1, count($actionProvider->search('', 1)), 'actions honour the limit');

    echo "Controller response\n";
    $controllerService = new GlobalSearchService(makeProviders());
    $controllerResponse = (new SearchController())->global(
        Request::create('/search/global', 'GET', ['q' => '  dune  ', 'scope' => 'local', 'limit' => '1']),
        $controllerService
    );
    $controllerPayload = json_decode((string) $controllerResponse->getContent(), true);
    eq(200, $controllerResponse->getStatusCode(), 'controller returns HTTP 200');
    eq(true, $controllerPayload['success'] ?? null, 'controller returns a JSON success payload');
    eq('dune', $controllerPayload['query'] ?? null, 'controller trims the query');
    eq(1, count($controllerPayload['groups']['actions'] ?? []), 'controller applies the requested scope and limit');
    eq([], $controllerPayload['groups']['media'] ?? null, 'controller leaves out-of-scope groups empty');

    echo "Route inventory and security\n";
    $routes = routerInventory();
    check($routes !== [], 'debug:router inventory is readable');
    check(is_subclass_of(SearchController::class, \Symfony\Bundle\FrameworkBundle\Controller\AbstractController::class), 'SearchController is autoconfigured for controller service arguments');
    check(isset($routes['search_global']), 'search_global route exists');
    if (isset($routes['search_global'])) {
        eq('/search/global', $routes['search_global']['path'] ?? null, 'search_global path is /search/global');
        check(routeMethodIncludesGet((string) ($routes['search_global']['method'] ?? '')), 'search_global accepts GET');
        check(str_contains((string) ($routes['search_global']['defaults']['_controller'] ?? ''), 'SearchController::global'), 'search_global maps to SearchController::global');
    }

    $existingRoutes = ['music', 'music_library', 'music_files', 'torrent', 'media_index', 'media_search', 'media_details', 'automation', 'video_integrity', 'queue', 'history', 'history_log', 'statistics', 'maintenance', 'config'];
    $missingRoutes = array_values(array_filter($existingRoutes, static fn (string $route): bool => !isset($routes[$route])));
    eq([], $missingRoutes, 'no existing route disappeared');

    $authReflection = new ReflectionClass(AuthSubscriber::class);
    $pollingConstant = $authReflection->getReflectionConstant('POLLING_ROUTES');
    $polling = $pollingConstant !== false ? $pollingConstant->getValue() : [];
    check(!in_array('search_global', $polling, true), 'search_global is not in the stateless polling exemption list');

    $csrf = new CsrfSubscriber(new CsrfTokenService());
    $getRequest = Request::create('/search/global', 'GET');
    eq(false, $csrf->shouldEnforce($getRequest), 'GET /search/global does not require CSRF');
    eq(false, CsrfSubscriber::isExemptPath('/search/global'), 'search_global is not hidden by a CSRF exemption');
    $csrfReflection = new ReflectionClass(CsrfSubscriber::class);
    $methodsConstant = $csrfReflection->getReflectionConstant('STATE_CHANGING_METHODS');
    $stateMethods = $methodsConstant !== false ? $methodsConstant->getValue() : [];
    check(!in_array('GET', $stateMethods, true), 'GET is not a state-changing method');

    echo "Navigation trigger\n";
    $navStorage = storageAt($sandbox . '/nav-storage', [
        'api_key' => 'smoke',
        'default_path' => '/tmp',
    ]);
    $navigation = (new NavigationService($navStorage))->getNavigation('media_index');
    $partialPath = $project . '/templates/_partials/navigation.html.twig';
    check(is_file($partialPath), 'navigation partial exists');
    $template = (string) file_get_contents($partialPath);
    check(str_contains($template, 'data-global-search-open'), 'navigation partial exposes the search trigger hook');
    check(str_contains($template, 'aria-label="Open global search"'), 'search trigger has an accessible label');
    check(str_contains($template, 'aria-haspopup="dialog"'), 'search trigger advertises a dialog');
    check(str_contains($template, '⌘K'), 'search trigger shows the keyboard shortcut');

    $twig = new Environment(new ArrayLoader(['navigation.html.twig' => $template]), ['strict_variables' => false]);
    $twig->addFunction(new TwigFunction('path', static function (string $name, array $parameters = []): string {
        $paths = [
            'music' => '/',
            'music_library' => '/music-library',
            'media_index' => '/media',
            'automation' => '/automation',
            'video_integrity' => '/video/integrity',
            'torrent' => '/torrent' . (isset($parameters['q']) ? '?' . http_build_query(['q' => $parameters['q']]) : ''),
            'queue' => '/queue',
            'history' => '/history',
            'statistics' => '/statistics',
            'config' => '/config',
            'logout' => '/logout',
        ];

        return $paths[$name] ?? '/' . $name;
    }));
    $navHtml = $twig->render('navigation.html.twig', [
        'navigation' => $navigation,
        'csrf_token' => 'smoke-token',
    ]);
    eq(5, substr_count($navHtml, 'data-nav-key='), 'navigation still renders exactly five top-level entries');
    eq(4, substr_count($navHtml, 'aria-haspopup="true"'), 'navigation still renders the four dropdowns');
    eq(1, substr_count($navHtml, 'data-global-search-open'), 'navigation renders one search trigger');
    check(str_contains($navHtml, 'aria-keyshortcuts="Control+K Meta+K"'), 'search trigger exposes keyboard shortcuts');
    check(str_contains($navHtml, '>Search<'), 'search trigger keeps its visible label');
    $disabledNavHtml = $twig->render('navigation.html.twig', [
        'navigation' => $navigation,
        'csrf_token' => 'smoke-token',
        'global_search_enabled' => false,
    ]);
    eq(0, substr_count($disabledNavHtml, 'data-global-search-open'), 'unconfigured navigation hides the search trigger');

    echo "Palette safety\n";
    $palette = (string) file_get_contents($project . '/templates/_partials/global_search.html.twig');
    check(str_contains($palette, 'createElement'), 'palette builds DOM nodes with createElement');
    check(str_contains($palette, 'textContent'), 'palette writes provider data through textContent');
    check(str_contains($palette, 'AbortController'), 'palette aborts stale requests with AbortController');
    check(str_contains($palette, 'role="dialog"') && str_contains($palette, 'aria-modal="true"'), 'palette exposes an accessible dialog');
    check(str_contains($palette, 'global_search_recent'), 'recent searches use a local storage key');
    check(preg_match('/\.gs-scopes\s*\{[^}]*flex:\s*0 0 auto/s', $palette) === 1, 'scope tabs keep their full height when results overflow');
    check(str_contains($palette, "link.target = '_blank'") && str_contains($palette, "link.rel = 'noopener noreferrer'"), 'external music links open with noopener/noreferrer');
    check(str_contains($palette, 'openExternalSpotify') && str_contains($palette, 'search stays open'), 'Spotify preview keeps the palette results available');
    check(str_contains($palette, 'gs-item-download') && str_contains($palette, 'openMusicDownload'), 'music results expose a download action');
    check(str_contains($palette, 'music_add') && str_contains($palette, "formData.append('urls'"), 'music download actions queue directly without leaving the palette');
    check(!preg_match('/innerHTML\s*=\s*(?:item|data|result)[\.\[]/', $palette), 'palette never injects provider items through innerHTML');
    $mediaTemplate = (string) file_get_contents($project . '/templates/media/index.html.twig');
    check(str_contains($mediaTemplate, "get('tmdb')") && str_contains($mediaTemplate, 'openMediaDetail'), 'Discover supports palette deep links');
    $debridTemplate = (string) file_get_contents($project . '/templates/dashboard/debrid.html.twig');
    check(str_contains($debridTemplate, "get('q')") && str_contains($debridTemplate, 'searchTorrentDb()') && str_contains($debridTemplate, "addEventListener('load'"), 'Debrid torrent page supports deferred palette query prefill');
    check(str_contains($mediaTemplate, 'overflow-wrap:anywhere'), 'Discover source filenames are shown in full');
    $packsTemplate = (string) file_get_contents($project . '/templates/dashboard/index.html.twig');
    check(str_contains($packsTemplate, 'view-tab active') && str_contains($packsTemplate, 'var(--panel'), 'Packs view shares the v2.8 card styling');
} finally {
    removeTree($sandbox);
}

echo "\n$passes passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
