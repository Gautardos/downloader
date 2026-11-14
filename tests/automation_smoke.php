<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Service\Automation\AutomationSettings;
use App\Service\Automation\AutomationStore;
use App\Service\Automation\LibraryMetadataResolver;
use App\Service\Automation\LibraryRenameService;
use App\Service\Automation\LibraryNameParser;
use App\Service\Automation\ListSourceClientInterface;
use App\Service\Automation\ListSourceRegistry;
use App\Service\Automation\ListSyncService;
use App\Service\Automation\MdbListClient;
use App\Service\Automation\ReleaseMetadataParser;
use App\Service\Automation\ReleasePreferenceService;
use App\Service\Automation\ScrobListClient;
use App\Service\TmdbService;


final class FakeTmdbService extends TmdbService
{
    public array $searchQueries = [];
    public array $detailCalls = [];

    public function __construct()
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function getLanguage(): string
    {
        return 'fr-FR';
    }

    public function search(string $query, string $type = 'all', ?bool $includeAdult = null, ?string $language = null): array
    {
        $this->searchQueries[] = $query . '|' . $type;
        $normalized = strtolower($query);

        if (str_contains($normalized, 'sopranos')) {
            return [['id' => 1396, 'type' => 'series', 'title' => 'Les Soprano', 'original_title' => 'The Sopranos', 'year' => '1999', 'rating' => 8.7]];
        }
        if (str_contains($normalized, 'office')) {
            return [
                ['id' => 2316, 'type' => 'series', 'title' => 'The Office', 'year' => '2005', 'rating' => 8.6],
                ['id' => 2996, 'type' => 'series', 'title' => 'The Office', 'year' => '2001', 'rating' => 7.5],
            ];
        }
        if (str_contains($normalized, 'dune')) {
            return [['id' => 693134, 'type' => 'movie', 'title' => 'Dune: Part Two', 'year' => '2024', 'rating' => 8.5]];
        }
        if (str_contains($normalized, 'oz')) {
            return [
                ['id' => 3322, 'type' => 'series', 'title' => 'Oz', 'year' => '1997', 'rating' => 8.0],
                ['id' => 115584, 'type' => 'series', 'title' => 'OZ', 'year' => '1992', 'rating' => 6.0],
            ];
        }
        if (str_contains($normalized, 'rurouni kenshin')) {
            return $language === 'en-US'
                ? [['id' => 210879, 'type' => 'series', 'title' => 'Rurouni Kenshin', 'year' => '2023', 'rating' => 8.3]]
                : [['id' => 210879, 'type' => 'series', 'title' => 'Kenshin le Vagabond', 'original_title' => 'るろうに剣心', 'year' => '2023', 'rating' => 8.3]];
        }
        if (str_contains($normalized, 'demon slayer')) {
            return $language === 'en-US'
                ? [['id' => 1311031, 'type' => 'movie', 'title' => 'Demon Slayer: Kimetsu no Yaiba Infinity Castle', 'year' => '2025', 'rating' => 8.8]]
                : [['id' => 1311031, 'type' => 'movie', 'title' => 'Demon Slayer : Kimetsu no Yaiba La Forteresse Infinie', 'original_title' => '劇場版「鬼滅の刃」無限城編', 'year' => '2025', 'rating' => 8.8]];
        }

        return [];
    }

    public function season(int $tvId, int $seasonNumber): array
    {
        if ($tvId === 1396 && $seasonNumber === 1) {
            return [
                ['episode_number' => 1, 'name' => 'Pilot', 'air_date' => '1999-01-10'],
                ['episode_number' => 2, 'name' => '46 Long', 'air_date' => '1999-01-17'],
                ['episode_number' => 3, 'name' => 'Denial, Anger, Acceptance', 'air_date' => '2999-01-01'],
            ];
        }

        return [];
    }

    public function details(string $type, int $id, ?string $language = null): ?array
    {
        $this->detailCalls[] = $type . '|' . $id;

        return match ($id) {
            1396 => ['id' => 1396, 'type' => 'series', 'title' => 'Les Soprano', 'original_title' => 'The Sopranos', 'year' => '1999', 'imdb_id' => 'tt0141842', 'seasons' => [['season_number' => 1, 'episode_count' => 3]]],
            693134 => ['id' => 693134, 'type' => 'movie', 'title' => 'Dune: Part Two', 'year' => '2024', 'imdb_id' => 'tt15239678'],
            3322 => ['id' => 3322, 'type' => 'series', 'title' => 'Oz', 'year' => '1997', 'imdb_id' => 'tt0118421', 'seasons' => [['season_number' => 4, 'episode_count' => 16]]],
            115584 => ['id' => 115584, 'type' => 'series', 'title' => 'OZ', 'year' => '1992', 'imdb_id' => 'tt0103529', 'seasons' => [['season_number' => 1, 'episode_count' => 8]]],
            210879 => ['id' => 210879, 'type' => 'series', 'title' => 'Rurouni Kenshin', 'year' => '2023', 'imdb_id' => 'tt27882080', 'seasons' => [['season_number' => 1, 'episode_count' => 24]]],
            1311031 => ['id' => 1311031, 'type' => 'movie', 'title' => 'Demon Slayer: Kimetsu no Yaiba Infinity Castle', 'year' => '2025', 'imdb_id' => 'tt1311031'],
            2316 => ['id' => 2316, 'type' => 'series', 'title' => 'The Office', 'year' => '2005', 'imdb_id' => 'tt0386676', 'seasons' => [['season_number' => 1, 'episode_count' => 6]]],
            2996 => ['id' => 2996, 'type' => 'series', 'title' => 'The Office', 'year' => '2001', 'imdb_id' => 'tt0290978', 'seasons' => [['season_number' => 1, 'episode_count' => 6]]],
            default => null,
        };
    }
}

final class FakeQualitySettings extends AutomationSettings
{
    public function __construct()
    {
    }

    public function defaults(): array
    {
        return [
            'quality_profile' => [
                'resolution_order' => ['2160p', '1080p', '720p'],
                'max_resolution' => '2160p',
                'min_resolution' => '720p',
                'codecs' => ['x265', 'h264', 'av1'],
                'hdr' => 'prefer',
                'audio_langs' => ['VF', 'VOSTFR', 'MULTI'],
                'min_seeders' => 5,
                'max_movie_size_gb' => 25,
                'max_episode_size_gb' => 5,
                'exclude' => ['CAM', 'TS', '3D', 'SAMPLE'],
                'addon_priority' => true,
            ],
        ];
    }

    public function getQualityProfile(): array
    {
        return [];
    }
}

