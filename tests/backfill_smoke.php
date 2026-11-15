<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\Automation\AutomationStore;
use App\Service\Automation\ReleaseIntegrityService;
use App\Service\JsonStorage;

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

echo "\n$passes passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
