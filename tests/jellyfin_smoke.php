<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Service\Automation\AutomationSettings;
use App\Service\Automation\EpisodeNumberingService;
use App\Service\Automation\AutomationStore;
use App\Service\Jellyfin\JellyfinAuthService;
use App\Service\Jellyfin\JellyfinIds;
use App\Service\Jellyfin\JellyfinLibraryService;
use App\Service\Jellyfin\JellyfinMediaService;
use App\Service\Jellyfin\JellyfinMetadataService;
use App\Service\Jellyfin\JellyfinStateService;
use App\Service\JsonStorage;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Kernel;

final class JellyfinTestKernel extends Kernel
{
    private string $project;

    public function __construct(string $project)
    {
        $this->project = $project;
    }

    public function registerBundles(): iterable
    {
        return [];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
    }

    public function getProjectDir(): string
    {
        return $this->project;
    }
}

final class JellyfinFakeMetadataService extends JellyfinMetadataService
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
                'genres' => ['Science Fiction', 'Adventure'],
                'rating' => 8.5,
                'year' => '2024',
                'poster' => 'https://image.tmdb.org/t/p/w342/dune.jpg',
                'backdrop' => 'https://image.tmdb.org/t/p/w780/dune-backdrop.jpg',
                'runtime' => 166,
                'imdb_id' => 'tt15239678',
            ];
        }

        if ($type === 'series' && $tmdbId === 1396) {
            return [
                'id' => 1396,
                'type' => 'series',
                'title' => 'The Sopranos',
                'overview' => 'A New Jersey mob boss seeks therapy.',
                'genres' => ['Drama'],
                'rating' => 9.2,
                'year' => '1999',
                'poster' => 'https://image.tmdb.org/t/p/w342/sopranos.jpg',
                'backdrop' => 'https://image.tmdb.org/t/p/w780/sopranos-backdrop.jpg',
                'episode_runtime' => 55,
                'imdb_id' => 'tt0141842',
            ];
        }

        return null;
    }

    public function imageUrl(string $type, ?int $tmdbId, ?string $imdbId, string $imageType, ?int $index = null): ?string
    {
        if ($type === 'movie' && $tmdbId === 693134) {
            return strtolower($imageType) === 'backdrop'
                ? 'https://image.tmdb.org/t/p/w780/dune-backdrop.jpg'
                : 'https://image.tmdb.org/t/p/w342/dune.jpg';
        }

        if ($type === 'series' && $tmdbId === 1396) {
            return strtolower($imageType) === 'backdrop'
                ? 'https://image.tmdb.org/t/p/w780/sopranos-backdrop.jpg'
                : 'https://image.tmdb.org/t/p/w342/sopranos.jpg';
        }

        return null;
    }

    public function runtimeTicks(string $type, ?array $details): ?int
    {
        if ($details === null) {
            return null;
        }

        $minutes = $type === 'movie' ? ($details['runtime'] ?? null) : ($details['episode_runtime'] ?? null);

        return is_numeric($minutes) ? (int) $minutes * 60 * 10_000_000 : null;
    }
}

final class JellyfinFakeNumberingService extends EpisodeNumberingService
{
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
            'jellyfin_mode' => 'tmdb',
            'target_mode' => 'local',
            'segments' => [['season' => 1, 'episodes' => 4], ['season' => 2, 'episodes' => 1]],
            'mapping' => [[
                'tmdb_season' => 1,
                'tmdb_episode' => 4,
                'release_season' => 2,
                'release_episode' => 1,
                'title' => 'Episode 4',
                'air_date' => '2024-01-22',
            ]],
        ];
    }

    public function canonicalFor(?array $profile, int $season, int $episode): ?array
    {
        if ($season === 2 && $episode === 1) {
            return ['season' => 1, 'episode' => 4];
        }

        return null;
    }
}

$failures = 0;
$passes = 0;