final class FakeListSourceClient implements ListSourceClientInterface
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
            'lists' => [['ref' => '1', 'name' => 'Test list', 'count' => count($this->items)]],
        ];
    }

    public function fetch(array $source, int $maxItems = 500): array
    {
        return [
            'items' => array_slice($this->items, 0, $maxItems),
            'lists' => [['ref' => '1', 'name' => 'Test list', 'count' => count($this->items)]],
            'warnings' => $this->warnings,
        ];
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

echo "Parser\n";
$parser = new LibraryNameParser();

$cases = [
    ['/nas/Video/Movies/1917 (2019)/1917.2019.1080p.mkv', '/nas/Video/Movies', 'movies', 'movie', ['title' => '1917', 'year' => 2019]],
    ['/nas/Video/Movies/Blade Runner 2049 (2017)/Blade Runner 2049.2017.2160p.mkv', '/nas/Video/Movies', 'movies', 'movie', ['title' => 'Blade Runner 2049', 'year' => 2017]],
    ['/nas/Video/Movies/2012 (2009)/2012.2009.1080p.mkv', '/nas/Video/Movies', 'movies', 'movie', ['title' => '2012', 'year' => 2009]],
    ['/nas/Video/Movies/The French Connection (1971)/The.French.Connection.1971.1080p.mkv', '/nas/Video/Movies', 'movies', 'movie', ['title' => 'The French Connection', 'year' => 1971]],
    ['/nas/Video/Movies/The Extended Family (2010)/The.Extended.Family.2010.mkv', '/nas/Video/Movies', 'movies', 'movie', ['title' => 'The Extended Family', 'year' => 2010]],
    ['/nas/Video/Movies/千と千尋の神隠し (2001)/千と千尋の神隠し.2001.mkv', '/nas/Video/Movies', 'movies', 'movie', ['title' => '千と千尋の神隠し', 'year' => 2001]],
    ['/nas/Video/TV Shows/The Office (2001)/Season 01/The.Office.2001.S01E01.mkv', '/nas/Video/TV Shows', 'series', 'series', ['title' => 'The Office', 'year' => 2001, 'season' => 1, 'episode' => 1]],
    ['/nas/Video/TV Shows/The Office (2005)/Season 01/The.Office.2005.S01E01.mkv', '/nas/Video/TV Shows', 'series', 'series', ['title' => 'The Office', 'year' => 2005, 'season' => 1, 'episode' => 1]],
    ['/nas/Video/TV Shows/American Dad/Season 10/American Dad.S10E02.mkv', '/nas/Video/TV Shows', 'series', 'series', ['title' => 'American Dad', 'year' => null, 'season' => 10, 'episode' => 2]],
    ['/nas/Video/TV Shows/Show (2020)/Season 01/Show.S01E01E02.mkv', '/nas/Video/TV Shows', 'series', 'series', ['title' => 'Show', 'year' => 2020, 'season' => 1, 'episode' => 1]],
];

foreach ($cases as [$path, $root, $key, $type, $expected]) {
    $result = $parser->parse($path, $root, $key, $type, ['read_nfo' => false]);
    foreach ($expected as $field => $value) {
        eq($value, $result[$field] ?? null, basename($path) . ' ' . $field);
    }
}

$key2001 = $parser->parse('/nas/Video/TV Shows/The Office (2001)/Season 01/The.Office.2001.S01E01.mkv', '/nas/Video/TV Shows', 'series', 'series', ['read_nfo' => false])['media_key'];
$key2005 = $parser->parse('/nas/Video/TV Shows/The Office (2005)/Season 01/The.Office.2005.S01E01.mkv', '/nas/Video/TV Shows', 'series', 'series', ['read_nfo' => false])['media_key'];
check($key2001 !== $key2005, 'same-title series get different media keys', [$key2001, $key2005]);

$noYear = $parser->parse('/nas/Video/TV Shows/American Dad/Season 10/American Dad.S10E02.mkv', '/nas/Video/TV Shows', 'series', 'series', ['read_nfo' => false]);
check($noYear['confidence'] < 0.8, 'series without year is flagged as ambiguous', $noYear['confidence']);

$multi = $parser->parse('/nas/Video/TV Shows/Show (2020)/Season 01/Show.S01E01E02.mkv', '/nas/Video/TV Shows', 'series', 'series', ['read_nfo' => false]);
check($multi['confidence'] <= 0.6, 'multi-episode file is flagged as ambiguous', $multi['confidence']);

$nonLatin = $parser->parse('/nas/Video/Movies/Матрица (1999)/Матрица.mkv', '/nas/Video/Movies', 'movies', 'movie', ['read_nfo' => false]);
check($nonLatin['media_key'] !== 'movie::1999' && $nonLatin['title'] !== '', 'non-latin title keeps a usable identity', $nonLatin['media_key']);

echo "NFO\n";
$tmp = sys_get_temp_dir() . '/automation-nfo-' . bin2hex(random_bytes(4));
$showDir = $tmp . '/Video/TV Shows/Some Show (2019)/Season 01';
$movieDir = $tmp . '/Video/Movies/Some Movie (2020)';
mkdir($showDir, 0777, true);
mkdir($movieDir, 0777, true);
file_put_contents($movieDir . '/movie.nfo', '<movie><title>Some Movie</title><tmdbid>12345</tmdbid><imdbid>tt1234567</imdbid></movie>');
file_put_contents($tmp . '/Video/TV Shows/Some Show (2019)/tvshow.nfo', '<tvshow><title>Some Show From Nfo</title><year>2019</year><uniqueid type="tmdb">67890</uniqueid></tvshow>');
file_put_contents($showDir . '/season.nfo', '<season><season>9</season><episode>99</episode></season>');
file_put_contents($showDir . '/Some.Show.S01E04.nfo', '<episode><season>1</season><episode>4</episode></episode>');

$movieNfo = $parser->parse($movieDir . '/Whatever.2021.1080p.mkv', $tmp . '/Video/Movies', 'movies', 'movie', ['read_nfo' => true]);
eq('Some Movie', $movieNfo['title'], 'movie NFO title');
eq(2021, $movieNfo['year'], 'movie NFO missing year falls back to filename');
eq('movie:tmdb:12345', $movieNfo['media_key'], 'movie NFO TMDB key');

$showNfo = $parser->parse($showDir . '/Some.Show.S01E04.mkv', $tmp . '/Video/TV Shows', 'series', 'series', ['read_nfo' => true]);
eq('Some Show From Nfo', $showNfo['title'], 'tvshow NFO title');
eq(1, $showNfo['season'], 'episode NFO season wins over season.nfo');
eq(4, $showNfo['episode'], 'episode NFO episode wins over season.nfo');
eq('series:tmdb:67890:s01e04', $showNfo['media_key'], 'series NFO TMDB key');

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $file) {
    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
}
rmdir($tmp);

echo "Release parser and scoring\n";
$releaseParser = new ReleaseMetadataParser();
$goodRelease = [
    'name' => '[Lumio] Movie.2024.2160p.HDR10.MULTI.x265-GROUP',
    'title' => 'Movie 2024 2160p HDR10 MULTI x265 · 18.4 GB · 52 seeders',
    'filename' => 'Movie.2024.2160p.HDR10.MULTI.x265-GROUP.mkv',
    'size' => '18.4 GB',
    'seeders' => 52,
    'addon' => 'Lumio',
    'priority' => 10,
];
$parsedGood = $releaseParser->parse($goodRelease);
eq('2160p', $parsedGood['resolution'], 'parser resolution');
eq('x265', $parsedGood['codec'], 'parser codec');
eq('HDR10', $parsedGood['hdr'], 'parser HDR');
check(in_array('MULTI', $parsedGood['audio_langs'], true), 'parser audio languages', $parsedGood['audio_langs']);
eq('Lumio', $parsedGood['release_group'], 'parser bracket release group');
eq(52, $parsedGood['seeders'], 'parser seeders');
check(abs($parsedGood['size_gb'] - 18.4) < 0.01, 'parser size in GB', $parsedGood['size_gb']);

$parsedWeb = $releaseParser->parse([
    'name' => 'Movie.2024.1080p.WEB-DL.VOSTFR.h264',
    'title' => 'Movie 2024 1080p WEB-DL VOSTFR h264 · 8.2 GB · 18 seeders',
    'filename' => 'Movie.2024.1080p.WEB-DL.VOSTFR.h264.mkv',
    'size' => '8.2 GB',
    'seeders' => 18,
]);
eq('1080p', $parsedWeb['resolution'], 'parser WEB-DL resolution');
eq('h264', $parsedWeb['codec'], 'parser h264 codec');
eq('WEB-DL', $parsedWeb['source_type'], 'parser WEB-DL source type');
check(in_array('VOSTFR', $parsedWeb['audio_langs'], true), 'parser VOSTFR audio', $parsedWeb['audio_langs']);
eq(null, $parsedWeb['hdr'], 'parser detects SDR');

$camRelease = [
    'name' => 'Movie.2024.CAM.x265',
    'title' => 'Movie 2024 CAM x265 · 2.1 GB · 10 seeders',
    'filename' => 'Movie.2024.CAM.x265.mkv',
    'size' => '2.1 GB',
    'seeders' => 10,
];
$parsedCam = $releaseParser->parse($camRelease);
eq('CAM', $parsedCam['source_type'], 'parser CAM source type');

