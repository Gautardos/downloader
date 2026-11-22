<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\JsonStorage;
use App\Service\MediaTypeHelper;
use App\Service\NavigationService;
use App\Service\Statistics\StorageUsageProviderInterface;
use App\Service\StatisticsService;
use Symfony\Component\Process\Process;

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

function checkClose(float $expected, float $actual, string $label, float $tolerance = 0.01): void
{
    check(abs($expected - $actual) <= $tolerance, $label . ' => ' . json_encode($expected) . ' +/- ' . $tolerance, $actual);
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

function entry(
    string $filename,
    string $type,
    string $status,
    string $date,
    string $path,
    mixed $size,
    mixed $speed = null,
    mixed $duration = null,
    ?int $actualSize = null
): array {
    $item = [
        'download_id' => bin2hex(random_bytes(4)),
        'filename' => $filename,
        'type' => $type,
        'status' => $status,
        'date' => $date,
        'path' => $path,
        'size' => $size,
        'speed' => $speed,
        'duration' => $duration,
    ];
    if ($actualSize !== null) {
        $item['actual_size'] = $actualSize;
    }

    return $item;
}

function indexBy(array $rows, string $key = 'key'): array
{
    $indexed = [];
    foreach ($rows as $row) {
        $indexed[$row[$key]] = $row;
    }

    return $indexed;
}

function csvRows(string $csv): array
{
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $csv);
    rewind($handle);
    $rows = [];
    while (($row = fgetcsv($handle, 1000000, ',', '"', '')) !== false) {
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
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

function findItem(array $groups, string $key): ?array
{
    foreach ($groups as $group) {
        foreach ($group['items'] as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }
    }

    return null;
}

final class FakeStorageUsageProvider implements StorageUsageProviderInterface
{
    public function __construct(
        private array $usages = [],
        private array $errors = []
    ) {
    }

    public function getUsage(string $path): ?array
    {
        if (in_array($path, $this->errors, true)) {
            throw new RuntimeException('Storage usage failure for ' . $path);
        }

        return $this->usages[$path] ?? null;
    }
}

$sandbox = tempDir('statistics-smoke');
$now = new DateTimeImmutable('2026-06-15 12:00:00');
$gib = 1024 ** 3;
$mib = 1024 ** 2;

$size1080 = 4 * $gib;
$size2160 = (int) round(1.2 * $gib);
$sizeAudioWarning = 123456789;
$sizeCanceled = 500000000;
$sizeAudioSuccess = (int) round(250 * $mib);
$sizeOld = 1000000000;

try {
    $config = [
        'default_path' => '/fake/downloads',
        'music_root_path' => '/fake/music',
        'music_library_path' => '/fake/library',
    ];
    $provider = new FakeStorageUsageProvider([
        '/fake/downloads' => ['total' => 1000000000000, 'free' => 400000000000],
        '/fake/music' => ['total' => 2000000000000, 'free' => 1500000000000],
        '/fake/library' => null,
    ]);
    $storage = storageAt($sandbox . '/main', $config);
    $history = [
        entry('Dune.Part.Two.2024.1080p.mkv', 'video', 'success', '2026-06-14 10:00:00', '/downloads/Movies', $size1080, '13.49 MB/s', 120, $size1080),
        entry('Severance.S02E06.2160p.mkv', 'video', 'success', '2026-06-10 09:30:00', '/downloads/TV Shows', '1.2 GB', '20 MB/s', '00:05:00'),
        entry('Kendrick.GNX.flac', 'audio', 'warning', '2026-06-01 08:00:00', '/downloads/Music', $sizeAudioWarning, null, 45),
        entry('Broken.Movie.720p.mkv', 'video', 'error', '2026-05-20 07:00:00', '/downloads/Movies', 'not-a-size', '0 B/s', null),
        entry('Archive.Pack.zip', 'archive', 'canceled', '2026-06-12 06:00:00', '/downloads/Archives', $sizeCanceled, '5.5 MB/s', 10),
        entry('Another.Song.mp3', 'audio', 'success', '2026-05-18 05:00:00', '/downloads/Music', '250 MB', '2 MB/s', 30),
        entry('Old.Movie.720p.mkv', 'video', 'success', '2026-01-01 00:00:00', '/downloads/Movies', $sizeOld, '1 MB/s', 60),
    ];
    $storage->set('history', $history);
    $service = new StatisticsService($storage, new MediaTypeHelper(), $provider, $now);

    $expected30Bytes = $size1080 + $size2160 + $sizeAudioWarning + $sizeCanceled + $sizeAudioSuccess;
    $expected30Duration = 120 + 300 + 45 + 10 + 30;
    $expectedAverageSpeed = ((13.49 + 20.0 + 5.5 + 2.0) / 4) * $mib;

    echo "Parsing helpers\n";
    eq(123456789, $service->parseSize(123456789), 'integer size is preserved');
    eq($size2160, $service->parseSize('1.2 GB'), 'size string with GB unit is parsed');
    eq((int) round(1.5 * $mib), $service->parseSize('1,5 MB'), 'size string with decimal comma is parsed');
    eq(0, $service->parseSize('not-a-size'), 'invalid size parses to zero');
    checkClose(13.49 * $mib, $service->parseSpeed('13.49 MB/s'), 'speed string with MB/s is parsed');
    checkClose(1536.0, $service->parseSpeed('1.5 KB/s'), 'speed string with KB/s is parsed');
    checkClose(0.0, $service->parseSpeed('fast'), 'invalid speed parses to zero');
    eq(125, $service->parseDuration('2m 5s'), 'duration with units is parsed');
    eq(3723, $service->parseDuration('01:02:03'), 'duration with clock format is parsed');

    echo "Summary for the default 30-day period\n";
    $stats = $service->getStatistics('30d');
    $summary = $stats['summary'];
    eq('30d', $stats['period'], 'default 30d period is selected');
    eq(6, $summary['items'], '30d item count excludes the old entry');
    eq(3, $summary['success'], '30d success count');
    eq(1, $summary['warning'], '30d warning count');
    eq(1, $summary['error'], '30d error count');
    eq(1, $summary['canceled'], '30d canceled count');
    eq(0, $summary['failed_requeued'], '30d failed_requeued count');
    eq(0, $summary['other'], '30d unknown status count');
    checkClose(0.5, $summary['success_rate'], '30d success rate is success / total', 1e-9);
    eq(50.0, $summary['success_rate_percent'], '30d success rate percent');
    eq($expected30Bytes, $summary['total_bytes'], '30d total bytes include parsed string sizes');
    checkClose($expectedAverageSpeed, $summary['average_speed'], '30d average speed ignores missing and zero speeds');
    eq($expected30Duration, $summary['total_duration'], '30d total duration');
    checkClose($expected30Bytes / 6, $summary['average_size'], '30d average size');

    echo "Period filtering\n";
    eq(3, $service->getStatistics('7d')['summary']['items'], '7d keeps three recent entries');
    eq(6, $service->getStatistics('90d')['summary']['items'], '90d excludes the old entry');
    eq(7, $service->getStatistics('all')['summary']['items'], 'all includes every entry');
    $fallback = $service->getStatistics('quarter');
    eq('30d', $fallback['period'], 'unknown period falls back to 30d');
    eq(6, $fallback['summary']['items'], 'fallback period uses the 30d window');
    eq('30d', $service->normalizePeriod(null), 'null period falls back to 30d');
    eq('30d', $service->normalizePeriod(''), 'empty period falls back to 30d');

    echo "Daily series\n";
    $daily7 = $service->getStatistics('7d')['daily'];
    eq(7, count($daily7), '7d daily series has seven buckets');
    eq('2026-06-09', $daily7[0]['date'], '7d series starts six days before now');
    eq('2026-06-15', $daily7[6]['date'], '7d series ends today');
    eq(0, $daily7[2]['items'], 'empty days are included with zero items');
    eq(1, $daily7[5]['items'], 'today-minus-one bucket has one item');
    eq($size1080, $daily7[5]['bytes'], 'daily bucket bytes are summed');
    eq(3, array_sum(array_column($daily7, 'items')), '7d daily items match the filtered summary');

    $stats30 = $service->getStatistics('30d');
    eq(30, count($stats30['daily']), '30d daily series has thirty buckets');
    eq('2026-05-17', $stats30['daily'][0]['date'], '30d series starts twenty-nine days before now');
    eq(6, array_sum(array_column($stats30['daily'], 'items')), '30d daily items match the summary');
    eq($expected30Bytes, array_sum(array_column($stats30['daily'], 'bytes')), '30d daily bytes match the summary');
    eq(90, count($service->getStatistics('90d')['daily']), '90d daily series has ninety buckets');

    $statsAll = $service->getStatistics('all');
    eq(166, count($statsAll['daily']), 'all-time daily series starts at the oldest entry');
    eq('2026-01-01', $statsAll['daily'][0]['date'], 'all-time series first day');
    eq('2026-06-15', $statsAll['daily'][165]['date'], 'all-time series last day');
    eq(7, array_sum(array_column($statsAll['daily'], 'items')), 'all-time daily items are summed');

    echo "Breakdowns\n";
    $types = indexBy($stats30['types']);
    eq(3, $types['video']['items'], 'video type count');
    eq(2, $types['audio']['items'], 'audio type count');
    eq(1, $types['archive']['items'], 'archive type count');
    eq(0, $types['ebook']['items'], 'ebook type count');
    eq(0, $types['image']['items'], 'image type count');
    eq(0, $types['other']['items'], 'other type count');
    eq($size1080 + $size2160, $types['video']['bytes'], 'video type bytes');

    $categories = indexBy($stats30['categories']);
    eq(2, $categories['movies']['items'], 'Movies category estimated from path');
    eq(1, $categories['tv']['items'], 'TV Shows category estimated from path');
    eq(2, $categories['music']['items'], 'Music category estimated from type');
    eq(1, $categories['other']['items'], 'Other category estimated for archives');

    $quality = indexBy($stats30['quality']);
    eq(1, $quality['2160p']['items'], '2160p quality count');
    eq(1, $quality['1080p']['items'], '1080p quality count');
    eq(1, $quality['720p']['items'], '720p quality count');
    eq(3, $quality['sd']['items'], 'SD / unknown quality count');

    $typesAll = indexBy($statsAll['types']);
    eq(4, $typesAll['video']['items'], 'all-time video count includes the old entry');
    eq(2, indexBy($statsAll['quality'])['720p']['items'], 'all-time 720p count includes the old entry');

    echo "Recent entries\n";
    $recent = $stats30['recent'];
    eq(6, count($recent), 'recent list contains the filtered entries');
    eq('Dune.Part.Two.2024.1080p.mkv', $recent[0]['filename'], 'recent list is newest first');
    eq('Another.Song.mp3', $recent[5]['filename'], 'recent list is oldest last');
    eq('video', $recent[0]['type'], 'recent entry exposes the normalized type');
    eq($size1080, $recent[0]['size'], 'recent entry exposes the parsed size');

    $manyStorage = storageAt($sandbox . '/many');
    $many = [];
    for ($i = 0; $i < 12; $i++) {
        $many[] = entry(
            sprintf('item-%02d.mkv', $i),
            'video',
            'success',
            sprintf('2026-06-14 %02d:00:00', $i),
            '/downloads/Movies',
            1
        );
    }
    $manyStorage->set('history', $many);
    $manyService = new StatisticsService($manyStorage, new MediaTypeHelper(), new FakeStorageUsageProvider(), $now);
    $manyStats = $manyService->getStatistics('30d');
    eq(12, $manyStats['summary']['items'], 'recent limit fixture has twelve entries');
    eq(10, count($manyStats['recent']), 'recent list is capped at ten entries');
    eq('item-11.mkv', $manyStats['recent'][0]['filename'], 'recent cap keeps the newest entry first');
    eq('item-02.mkv', $manyStats['recent'][9]['filename'], 'recent cap keeps the tenth newest entry');

    echo "Storage usage\n";
    $storageStats = $stats30['storage'];
    eq(2, count($storageStats['volumes']), 'only available volumes are returned');
    eq(3000000000000, $storageStats['total'], 'disk total is summed from the provider');
    eq(1900000000000, $storageStats['free'], 'disk free is summed from the provider');
    eq(1100000000000, $storageStats['used'], 'disk used is summed from the provider');
    eq(['/fake/library'], $storageStats['unavailable_paths'], 'missing provider path is reported');
    eq($expected30Bytes, $storageStats['history_bytes'], 'history bytes are exposed in storage data');
    eq($size1080 + $size2160, indexBy($storageStats['by_type'])['video']['bytes'], 'storage by type uses history sizes');

    $errorProvider = new FakeStorageUsageProvider([], ['/fake/downloads', '/fake/music', '/fake/library']);
    $errorService = new StatisticsService($storage, new MediaTypeHelper(), $errorProvider, $now);
    $errorStats = $errorService->getStatistics('30d');
    eq([], $errorStats['storage']['volumes'], 'provider errors degrade to no volumes');
    eq(0, $errorStats['storage']['total'], 'provider errors degrade total to zero');
    eq(3, count($errorStats['storage']['unavailable_paths']), 'provider errors are reported as unavailable');
    eq(6, $errorStats['summary']['items'], 'provider errors do not affect history metrics');

    echo "All-time daily cap\n";
    $cappedStorage = storageAt($sandbox . '/capped');
    $cappedStorage->set('history', [
        entry('Very.Old.1080p.mkv', 'video', 'success', '2025-05-01 00:00:00', '/downloads/Movies', 10),
        entry('Recent.1080p.mkv', 'video', 'success', '2026-06-10 00:00:00', '/downloads/Movies', 20),
    ]);
    $cappedService = new StatisticsService($cappedStorage, new MediaTypeHelper(), new FakeStorageUsageProvider(), $now);
    $capped = $cappedService->getStatistics('all');
    eq(2, $capped['summary']['items'], 'all-time summary keeps entries older than 365 days');
    eq(365, count($capped['daily']), 'all-time daily series is capped at 365 days');
    eq('2025-06-16', $capped['daily'][0]['date'], 'capped daily series starts 364 days before today');
    eq('2026-06-15', $capped['daily'][364]['date'], 'capped daily series ends today');

    echo "Empty history\n";
    $emptyStorage = storageAt($sandbox . '/empty');
    $emptyService = new StatisticsService($emptyStorage, new MediaTypeHelper(), new FakeStorageUsageProvider(), $now);
    $empty = $emptyService->getStatistics('30d');
    eq(0, $empty['summary']['items'], 'empty history has zero items');
    eq(0, $empty['summary']['total_bytes'], 'empty history has zero bytes');
    eq(0.0, $empty['summary']['success_rate'], 'empty history has a zero success rate');
    eq(30, count($empty['daily']), 'empty 30d history still has thirty daily buckets');
    eq(0, array_sum(array_column($empty['daily'], 'items')), 'empty daily buckets are all zero');
    eq([], $empty['recent'], 'empty history has no recent entries');
    foreach ($empty['types'] as $type) {
        eq(0, $type['items'], 'empty type ' . $type['key'] . ' has zero items');
    }
    $emptyRows = csvRows($emptyService->exportCsv('30d'));
    eq(1, count($emptyRows), 'empty CSV contains only the header row');
    eq(['date', 'filename', 'type', 'status', 'size_bytes', 'path'], $emptyRows[0], 'empty CSV keeps stable headers');
    eq([], $emptyService->getStatistics('all')['daily'], 'empty all-time history has no daily buckets');

    echo "Malformed history\n";
    $malformedStorage = storageAt($sandbox . '/malformed');
    $malformedStorage->set('history', [
        entry('Invalid.Date.mkv', 'video', 'success', 'not-a-date', '/downloads/Movies', 100),
        entry('Missing.Size.mkv', 'video', 'success', '2026-06-10 10:00:00', '/downloads/Movies', null),
        entry('Unknown.Status.mkv', 'video', 'super-status', '2026-06-11 10:00:00', '/downloads/Movies', 'abc'),
        entry('mystery', '', 'success', '2026-06-12 10:00:00', '/downloads/Unknown', null),
    ]);
    $malformedService = new StatisticsService($malformedStorage, new MediaTypeHelper(), new FakeStorageUsageProvider(), $now);
    $malformed = $malformedService->getStatistics('30d');
    eq(3, $malformed['summary']['items'], 'entry with an invalid date is ignored');
    eq(2, $malformed['summary']['success'], 'entries with malformed sizes are still counted');
    eq(1, $malformed['summary']['other'], 'unknown status is counted as other');
    eq(0, $malformed['summary']['total_bytes'], 'missing or non-numeric sizes count as zero bytes');
    $malformedTypes = indexBy($malformed['types']);
    eq(2, $malformedTypes['video']['items'], 'type is kept for entries with malformed sizes');
    eq(1, $malformedTypes['other']['items'], 'unknown extension falls back to other');
    $malformedRows = csvRows($malformedService->exportCsv('30d'));
    eq(4, count($malformedRows), 'malformed CSV exports every valid-date entry');

    echo "CSV export\n";
    $csv = $service->exportCsv('30d');
    $rows = csvRows($csv);
    eq(['date', 'filename', 'type', 'status', 'size_bytes', 'path'], $rows[0], 'CSV headers are stable');
    eq(7, count($rows), '30d CSV contains header plus six entries');
    eq('Dune.Part.Two.2024.1080p.mkv', $rows[1][1], 'CSV keeps history order');
    eq((string) $size1080, $rows[1][4], 'CSV size is expressed in bytes');
    eq('2026-06-10 09:30:00', $rows[2][0], 'CSV contains the normalized date');
    eq((string) $size2160, $rows[2][4], 'CSV contains parsed string sizes');
    eq(7, count(csvRows($service->exportCsv('bogus'))), 'CSV invalid period falls back to 30d');

    $formulaStorage = storageAt($sandbox . '/formula');
    $formulaStorage->set('history', [
        entry('=SUM(A1:A2).mkv', 'video', 'success', '2026-06-14 10:00:00', '+C:\\temp', 100),
        entry("Comma, \"quoted\"\nnewline.mkv", 'video', 'warning', '2026-06-13 10:00:00', '/safe, path', 200),
        entry('-danger.zip', 'archive', 'error', '2026-06-12 10:00:00', '@cmd', 300),
        entry('+plus.mkv', 'video', 'success', '2026-06-11 10:00:00', '/safe', 400),
        entry('@at.mkv', 'video', 'success', '2026-06-10 10:00:00', '/safe', 500),
    ]);
    $formulaService = new StatisticsService($formulaStorage, new MediaTypeHelper(), new FakeStorageUsageProvider(), $now);
    $formulaRows = csvRows($formulaService->exportCsv('7d'));
    eq(6, count($formulaRows), 'CSV formula fixture has five rows and a header');
    eq("'=SUM(A1:A2).mkv", $formulaRows[1][1], 'equals-prefixed filename is neutralized');
    eq("'+C:\\temp", $formulaRows[1][5], 'equals-prefixed path is neutralized');
    eq("Comma, \"quoted\"\nnewline.mkv", $formulaRows[2][1], 'commas, quotes and newlines round-trip through CSV');
    eq('/safe, path', $formulaRows[2][5], 'comma in path round-trips through CSV');
    eq("'-danger.zip", $formulaRows[3][1], 'minus-prefixed filename is neutralized');
    eq("'@cmd", $formulaRows[3][5], 'at-prefixed path is neutralized');
    eq("'+plus.mkv", $formulaRows[4][1], 'plus-prefixed filename is neutralized');
    eq("'@at.mkv", $formulaRows[5][1], 'at-prefixed filename is neutralized');

    echo "Route inventory\n";
    $routes = routerInventory();
    check($routes !== [], 'debug:router inventory is readable');
    check(isset($routes['statistics']), 'statistics route exists');
    check(isset($routes['statistics_export']), 'statistics_export route exists');
    if (isset($routes['statistics'])) {
        eq('/statistics', $routes['statistics']['path'] ?? null, 'statistics path is /statistics');
        check(str_contains(strtoupper((string) ($routes['statistics']['method'] ?? '')), 'GET'), 'statistics accepts GET');
        check(str_contains((string) ($routes['statistics']['defaults']['_controller'] ?? ''), '::index'), 'statistics maps to the controller index');
    }
    if (isset($routes['statistics_export'])) {
        eq('/statistics/export', $routes['statistics_export']['path'] ?? null, 'statistics_export path is /statistics/export');
        check(str_contains(strtoupper((string) ($routes['statistics_export']['method'] ?? '')), 'GET'), 'statistics_export accepts GET');
        check(str_contains((string) ($routes['statistics_export']['defaults']['_controller'] ?? ''), '::export'), 'statistics_export maps to the controller export');
    }

    echo "Navigation\n";
    $navigationStorage = storageAt($sandbox . '/navigation', [
        'api_key' => 'smoke-key',
        'default_path' => '/tmp',
    ]);
    $navigation = new NavigationService($navigationStorage);
    $item = findItem($navigation->getNavigation('statistics'), 'statistics');
    check($item !== null, 'statistics navigation item exists');
    if ($item !== null) {
        eq(true, $item['enabled'], 'statistics navigation item is enabled');
        eq('statistics', $item['route'], 'statistics navigation item points to statistics');
        eq(true, $item['active'], 'statistics navigation item is active on statistics');
    }
    $exportItem = findItem($navigation->getNavigation('statistics_export'), 'statistics');
    check($exportItem !== null && $exportItem['active'] === true, 'statistics navigation item is active on statistics_export');
} finally {
    removeTree($sandbox);
}

echo "\n$passes passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