function check(bool $condition, string $label, mixed $actual = null): void
{
    global $failures, $passes;

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

echo "Identifiers\n";
$libraryId = JellyfinIds::library('movies');
$movieId = JellyfinIds::movie('movies', 'tmdb:693134');
$seriesId = JellyfinIds::series('series', 'folder:The Sopranos');
$episodeId = JellyfinIds::episode(42);
eq('library', JellyfinIds::decode($libraryId)['kind'], 'library id roundtrip');
eq('movies', JellyfinIds::decode($libraryId)['root'], 'library root roundtrip');
eq('movie', JellyfinIds::decode($movieId)['kind'], 'movie id roundtrip');
eq('tmdb:693134', JellyfinIds::decode($movieId)['key'], 'movie key roundtrip');
eq('folder:The Sopranos', JellyfinIds::decode($seriesId)['key'], 'series key roundtrip');
eq(42, JellyfinIds::decode($episodeId)['id'], 'episode id roundtrip');
check(JellyfinIds::decode('not-an-id') === null, 'invalid id is rejected');

$project = sys_get_temp_dir() . '/jellyfin-smoke-' . bin2hex(random_bytes(4));
mkdir($project, 0777, true);
$storage = new JsonStorage(new JellyfinTestKernel($project));
$storage->set('config', [
    'jellyfin_enabled' => true,
    'jellyfin_username' => 'nuvio',
    'jellyfin_password' => 'secret',
    'jellyfin_server_name' => 'Downloader Test',
]);

echo "Authentication\n";
$auth = new JellyfinAuthService($storage);
check($auth->isEnabled(), 'jellyfin is enabled from config');
eq('Downloader Test', $auth->publicInfo()['ServerName'], 'public info exposes server name');
eq('10.9.0', $auth->publicInfo()['Version'], 'public info exposes a compatible version');
check($auth->authenticate('nuvio', 'wrong') === null, 'wrong password is rejected');
$signIn = $auth->authenticate('nuvio', 'secret');
check($signIn !== null && isset($signIn['AccessToken']), 'valid credentials return a token');
eq('nuvio', $signIn['User']['Name'], 'sign-in returns the user name');
$request = Request::create('/jellyfin/UserViews', 'GET', ['api_key' => $signIn['AccessToken']]);
eq($signIn['AccessToken'], $auth->tokenFromRequest($request), 'token is read from api_key');
check($auth->validateToken($auth->tokenFromRequest($request)) !== null, 'token validates');
$authorized = Request::create('/jellyfin/UserViews', 'GET');
$authorized->headers->set('Authorization', 'MediaBrowser Client="Nuvio", Device="Test", DeviceId="1", Version="1", Token="' . $signIn['AccessToken'] . '"');
eq($signIn['AccessToken'], $auth->tokenFromRequest($authorized), 'token is read from Authorization header');
$auth->logout($signIn['AccessToken']);
check($auth->validateToken($signIn['AccessToken']) === null, 'logout invalidates the token');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "Library, playback and state\n  skip (pdo_sqlite not available)\n";
    echo "\n$passes passed, $failures failed.\n";
    exit($failures === 0 ? 0 : 1);
}

echo "Library, playback and state\n";
$moviesRoot = $project . '/nas/Video/Movies';
$seriesRoot = $project . '/nas/Video/TV Shows';
mkdir($moviesRoot . '/Dune Part Two (2024)', 0777, true);
mkdir($seriesRoot . '/The Sopranos/Season 01', 0777, true);
$moviePath = $moviesRoot . '/Dune Part Two (2024)/Dune Part Two (2024).mkv';
$episodeOnePath = $seriesRoot . '/The Sopranos/Season 01/The Sopranos - S01E01.mkv';
$episodeTwoPath = $seriesRoot . '/The Sopranos/Season 01/The Sopranos - S01E02.mkv';
file_put_contents($moviePath, str_repeat('m', 512));
file_put_contents($episodeOnePath, str_repeat('e', 256));
file_put_contents($episodeTwoPath, str_repeat('e', 256));

$store = new AutomationStore($project);
$settings = new AutomationSettings($store);
$settings->save([
    'roots' => [
        'movies' => ['label' => 'Movies', 'path' => $moviesRoot, 'type' => 'movie', 'enabled' => true],
        'series' => ['label' => 'TV Shows', 'path' => $seriesRoot, 'type' => 'series', 'enabled' => true],
        'anime' => ['label' => 'Anime', 'path' => $project . '/nas/Video/Anime', 'type' => 'anime', 'enabled' => false],
    ],
]);