$quality = new ReleasePreferenceService($releaseParser, new FakeQualitySettings());
$profile = [
    'resolution_order' => ['2160p', '1080p', '720p'],
    'max_resolution' => '2160p',
    'min_resolution' => '720p',
    'codecs' => ['x265', 'h264', 'av1'],
    'hdr' => 'prefer',
    'audio_langs' => ['VF', 'VOSTFR', 'MULTI'],
    'min_seeders' => 5,
    'max_movie_size_gb' => 25,
    'max_episode_size_gb' => 5,
    'exclude' => ['CAM', 'TS', '3D', 'SAMPLE'],
    'addon_priority' => true,
];
$goodEvaluation = $quality->evaluate($goodRelease, $profile);
check($goodEvaluation['accepted'], 'good release passes hard filters', $goodEvaluation);
check($goodEvaluation['score'] >= 70, 'good release scores high', $goodEvaluation['score']);
check(str_contains($goodEvaluation['explanation'], '2160p x265'), 'explanation includes resolution and codec', $goodEvaluation['explanation']);

$episodeRelease = [
    'name' => 'Show.S01E01.2160p.WEB-DL.HDR10.MULTI.x265-GROUP',
    'title' => 'Show S01E01 2160p WEB-DL HDR10 MULTI x265 · 10 GB · 30 seeders',
    'filename' => 'Show.S01E01.2160p.WEB-DL.HDR10.MULTI.x265-GROUP.mkv',
    'size' => '10 GB',
    'seeders' => 30,
];
$movieSizeEvaluation = $quality->evaluate($episodeRelease, $profile, 'movie');
check($movieSizeEvaluation['accepted'], 'movie size limit accepts a 10 GB movie', $movieSizeEvaluation['failures']);
$episodeSizeEvaluation = $quality->evaluate($episodeRelease, $profile, 'series');
check(!$episodeSizeEvaluation['accepted'] && in_array('size_above_max', $episodeSizeEvaluation['failures'], true), 'episode size limit rejects a 10 GB episode', $episodeSizeEvaluation['failures']);

$lowSeeders = $quality->evaluate([
    'name' => 'Movie.2024.720p.HDTV.VF.x264',
    'title' => 'Movie 2024 720p HDTV VF x264 · 1.8 GB · 3 seeders',
    'filename' => 'Movie.2024.720p.HDTV.VF.x264.mkv',
    'size' => '1.8 GB',
    'seeders' => 3,
], $profile);
check(!$lowSeeders['accepted'] && in_array('not_enough_seeders', $lowSeeders['failures'], true), 'low seeders are rejected', $lowSeeders['failures']);

$camEvaluation = $quality->evaluate($camRelease, $profile);
check(!$camEvaluation['accepted'] && in_array('excluded_tag:CAM', $camEvaluation['failures'], true), 'excluded CAM tag is rejected', $camEvaluation['failures']);

$hdrRequired = $profile;
$hdrRequired['hdr'] = 'require';
$hdrEvaluation = $quality->evaluate([
    'name' => 'Movie.2024.1080p.WEB-DL.VOSTFR.h264',
    'title' => 'Movie 2024 1080p WEB-DL VOSTFR h264 · 8.2 GB · 18 seeders',
    'filename' => 'Movie.2024.1080p.WEB-DL.VOSTFR.h264.mkv',
    'size' => '8.2 GB',
    'seeders' => 18,
], $hdrRequired);
check(!$hdrEvaluation['accepted'] && in_array('hdr_required', $hdrEvaluation['failures'], true), 'required HDR rejects SDR', $hdrEvaluation['failures']);

$ranked = $quality->rank([
    ['name' => 'Movie.2024.1080p.WEB-DL.VOSTFR.h264', 'title' => 'Movie 2024 1080p WEB-DL VOSTFR h264 · 8.2 GB · 18 seeders', 'filename' => 'Movie.2024.1080p.WEB-DL.VOSTFR.h264.mkv', 'size' => '8.2 GB', 'seeders' => 18, 'priority' => 20],
    $goodRelease,
], $profile);
check(count($ranked['accepted']) === 2 && $ranked['accepted'][0]['score'] >= $ranked['accepted'][1]['score'], 'scorer ranks best release first', array_map(fn ($item) => $item['score'], $ranked['accepted']));

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "Store and settings\n";
    $project = sys_get_temp_dir() . '/automation-store-' . bin2hex(random_bytes(4));
    mkdir($project, 0777, true);
    $store = new AutomationStore($project);
    $settings = new AutomationSettings($store);

    $settings->save(['sources' => [['id' => 'x']]]);
    eq([['id' => 'x']], $settings->get('sources'), 'sources can be set');
    $settings->save(['sources' => []]);
    eq([], $settings->get('sources'), 'sources can be cleared');
    $settings->save(['roots' => ['movies' => ['label' => 'Movies', 'path' => '/nas/Video/Movies', 'type' => 'movie', 'enabled' => true]]]);
    eq(['movies'], array_keys($settings->getRoots()), 'roots can be replaced');

    $entry = [
        'root_key' => 'movies',
        'path' => '/nas/Video/Movies/Test (2020)/Test.mkv',
        'relative_path' => 'Test (2020)/Test.mkv',
        'filename' => 'Test.mkv',
        'extension' => 'mkv',
        'media_type' => 'movie',
        'title' => 'Test',
        'title_normalized' => 'test',
        'year' => 2020,
        'season' => null,
        'episode' => null,
        'quality' => '1080p',
        'size' => 1000,
        'mtime' => 100,
        'media_key' => 'movie:test:2020',
        'tmdb_id' => null,
        'imdb_id' => null,
        'confidence' => 0.92,
        'parse_source' => 'filename',
        'parser_version' => LibraryNameParser::VERSION,
        'scan_id' => 1,
    ];
    eq('inserted', $store->upsertLibraryEntry($entry), 'first insert');
    eq('unchanged', $store->upsertLibraryEntry($entry), 'second scan unchanged');
    $entry['size'] = 2000;
    $entry['mtime'] = 200;
    eq('updated', $store->upsertLibraryEntry($entry), 'changed file updated');

    $store->getPdo()->exec("UPDATE library_entries SET tmdb_id = 42, media_key = 'movie:tmdb:42', resolved_at = '2026-01-01 00:00:00', resolution_source = 'test' WHERE path = '/nas/Video/Movies/Test (2020)/Test.mkv'");
    $entry['size'] = 3000;
    $entry['mtime'] = 300;
    $store->upsertLibraryEntry($entry);
    $stored = $store->getPdo()->query("SELECT tmdb_id, media_key, resolution_source FROM library_entries WHERE path = '/nas/Video/Movies/Test (2020)/Test.mkv'")->fetch();
    eq(42, (int) $stored['tmdb_id'], 'scan preserves enriched TMDB id');
    eq('movie:tmdb:42', $stored['media_key'], 'scan preserves enriched media key');
    eq('test', $stored['resolution_source'], 'scan preserves resolution source');


    $seriesEntry = static function (string $path, string $title, string $folder, int $episode, float $confidence, string $source, int $season = 1) use ($store): void {
        $store->upsertLibraryEntry([
            'root_key' => 'series',
            'path' => $path,
            'relative_path' => substr($path, strlen('/nas/Video/TV Shows/')),
            'filename' => basename($path),
            'extension' => 'mkv',
            'media_type' => 'series',
            'title' => $title,
            'title_normalized' => strtolower($title),
            'year' => null,
            'season' => $season,
            'episode' => $episode,
            'quality' => '1080p',
            'size' => 1000,
            'mtime' => 100,
            'media_key' => 'series:' . strtolower($title) . ':unknown:s' . str_pad((string) $season, 2, '0', STR_PAD_LEFT) . 'e' . str_pad((string) $episode, 2, '0', STR_PAD_LEFT),
            'tmdb_id' => null,
            'imdb_id' => null,
            'confidence' => $confidence,
            'parse_source' => $source,
            'parser_version' => LibraryNameParser::VERSION,
            'scan_id' => 1,
            'show_folder' => $folder,
        ]);
    };

    $seriesEntry('/nas/Video/TV Shows/The Sopranos/Season 01/A.mkv', 'The Sopranos', 'The Sopranos', 1, 0.78, 'filename');
    $seriesEntry('/nas/Video/TV Shows/The Sopranos/Season 01/B.mkv', 'The Sopranos', 'The Sopranos', 2, 0.78, 'filename');
    $seriesEntry('/nas/Video/TV Shows/Genre A/The Office/Season 01/A.mkv', 'The Office', 'Genre A/The Office', 1, 0.78, 'filename');
    $seriesEntry('/nas/Video/TV Shows/Genre B/The Office/Season 01/B.mkv', 'The Office', 'Genre B/The Office', 1, 0.78, 'filename');
    $seriesEntry('/nas/Video/TV Shows/Multi Show/Season 01/Multi.S01E01E02.mkv', 'Multi Show', 'Multi Show', 1, 0.6, 'multi_episode');

    eq(2, $store->reconcileSeriesAmbiguity(), 'unique series folder promotes no-year episodes');
    $sopranos = $store->getPdo()->query("SELECT confidence FROM library_entries WHERE title = 'The Sopranos'")->fetchAll();
    check(count($sopranos) === 2 && (float) $sopranos[0]['confidence'] >= 0.8, 'unique no-year series becomes confident', $sopranos);
    $office = $store->getPdo()->query("SELECT confidence FROM library_entries WHERE title = 'The Office'")->fetchAll();
    check(count($office) === 2 && (float) $office[0]['confidence'] < 0.8, 'colliding no-year series stays ambiguous', $office);
    $multi = $store->getPdo()->query("SELECT confidence FROM library_entries WHERE title = 'Multi Show'")->fetch();
    check((float) $multi['confidence'] <= 0.6, 'multi-episode file stays ambiguous', $multi);

    $seriesEntry('/nas/Video/TV Shows/Other Network/The Sopranos/Season 01/C.mkv', 'The Sopranos', 'Other Network/The Sopranos', 3, 0.78, 'filename');
    eq(2, $store->reconcileSeriesAmbiguity(), 'new colliding show demotes previously promoted episodes');
    $sopranos = $store->getPdo()->query("SELECT confidence FROM library_entries WHERE title = 'The Sopranos'")->fetchAll();
    check(count($sopranos) === 3 && (float) $sopranos[0]['confidence'] < 0.8, 'colliding no-year series is demoted', $sopranos);

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($project, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($project);
} else {
    echo "Store and settings\n  skip (pdo_sqlite not available)\n";
}


if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "Metadata resolver\n";
    $resolveProject = sys_get_temp_dir() . '/automation-resolve-' . bin2hex(random_bytes(4));
    mkdir($resolveProject, 0777, true);
    $resolveStore = new AutomationStore($resolveProject);
    $resolveSettings = new AutomationSettings($resolveStore);
    $resolveSettings->save(['metadata' => ['max_lookups_per_run' => 50, 'cache_positive_days' => 30, 'cache_negative_days' => 2]]);

    $makeSeries = static function (string $path, string $title, string $folder, int $episode, int $season = 1) use ($resolveStore): void {
        $resolveStore->upsertLibraryEntry([
            'root_key' => 'series',
            'path' => $path,
            'relative_path' => substr($path, strlen('/nas/Video/TV Shows/')),
            'filename' => basename($path),
            'extension' => 'mkv',
            'media_type' => 'series',
            'title' => $title,
            'title_normalized' => strtolower($title),
            'year' => null,
            'season' => $season,
            'episode' => $episode,
            'quality' => '1080p',
            'size' => 1000,
            'mtime' => 100,
            'media_key' => 'series:' . strtolower($title) . ':unknown:s' . str_pad((string) $season, 2, '0', STR_PAD_LEFT) . 'e' . str_pad((string) $episode, 2, '0', STR_PAD_LEFT),
            'tmdb_id' => null,
            'imdb_id' => null,
            'confidence' => 0.84,
            'parse_source' => 'filename',
            'parser_version' => LibraryNameParser::VERSION,
            'scan_id' => 1,
            'show_folder' => $folder,
        ]);
    };

    $makeSeries('/nas/Video/TV Shows/The Sopranos/Season 01/A.mkv', 'The Sopranos', 'The Sopranos', 1);
    $makeSeries('/nas/Video/TV Shows/The Sopranos/Season 01/B.mkv', 'The Sopranos', 'The Sopranos', 2);
    $makeSeries('/nas/Video/TV Shows/Oz/Season 04/Oz.S04E06.mkv', 'Oz', 'Oz', 6, 4);
    $makeSeries('/nas/Video/TV Shows/Rurouni Kenshin (2023)/Season 01/Rurouni.Kenshin.S01E01.mkv', 'Rurouni Kenshin', 'Rurouni Kenshin (2023)', 1);
    $resolveStore->getPdo()->exec("UPDATE library_entries SET year = 2023, media_key = 'series:rurouni kenshin:2023:s01e01' WHERE title = 'Rurouni Kenshin'");
    $makeSeries('/nas/Video/TV Shows/Genre A/The Office/Season 01/A.mkv', 'The Office', 'Genre A/The Office', 1);
    $makeSeries('/nas/Video/TV Shows/Genre B/The Office/Season 01/B.mkv', 'The Office', 'Genre B/The Office', 1);
    $resolveStore->getPdo()->exec("UPDATE library_entries SET confidence = 0.78 WHERE title = 'The Office'");

    $resolveStore->upsertLibraryEntry([
        'root_key' => 'movies',
        'path' => '/nas/Video/Movies/Dune Part Two (2024)/Dune.Part.Two.2024.mkv',
        'relative_path' => 'Dune Part Two (2024)/Dune.Part.Two.2024.mkv',
        'filename' => 'Dune.Part.Two.2024.mkv',
        'extension' => 'mkv',
        'media_type' => 'movie',
        'title' => 'Dune Part Two',
        'title_normalized' => 'dune part two',
        'year' => 2024,
        'season' => null,
        'episode' => null,
        'quality' => '2160p',
        'size' => 1000,
        'mtime' => 100,
        'media_key' => 'movie:dune part two:2024',
        'tmdb_id' => null,
        'imdb_id' => null,
        'confidence' => 0.92,
        'parse_source' => 'filename',
        'parser_version' => LibraryNameParser::VERSION,
        'scan_id' => 1,
        'show_folder' => null,
    ]);

    $resolveStore->upsertLibraryEntry([
        'root_key' => 'movies',
        'path' => '/nas/Video/Movies/Unknown Movie (2000)/Unknown.Movie.2000.mkv',
        'relative_path' => 'Unknown Movie (2000)/Unknown.Movie.2000.mkv',
        'filename' => 'Unknown.Movie.2000.mkv',
        'extension' => 'mkv',
        'media_type' => 'movie',
        'title' => 'Unknown Movie',
        'title_normalized' => 'unknown movie',
        'year' => 2000,
        'season' => null,
        'episode' => null,
        'quality' => '1080p',
        'size' => 1000,
        'mtime' => 100,
        'media_key' => 'movie:unknown movie:2000',
        'tmdb_id' => null,
        'imdb_id' => null,
        'confidence' => 0.92,
        'parse_source' => 'filename',
        'parser_version' => LibraryNameParser::VERSION,
        'scan_id' => 1,
        'show_folder' => null,
    ]);

    $resolveStore->upsertLibraryEntry([
        'root_key' => 'movies',
        'path' => '/nas/Video/Movies/Demon Slayer Infinity Castle (2025)/Demon.Slayer.Infinity.Castle.2025.mkv',
        'relative_path' => 'Demon Slayer Infinity Castle (2025)/Demon.Slayer.Infinity.Castle.2025.mkv',
        'filename' => 'Demon.Slayer.Infinity.Castle.2025.mkv',
        'extension' => 'mkv',
        'media_type' => 'movie',
        'title' => 'Demon Slayer Kimetsu no Yaiba The Movie Infinity Castle',
        'title_normalized' => 'demon slayer kimetsu no yaiba the movie infinity castle',
        'year' => 2025,
        'season' => null,
        'episode' => null,
        'quality' => '2160p',
        'size' => 1000,
        'mtime' => 100,
        'media_key' => 'movie:demon slayer kimetsu no yaiba the movie infinity castle:2025',
        'tmdb_id' => null,
        'imdb_id' => null,
        'confidence' => 0.92,
        'parse_source' => 'filename',
        'parser_version' => LibraryNameParser::VERSION,
        'scan_id' => 1,
        'show_folder' => null,
    ]);

    $fake = new FakeTmdbService();
    $resolver = new LibraryMetadataResolver($resolveStore, $resolveSettings, $fake, $resolveProject);
    $resolved = $resolver->resolve(50);
    check(!empty($resolved['success']), 'resolver runs', $resolved);
    check($resolved['stats']['groups_resolved'] >= 2, 'resolver resolves unique series and movie', $resolved['stats']);
    $resolutionHistory = $resolveStore->getRecentResolutions(5);
    check(!empty($resolutionHistory) && (int) $resolutionHistory[0]['groups_resolved'] >= 2, 'resolution run history is recorded', $resolutionHistory);

    $sopranos = $resolveStore->getPdo()->query("SELECT media_key, year, tmdb_id, imdb_id, confidence FROM library_entries WHERE title = 'The Sopranos' ORDER BY episode")->fetchAll();
    eq('series:tmdb:1396:s01e01', $sopranos[0]['media_key'], 'series TMDB key episode 1');
    eq('series:tmdb:1396:s01e02', $sopranos[1]['media_key'], 'series TMDB key episode 2');
    eq(1999, (int) $sopranos[0]['year'], 'series year resolved from TMDB');
    eq('tt0141842', $sopranos[0]['imdb_id'], 'series IMDb resolved from TMDB');
    check((float) $sopranos[0]['confidence'] >= 0.99, 'series confidence raised', $sopranos[0]);

    $oz = $resolveStore->getPdo()->query("SELECT media_key, year, tmdb_id, imdb_id FROM library_entries WHERE title = 'Oz'")->fetch();
    check($oz !== false, 'Oz entry exists');
    eq('series:tmdb:3322:s04e06', $oz['media_key'], 'ambiguous title resolved by season structure');
    eq('tt0118421', $oz['imdb_id'], 'season structure picks the correct IMDb id');

    $rurouni = $resolveStore->getPdo()->query("SELECT media_key, year, tmdb_id, imdb_id FROM library_entries WHERE title = 'Rurouni Kenshin'")->fetch();
    check($rurouni !== false, 'romaji entry exists');
    eq('series:tmdb:210879:s01e01', $rurouni['media_key'], 'en-US fallback resolves romaji anime title');
    eq('tt27882080', $rurouni['imdb_id'], 'en-US fallback resolves romaji IMDb id');

    $dune = $resolveStore->getPdo()->query("SELECT media_key, year, tmdb_id, imdb_id, confidence FROM library_entries WHERE title = 'Dune Part Two'")->fetch();
    eq('movie:tmdb:693134', $dune['media_key'], 'movie TMDB key');
    eq('tt15239678', $dune['imdb_id'], 'movie IMDb resolved from TMDB');
    eq(2024, (int) $dune['year'], 'movie year resolved from TMDB');

    $demon = $resolveStore->getPdo()->query("SELECT media_key, year, tmdb_id, imdb_id FROM library_entries WHERE title LIKE 'Demon Slayer%'")->fetch();
    check($demon !== false, 'demon slayer entry exists');
    eq('movie:tmdb:1311031', $demon['media_key'], 'simplified title matching resolves localized movie');
    eq('tt1311031', $demon['imdb_id'], 'simplified title matching resolves IMDb id');

    $office = $resolveStore->getPdo()->query("SELECT media_key, tmdb_id FROM library_entries WHERE title = 'The Office'")->fetchAll();
    check(count($office) === 2 && $office[0]['tmdb_id'] === null && $office[1]['tmdb_id'] === null, 'ambiguous show folders stay unresolved', $office);
    check(!in_array('The Office|series', $fake->searchQueries, true), 'ambiguous show is not sent to TMDB');

    $unknownBefore = count($fake->searchQueries);
    $second = $resolver->resolve(50);
    $unknownAfter = count($fake->searchQueries);
    check(!empty($second['success']), 'resolver runs a second time', $second);
    eq($unknownBefore, $unknownAfter, 'negative TMDB cache prevents a second lookup for the same title');

    $officeAttention = array_values(array_filter(
        $resolveStore->getAttentionGroups(50),
        static fn ($group) => $group['title'] === 'The Office'
    ));
    check(count($officeAttention) === 2, 'attention groups split by show folder', $officeAttention);

    $firstOffice = $officeAttention[0];
    eq(1, $resolveStore->ignoreGroup($firstOffice, true), 'ignore group updates matching entries');
    $afterIgnore = array_values(array_filter(
        $resolveStore->getAttentionGroups(50),
        static fn ($group) => $group['title'] === 'The Office'
    ));
    check(count($afterIgnore) === 1, 'ignored group leaves needs attention', $afterIgnore);
    $ignoredOffice = array_values(array_filter(
        $resolveStore->getIgnoredGroups(50),
        static fn ($group) => $group['title'] === 'The Office'
    ));
    check(count($ignoredOffice) === 1, 'ignored group appears in ignored list', $ignoredOffice);

    eq(1, $resolveStore->ignoreGroup($firstOffice, false), 'restore group updates matching entries');
    $afterRestore = array_values(array_filter(
        $resolveStore->getAttentionGroups(50),
        static fn ($group) => $group['title'] === 'The Office'
    ));
    check(count($afterRestore) === 2, 'restored group returns to needs attention', $afterRestore);

    $manualGroup = $afterRestore[0];
    $manual = $resolver->resolveGroupManually($manualGroup, 2316, 'series');
    check(!empty($manual['success']) && ($manual['entries'] ?? 0) === 1, 'manual resolution updates one show folder', $manual);
    $manualEntry = $resolveStore->getPdo()->prepare("SELECT tmdb_id, media_key, year, resolution_source FROM library_entries WHERE title = 'The Office' AND show_folder = :folder");
    $manualEntry->execute([':folder' => $manualGroup['show_folder']]);
    $manualRow = $manualEntry->fetch();
    eq(2316, (int) $manualRow['tmdb_id'], 'manual resolution sets TMDB id');
    eq('series:tmdb:2316:s01e01', $manualRow['media_key'], 'manual resolution sets TMDB media key');
    eq('manual', $manualRow['resolution_source'], 'manual resolution is labelled manual');
    $otherOffice = $resolveStore->getPdo()->prepare("SELECT tmdb_id FROM library_entries WHERE title = 'The Office' AND show_folder != :folder");
    $otherOffice->execute([':folder' => $manualGroup['show_folder']]);
    check($otherOffice->fetchAll() === [['tmdb_id' => null]], 'other show folder stays unresolved', $otherOffice->fetchAll());

    $makeSeries('/nas/Video/TV Shows/Bulk Show/Season 01/One.mkv', 'Bulk One', 'Bulk Show', 1);
    $makeSeries('/nas/Video/TV Shows/Bulk Show/Season 01/Two.mkv', 'Bulk Two', 'Bulk Show', 2);
    $makeSeries('/nas/Video/TV Shows/Bulk Show/Season 01/Three.mkv', 'Bulk Three', 'Bulk Show', 3);
    $makeSeries('/nas/Video/TV Shows/Other Show/Season 01/Other.mkv', 'Other Show', 'Other Show', 1);
    $bulk = $resolver->resolveShowFolderManually(['media_type' => 'series', 'show_folder' => 'Bulk Show'], 3322, 'series');
    check(!empty($bulk['success']) && ($bulk['entries'] ?? 0) === 3, 'show-folder resolution updates every episode', $bulk);
    $bulkRows = $resolveStore->getPdo()->query("SELECT tmdb_id, year, imdb_id, media_key FROM library_entries WHERE show_folder = 'Bulk Show' ORDER BY episode")->fetchAll();
    check(count($bulkRows) === 3, 'bulk show has three episodes');
    foreach ($bulkRows as $index => $row) {
        eq(3322, (int) $row['tmdb_id'], 'bulk episode TMDB id ' . ($index + 1));
        eq(1997, (int) $row['year'], 'bulk episode year ' . ($index + 1));
        eq('tt0118421', $row['imdb_id'], 'bulk episode IMDb ' . ($index + 1));
        eq('series:tmdb:3322:s01e' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), $row['media_key'], 'bulk episode key ' . ($index + 1));
    }
    $other = $resolveStore->getPdo()->query("SELECT tmdb_id FROM library_entries WHERE show_folder = 'Other Show'")->fetch();
    check($other !== false && $other['tmdb_id'] === null, 'other show folder is untouched');

    $makeSeries('/nas/Video/TV Shows/Missing Show/Season 01/Missing.mkv', 'Missing Show', 'Missing Show', 1);
    $resolveStore->getPdo()->exec("UPDATE library_entries SET status = 'missing' WHERE show_folder = 'Missing Show'");
    $missing = $resolver->resolveShowFolderManually(['media_type' => 'series', 'show_folder' => 'Missing Show'], 3322, 'series');
    check(!empty($missing['success']) && ($missing['entries'] ?? 0) === 1, 'manual resolution accepts missing entries', $missing);
    $missingRow = $resolveStore->getPdo()->query("SELECT tmdb_id, status, media_key FROM library_entries WHERE show_folder = 'Missing Show'")->fetch();
    eq(3322, (int) $missingRow['tmdb_id'], 'missing entry TMDB id updated');
    eq('missing', $missingRow['status'], 'missing status is preserved');
    eq('series:tmdb:3322:s01e01', $missingRow['media_key'], 'missing entry media key updated');

    $missingId = (int) $resolveStore->getPdo()->query("SELECT id FROM library_entries WHERE show_folder = 'Missing Show'")->fetchColumn();
    check($resolveStore->deleteMissingEntry($missingId), 'delete one missing entry');
    check($resolveStore->getPdo()->query('SELECT COUNT(*) FROM library_entries WHERE id = ' . $missingId)->fetchColumn() == 0, 'deleted missing entry is gone');
    $makeSeries('/nas/Video/TV Shows/Purge Show/Season 01/Purge.mkv', 'Purge Show', 'Purge Show', 1);
    $resolveStore->getPdo()->exec("UPDATE library_entries SET status = 'missing' WHERE show_folder = 'Purge Show'");
    eq(1, $resolveStore->purgeMissingEntries(), 'purge missing removes remaining missing entries');

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolveProject, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($resolveProject);
} else {
    echo "Metadata resolver\n  skip (pdo_sqlite not available)\n";
}