$store->upsertLibraryEntry([
    'root_key' => 'movies',
    'path' => $moviePath,
    'relative_path' => 'Dune Part Two (2024)/Dune Part Two (2024).mkv',
    'folder_path' => 'Dune Part Two (2024)',
    'filename' => basename($moviePath),
    'extension' => 'mkv',
    'media_type' => 'movie',
    'title' => 'Dune: Part Two',
    'title_normalized' => 'dune part two',
    'year' => 2024,
    'season' => null,
    'episode' => null,
    'quality' => '1080p',
    'size' => filesize($moviePath),
    'mtime' => filemtime($moviePath),
    'media_key' => 'movie:tmdb:693134',
    'tmdb_id' => 693134,
    'imdb_id' => 'tt15239678',
    'confidence' => 0.99,
    'parse_source' => 'filename',
    'parser_version' => 3,
    'scan_id' => 1,
    'show_folder' => null,
]);

foreach ([[1, $episodeOnePath], [2, $episodeTwoPath]] as [$episode, $path]) {
    $store->upsertLibraryEntry([
        'root_key' => 'series',
        'path' => $path,
        'relative_path' => 'The Sopranos/Season 01/' . basename($path),
        'folder_path' => 'The Sopranos/Season 01',
        'filename' => basename($path),
        'extension' => 'mkv',
        'media_type' => 'series',
        'title' => 'The Sopranos',
        'title_normalized' => 'the sopranos',
        'year' => 1999,
        'season' => 1,
        'episode' => $episode,
        'quality' => '1080p',
        'size' => filesize($path),
        'mtime' => filemtime($path),
        'media_key' => 'series:tmdb:1396:s01e0' . $episode,
        'tmdb_id' => 1396,
        'imdb_id' => 'tt0141842',
        'confidence' => 0.99,
        'parse_source' => 'filename',
        'parser_version' => 3,
        'scan_id' => 1,
        'show_folder' => 'The Sopranos',
    ]);
}

$state = new JellyfinStateService($storage);
$library = new JellyfinLibraryService(
    $store,
    $settings,
    $state,
    new JellyfinMediaService($settings, new ArrayAdapter()),
    new JellyfinFakeMetadataService()
);

$views = $library->views();
eq(2, $views['TotalRecordCount'], 'two enabled roots with entries are exposed');
eq('movies', $views['Items'][0]['CollectionType'], 'movie root maps to movies');
eq('tvshows', $views['Items'][1]['CollectionType'], 'series root maps to tvshows');
check(!array_key_exists('ImageTags', $views['Items'][0]), 'library views omit an empty ImageTags map');

$movieViewId = JellyfinIds::library('movies');
$moviePage = $library->items(['parentId' => $movieViewId, 'includeItemTypes' => 'Movie', 'limit' => '50'], 'user');
eq(1, $moviePage['TotalRecordCount'], 'movie library returns one grouped movie');
$movie = $moviePage['Items'][0];
eq('Movie', $movie['Type'], 'movie item has the Movie type');
eq('Dune: Part Two', $movie['Name'], 'movie item keeps its title');
eq('693134', $movie['ProviderIds']['Tmdb'], 'movie item exposes its TMDB id');
check(isset($movie['ImageTags']['Primary']), 'movie item exposes a poster tag');

$movieFull = $library->item($movie['Id'], 'user', true, true, true);
check($movieFull !== null, 'movie item can be loaded');
eq(1, count($movieFull['MediaSources']), 'movie item exposes one media source');
eq('ms_' . $store->getEntryById(1)['id'], $movieFull['MediaSources'][0]['Id'], 'media source id is stable');
check($movieFull['MediaSources'][0]['SupportsDirectPlay'] === true, 'media source supports direct play');
$streamEntries = $library->streamEntries($movie['Id'], $movieFull['MediaSources'][0]['Id']);
check($streamEntries !== null && $streamEntries['entry']['path'] === $moviePath, 'stream entry resolves to the indexed file');
$playback = $library->playbackInfo($movie['Id']);
check($playback !== null && isset($playback['PlaySessionId']), 'playback info exposes a session id');