if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "Rename service\n";
    $renameProject = sys_get_temp_dir() . '/automation-rename-' . bin2hex(random_bytes(4));
    $renameRoot = $renameProject . '/nas/Video';
    mkdir($renameRoot . '/Movies/Wrong Folder', 0777, true);
    mkdir($renameRoot . '/TV Shows/Demo Show/Season 01', 0777, true);
    file_put_contents($renameRoot . '/Movies/Wrong Folder/Wrong.Movie.2020.mkv', 'video');
    file_put_contents($renameRoot . '/Movies/Wrong Folder/Wrong.Movie.2020.en.srt', 'subs');
    file_put_contents($renameRoot . '/Movies/Wrong Folder/movie.nfo', 'nfo');
    file_put_contents($renameRoot . '/TV Shows/Demo Show/Season 01/Demo.Show.S01E01.mkv', 'video');
    file_put_contents($renameRoot . '/TV Shows/Demo Show/Season 01/Demo.Show.S01E01.en.srt', 'subs');

    $renameStore = new AutomationStore($renameProject);
    $renameSettings = new AutomationSettings($renameStore);
    $renameSettings->save([
        'roots' => [
            'movies' => ['label' => 'Movies', 'path' => $renameRoot . '/Movies', 'type' => 'movie', 'enabled' => true],
            'series' => ['label' => 'TV Shows', 'path' => $renameRoot . '/TV Shows', 'type' => 'series', 'enabled' => true],
            'anime' => ['label' => 'Anime', 'path' => $renameRoot . '/Anime', 'type' => 'anime', 'enabled' => false],
        ],
    ]);

    $renameStore->upsertLibraryEntry([
        'root_key' => 'movies',
        'path' => $renameRoot . '/Movies/Wrong Folder/Wrong.Movie.2020.mkv',
        'relative_path' => 'Wrong Folder/Wrong.Movie.2020.mkv',
        'filename' => 'Wrong.Movie.2020.mkv',
        'extension' => 'mkv',
        'media_type' => 'movie',
        'title' => 'The Movie',
        'title_normalized' => 'the movie',
        'year' => 2020,
        'season' => null,
        'episode' => null,
        'quality' => '1080p',
        'size' => 5,
        'mtime' => 100,
        'media_key' => 'movie:tmdb:5000',
        'tmdb_id' => 5000,
        'imdb_id' => 'tt5000000',
        'confidence' => 0.99,
        'parse_source' => 'filename',
        'parser_version' => LibraryNameParser::VERSION,
        'scan_id' => 1,
        'show_folder' => null,
    ]);
    $renameStore->upsertLibraryEntry([
        'root_key' => 'series',
        'path' => $renameRoot . '/TV Shows/Demo Show/Season 01/Demo.Show.S01E01.mkv',
        'relative_path' => 'Demo Show/Season 01/Demo.Show.S01E01.mkv',
        'filename' => 'Demo.Show.S01E01.mkv',
        'extension' => 'mkv',
        'media_type' => 'series',
        'title' => 'Demo Show',
        'title_normalized' => 'demo show',
        'year' => 2020,
        'season' => 1,
        'episode' => 1,
        'quality' => '1080p',
        'size' => 5,
        'mtime' => 100,
        'media_key' => 'series:tmdb:777:s01e01',
        'tmdb_id' => 777,
        'imdb_id' => 'tt7777777',
        'confidence' => 0.99,
        'parse_source' => 'filename',
        'parser_version' => LibraryNameParser::VERSION,
        'scan_id' => 1,
        'show_folder' => 'Demo Show',
    ]);

    $renameService = new LibraryRenameService($renameStore, $renameSettings, $fake);
    $plan = $renameService->preview([], 50);
    $movieItem = null;
    $seriesItem = null;
    foreach ($plan['items'] as $item) {
        if (($item['entry']['title'] ?? '') === 'The Movie') $movieItem = $item;
        if (($item['entry']['title'] ?? '') === 'Demo Show') $seriesItem = $item;
    }
    check($movieItem !== null && $movieItem['status'] === 'ok', 'movie rename plan is ready', $movieItem);
    check($seriesItem !== null && $seriesItem['status'] === 'ok', 'series rename plan is ready', $seriesItem);
    eq($renameRoot . '/Movies/The Movie (2020)/The Movie (2020).mkv', $movieItem['target'], 'movie target follows convention');
    eq($renameRoot . '/TV Shows/Demo Show (2020)/Season 01/Demo Show (2020) - S01E01.mkv', $seriesItem['target'], 'series target follows convention');
    eq(2, count($movieItem['sidecars']), 'movie sidecars planned');

    $apply = $renameService->apply([], 50);
    check(!empty($apply['success']) && ($apply['stats']['entries_renamed'] ?? 0) === 2, 'rename applies to movie and series', $apply);
    check(is_file($renameRoot . '/Movies/The Movie (2020)/The Movie (2020).mkv'), 'movie file moved');
    check(is_file($renameRoot . '/Movies/The Movie (2020)/movie.nfo'), 'movie.nfo moved');
    check(is_file($renameRoot . '/Movies/The Movie (2020)/The Movie (2020).en.srt'), 'movie sidecar renamed');
    check(!is_file($renameRoot . '/Movies/Wrong Folder/Wrong.Movie.2020.mkv'), 'old movie file removed');
    check(is_file($renameRoot . '/TV Shows/Demo Show (2020)/Season 01/Demo Show (2020) - S01E01.mkv'), 'series file moved');
    check(is_file($renameRoot . '/TV Shows/Demo Show (2020)/Season 01/Demo Show (2020) - S01E01.en.srt'), 'series sidecar renamed');

    $moved = $renameStore->getPdo()->query("SELECT path, show_folder FROM library_entries WHERE title = 'Demo Show'")->fetch();
    eq($renameRoot . '/TV Shows/Demo Show (2020)/Season 01/Demo Show (2020) - S01E01.mkv', $moved['path'], 'DB path updated after rename');
    eq('Demo Show (2020)', $moved['show_folder'], 'DB show folder updated after rename');

    $renameHistory = $renameStore->getRecentRenameRuns(5);
    check(!empty($renameHistory) && (int) $renameHistory[0]['entries_renamed'] === 2, 'rename run history recorded', $renameHistory);

    $conformPlan = $renameService->preview([], 50);
    $conformCount = 0;
    foreach ($conformPlan['items'] as $item) {
        if (($item['status'] ?? '') === 'conform') {
            $conformCount++;
        }
    }
    eq(2, $conformCount, 'renamed files are detected as conform');

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($renameProject, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($renameProject);
} else {
    echo "Rename service\n  skip (pdo_sqlite not available)\n";
}

echo "List clients\n";
$scrobClient = new ScrobListClient();
$scrobItems = $scrobClient->normalizePayload([
    'name' => 'Watchlist',
    'items' => [
        ['id' => 10, 'media' => ['type' => 'movie', 'tmdb_id' => 603, 'imdb_id' => 'tt0133093', 'title' => 'The Matrix', 'release_date' => '1999-03-31']],
        ['id' => 11, 'media' => ['type' => 'series', 'tmdb_id' => 1399, 'title' => 'Game of Thrones', 'release_date' => '2011-04-17']],
        ['id' => 12, 'media' => ['type' => 'episode', 'tmdb_id' => 63056, 'show_tmdb_id' => 1399, 'show_title' => 'Game of Thrones', 'season_number' => 1, 'episode_number' => 2, 'release_date' => '2011-04-24']],
        ['id' => 13, 'media' => ['type' => 'person', 'title' => 'Someone']],
    ],
], '7');
eq(3, count($scrobItems), 'Scrob normalization skips people and keeps movies/series/episodes');
eq('movie', $scrobItems[0]['media_type'], 'Scrob movie type');
eq(1399, $scrobItems[2]['tmdb_id'], 'Scrob episode uses the show TMDB id');
eq(1, $scrobItems[2]['season'], 'Scrob episode season');
eq(2, $scrobItems[2]['episode'], 'Scrob episode number');