$seriesViewId = JellyfinIds::library('series');
$seriesPage = $library->items(['parentId' => $seriesViewId, 'includeItemTypes' => 'Series'], 'user');
eq(1, $seriesPage['TotalRecordCount'], 'series library returns one grouped series');
$series = $seriesPage['Items'][0];
eq('Series', $series['Type'], 'series item has the Series type');
$episodes = $library->episodes($series['Id'], 'user', 1);
eq(2, $episodes['TotalRecordCount'], 'series exposes two episodes for season 1');
eq(1, $episodes['Items'][0]['IndexNumber'], 'episode number is exposed');
eq($series['Id'], $episodes['Items'][0]['SeriesId'], 'episode links back to its series');

$splitDir = $seriesRoot . '/Split Show (2024)/Season 02';
mkdir($splitDir, 0777, true);
$splitPath = $splitDir . '/Split Show (2024) - S02E01.mkv';
file_put_contents($splitPath, str_repeat('s', 256));
$store->upsertLibraryEntry([
    'root_key' => 'series',
    'path' => $splitPath,
    'relative_path' => 'Split Show (2024)/Season 02/' . basename($splitPath),
    'folder_path' => 'Split Show (2024)/Season 02',
    'filename' => basename($splitPath),
    'extension' => 'mkv',
    'media_type' => 'series',
    'title' => 'Split Show',
    'title_normalized' => 'split show',
    'year' => 2024,
    'season' => 2,
    'episode' => 1,
    'quality' => '1080p',
    'size' => filesize($splitPath),
    'mtime' => filemtime($splitPath),
    'media_key' => 'series:tmdb:900001:s02e01',
    'tmdb_id' => 900001,
    'imdb_id' => 'tt9000001',
    'confidence' => 0.99,
    'parse_source' => 'filename',
    'parser_version' => 3,
    'scan_id' => 2,
    'show_folder' => 'Split Show (2024)',
]);
$numberedLibrary = new JellyfinLibraryService(
    $store,
    $settings,
    $state,
    new JellyfinMediaService($settings, new ArrayAdapter()),
    new JellyfinFakeMetadataService(),
    new JellyfinFakeNumberingService()
);
$splitSeriesId = JellyfinIds::series('series', 'folder:Split Show (2024)');
$splitSeasonOne = $numberedLibrary->episodes($splitSeriesId, 'user', 1);
eq(1, $splitSeasonOne['TotalRecordCount'], 'Jellyfin exposes the local release episode under the TMDB season');
eq(1, $splitSeasonOne['Items'][0]['ParentIndexNumber'], 'Jellyfin parent index follows TMDB numbering');
eq(4, $splitSeasonOne['Items'][0]['IndexNumber'], 'Jellyfin episode index follows TMDB numbering');
$splitSeasonTwo = $numberedLibrary->episodes($splitSeriesId, 'user', 2);
eq(0, $splitSeasonTwo['TotalRecordCount'], 'local release season is hidden in TMDB numbering mode');
$splitPlayback = $numberedLibrary->playbackInfo($splitSeasonOne['Items'][0]['Id']);
check($splitPlayback !== null && count($splitPlayback['MediaSources']) === 1, 'virtual TMDB episode exposes playback info');
$splitStream = $numberedLibrary->streamEntries($splitSeasonOne['Items'][0]['Id'], $splitPlayback['MediaSources'][0]['Id']);
check($splitStream !== null && $splitStream['entry']['path'] === $splitPath, 'virtual TMDB episode streams the local split file');

check($library->setPlayed('user', $movie['Id'], true), 'movie can be marked as played');
check($state->get('user', $movie['Id'])['played'] === true, 'played state is persisted');
$state->setProgress('user', $movie['Id'], 5000);
check($state->get('user', $movie['Id'])['played'] === false, 'progress clears the played flag');
$resume = $library->resume('user', 10);
eq(1, $resume['TotalRecordCount'], 'resume returns the in-progress item');

$imageUrl = $library->imageUrl($movie['Id'], 'Backdrop', 0);
eq('https://image.tmdb.org/t/p/w780/dune-backdrop.jpg', $imageUrl, 'backdrop image resolves through metadata');

echo "\n$passes passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