$mdbClient = new MdbListClient();
$mdbItems = $mdbClient->normalizePayload([
    'movies' => [[
        'id' => 917496,
        'title' => 'Beetlejuice Beetlejuice',
        'release_year' => 2024,
        'mediatype' => 'movie',
        'ids' => ['tmdb' => 917496, 'imdb' => 'tt2049403'],
    ]],
    'shows' => [[
        'id' => 258902,
        'title' => 'English Teacher',
        'release_year' => 2024,
        'mediatype' => 'show',
        'ids' => ['tmdb' => 258902, 'imdb' => 'tt20782190'],
    ]],
], 'alice/favourites');
eq(2, count($mdbItems), 'MDBList normalization returns movies and shows');
eq('movie', $mdbItems[0]['media_type'], 'MDBList movie type');
eq(917496, $mdbItems[0]['tmdb_id'], 'MDBList movie TMDB id');
eq('series', $mdbItems[1]['media_type'], 'MDBList show becomes a series');
eq('English Teacher', $mdbItems[1]['title'], 'MDBList show title');

$mdbFlat = $mdbClient->normalizePayload([
    ['id' => 1, 'mediatype' => 'movie', 'title' => 'Flat Movie', 'ids' => ['tmdb' => 42]],
    ['id' => 2, 'mediatype' => 'show', 'title' => 'Flat Show', 'ids' => ['tmdb' => 43]],
], '99');
eq(2, count($mdbFlat), 'MDBList unified flat array is normalized');
eq('series', $mdbFlat[1]['media_type'], 'MDBList flat show becomes a series');

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "List ingestion and dry-run matching\n";
    $listProject = sys_get_temp_dir() . '/automation-lists-' . bin2hex(random_bytes(4));
    mkdir($listProject, 0777, true);
    $listStore = new AutomationStore($listProject);
    $listSettings = new AutomationSettings($listStore);
    $listSettings->save([
        'sources' => [
            ['id' => 'scrob', 'provider' => 'scrob', 'label' => 'Scrob', 'enabled' => true, 'config' => ['base_url' => 'http://scrob:7330', 'api_key' => 'test', 'list_ids' => ['1']]],
            ['id' => 'mdblist', 'provider' => 'mdblist', 'label' => 'MDBList', 'enabled' => false, 'config' => ['api_key' => '', 'list_ids' => []]],
        ],
    ]);

    $listEntry = static function (array $data) use ($listStore): void {
        $listStore->upsertLibraryEntry(array_merge([
            'root_key' => 'series',
            'relative_path' => ltrim(substr($data['path'], strlen('/nas/Video/TV Shows/')), '/'),
            'filename' => basename($data['path']),
            'extension' => 'mkv',
            'year' => null,
            'season' => 1,
            'episode' => 1,
            'quality' => '1080p',
            'size' => 1000,
            'mtime' => 100,
            'tmdb_id' => null,
            'imdb_id' => null,
            'confidence' => 0.99,
            'parse_source' => 'filename',
            'parser_version' => LibraryNameParser::VERSION,
            'scan_id' => 1,
            'show_folder' => null,
            'ignored_at' => null,
        ], $data));
    };

    $listEntry([
        'root_key' => 'movies',
        'path' => '/nas/Video/Movies/Dune Part Two (2024)/Dune.Part.Two.2024.mkv',
        'relative_path' => 'Dune Part Two (2024)/Dune.Part.Two.2024.mkv',
        'media_type' => 'movie',
        'title' => 'Dune Part Two',
        'title_normalized' => 'dune part two',
        'year' => 2024,
        'season' => null,
        'episode' => null,
        'media_key' => 'movie:tmdb:693134',
        'tmdb_id' => 693134,
        'imdb_id' => 'tt15239678',
    ]);
    $listEntry([
        'path' => '/nas/Video/TV Shows/The Sopranos/Season 01/The.Sopranos.S01E01.mkv',
        'media_type' => 'series',
        'title' => 'The Sopranos',
        'title_normalized' => 'the sopranos',
        'year' => 1999,
        'media_key' => 'series:tmdb:1396:s01e01',
        'tmdb_id' => 1396,
        'imdb_id' => 'tt0141842',
        'show_folder' => 'The Sopranos',
    ]);
    $listEntry([
        'path' => '/nas/Video/TV Shows/Oz/Season 01/Oz.S01E01.mkv',
        'media_type' => 'series',
        'title' => 'Oz',
        'title_normalized' => 'oz',
        'year' => 1997,
        'media_key' => 'series:tmdb:3322:s01e01',
        'tmdb_id' => 3322,
        'imdb_id' => 'tt0118421',
        'show_folder' => 'Oz',
    ]);
    $listStore->getPdo()->exec("UPDATE library_entries SET ignored_at = '2026-01-01 00:00:00' WHERE title = 'Oz'");
    $listEntry([
        'path' => '/nas/Video/TV Shows/Twin Peaks A/Season 01/Twin.Peaks.S01E01.mkv',
        'media_type' => 'series',
        'title' => 'Twin Peaks',
        'title_normalized' => 'twin peaks',
        'confidence' => 0.84,
        'media_key' => 'series:twin peaks:unknown:s01e01',
        'show_folder' => 'Twin Peaks A',
    ]);
    $listEntry([
        'path' => '/nas/Video/TV Shows/Twin Peaks B/Season 01/Twin.Peaks.S01E01.mkv',
        'media_type' => 'series',
        'title' => 'Twin Peaks',
        'title_normalized' => 'twin peaks',
        'confidence' => 0.84,
        'media_key' => 'series:twin peaks:unknown:s01e01',
        'show_folder' => 'Twin Peaks B',
    ]);

    $registry = new ListSourceRegistry(new ScrobListClient(), new MdbListClient());
    $fakeScrob = new FakeListSourceClient('scrob', [
        ['list_ref' => '1', 'remote_id' => '1', 'media_type' => 'movie', 'tmdb_id' => 693134, 'imdb_id' => 'tt15239678', 'title' => 'Dune Part Two', 'year' => 2024, 'season' => null, 'episode' => null, 'raw' => []],
        ['list_ref' => '1', 'remote_id' => '2', 'media_type' => 'series', 'tmdb_id' => 1396, 'imdb_id' => 'tt0141842', 'title' => 'The Sopranos', 'year' => 1999, 'season' => null, 'episode' => null, 'raw' => []],
        ['list_ref' => '1', 'remote_id' => '3', 'media_type' => 'series', 'tmdb_id' => 3322, 'imdb_id' => 'tt0118421', 'title' => 'Oz', 'year' => 1997, 'season' => null, 'episode' => null, 'raw' => []],
        ['list_ref' => '1', 'remote_id' => '4', 'media_type' => 'series', 'tmdb_id' => null, 'imdb_id' => null, 'title' => 'Twin Peaks', 'year' => null, 'season' => null, 'episode' => null, 'raw' => []],
        ['list_ref' => '1', 'remote_id' => '5', 'media_type' => 'movie', 'tmdb_id' => 999999, 'imdb_id' => null, 'title' => 'Missing Movie', 'year' => 2001, 'season' => null, 'episode' => null, 'raw' => []],
    ]);
    $registry->register($fakeScrob);

    $listMatcher = new App\Service\Automation\ListMatcher($listStore, $listSettings, $fake);
    $listSync = new ListSyncService($listStore, $listSettings, $listMatcher, $registry, $listProject);

    $firstSync = $listSync->sync(['scrob']);
    check(!empty($firstSync['success']), 'list sync runs', $firstSync);
    eq(5, $firstSync['stats']['items_seen'], 'list sync sees five items');
    eq(1, $firstSync['stats']['present'], 'present movie is detected');
    eq(2, $firstSync['stats']['missing'], 'partial series and missing movie are reported missing');
    eq(1, $firstSync['stats']['ambiguous'], 'colliding series title is ambiguous');
    eq(1, $firstSync['stats']['ignored'], 'ignored library group is reported ignored');

    $listRows = $listStore->getPdo()->query("SELECT title, match_status, match_reason, episodes_total, episodes_present, episodes_missing FROM list_items ORDER BY title")->fetchAll();
    $byTitle = [];
    foreach ($listRows as $row) {
        $byTitle[$row['title']] = $row;
    }
    eq('present', $byTitle['Dune Part Two']['match_status'], 'Dune list item is present');
    eq('missing', $byTitle['The Sopranos']['match_status'], 'Sopranos list item is missing an episode');
    eq(2, (int) $byTitle['The Sopranos']['episodes_total'], 'future Sopranos episode is excluded');
    eq(1, (int) $byTitle['The Sopranos']['episodes_present'], 'one Sopranos episode is present');
    eq(1, (int) $byTitle['The Sopranos']['episodes_missing'], 'one Sopranos episode is missing');
    eq('ignored', $byTitle['Oz']['match_status'], 'Oz list item is ignored');
    eq('ambiguous', $byTitle['Twin Peaks']['match_status'], 'Twin Peaks list item is ambiguous');
    eq('missing', $byTitle['Missing Movie']['match_status'], 'unknown movie is missing');

    $run = $listStore->getLastListRun('scrob');
    check($run !== null && $run['status'] === 'success', 'list run is recorded', $run);
    eq(5, (int) $run['items_seen'], 'list run stores item count');

    $fakeScrob->items = [$fakeScrob->items[0]];
    $secondSync = $listSync->sync(['scrob']);
    check(!empty($secondSync['success']), 'second list sync runs', $secondSync);
    $secondRun = $listStore->getLastListRun('scrob');
    eq(4, (int) ($secondRun['items_removed'] ?? 0), 'items no longer in the list are marked removed');
    eq(1, $listStore->getListStatusCounts()['all'], 'only the remaining list item stays active');

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($listProject, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($listProject);
} else {
    echo "List ingestion and dry-run matching\n  skip (pdo_sqlite not available)\n";
}

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "Intent store\n";
    $intentProject = sys_get_temp_dir() . '/automation-intents-' . bin2hex(random_bytes(4));
    mkdir($intentProject, 0777, true);
    $intentStore = new AutomationStore($intentProject);

    $created = $intentStore->createIntent([
        'source_id' => 'scrob',
        'source_list_id' => '1',
        'media_key' => 'movie:tmdb:693134',
        'media_type' => 'movie',
        'tmdb_id' => 693134,
        'imdb_id' => 'tt15239678',
        'title' => 'Dune Part Two',
        'year' => 2024,
    ]);
    eq('inserted', $created['status'], 'intent is inserted');
    $duplicate = $intentStore->createIntent([
        'source_id' => 'scrob',
        'media_key' => 'movie:title:dune part two:2024',
        'media_type' => 'movie',
        'tmdb_id' => 693134,
        'title' => 'Dune Part Two',
        'year' => 2024,
    ]);
    eq('existing', $duplicate['status'], 'duplicate intent is detected by TMDB or title');
    eq(1, $intentStore->countIntents(), 'only one intent is stored');

    eq(1, count($intentStore->getPendingIntents(10)), 'new intent is pending and due');
    $intentStore->updateIntent((int) $created['intent']['id'], ['status' => 'no_source', 'error_message' => 'none', 'next_attempt_at' => date('Y-m-d H:i:s')]);
    eq(1, count($intentStore->getPendingIntents(10)), 'no_source intent is retried when due');
    $intentStore->updateIntent((int) $created['intent']['id'], ['status' => 'resolving']);
    $intentStore->getPdo()->exec("UPDATE intents SET updated_at = '2020-01-01 00:00:00' WHERE id = " . (int) $created['intent']['id']);
    eq(1, $intentStore->markStaleResolvingIntents(30), 'stale resolving intent is reset');
    $resetIntent = $intentStore->getIntent((int) $created['intent']['id']);
    eq('pending', $resetIntent['status'], 'stale resolving intent becomes pending');
    $counts = $intentStore->getIntentStatusCounts();
    eq(1, $counts['pending'], 'intent status counts are available');

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($intentProject, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($intentProject);
} else {
    echo "Intent store\n  skip (pdo_sqlite not available)\n";
}

echo "\n$passes passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
