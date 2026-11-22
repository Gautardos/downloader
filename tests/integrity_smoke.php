<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Command\IntegrityCheckCommand;
use App\Command\ReleaseVerifyDownloadsCommand;
use App\Service\AlldebridService;
use App\Service\Automation\AutomationStore;
use App\Service\Automation\ReleaseIntegrityService;
use App\Service\Integrity\FileIntegrityChecker;
use App\Service\Integrity\IntegrityRequeueService;
use App\Service\Integrity\IntegritySettings;
use App\Service\Integrity\IntegrityStore;
use App\Service\Integrity\VideoIntegrityService;
use App\Service\JsonStorage;
use App\Service\Maintenance\Job\IntegrityCheckJob;
use App\Service\Maintenance\Job\MaintenanceJobInterface;
use App\Service\Maintenance\MaintenanceSettings;
use App\Service\Maintenance\MaintenanceStore;
use App\Service\MediaTypeHelper;
use App\Service\NavigationService;
use App\Service\QueueManager;
use Symfony\Component\Console\Tester\CommandTester;
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
        if ($file->isLink() || !$file->isDir()) {
            @unlink($file->getPathname());
        } else {
            @rmdir($file->getPathname());
        }
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

function writeFile(string $path, int $bytes, string $fill = 'V'): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    file_put_contents($path, str_repeat($fill, $bytes));
}

final class FakeAlldebrid extends AlldebridService
{
    public array $unlocked = [];
    public ?string $nextLink = 'https://cdn.example.test/new-link?token=secret';

    public function __construct()
    {
    }

    public function unlockLink(string $link): ?string
    {
        $this->unlocked[] = $link;

        return $this->nextLink;
    }
}

final class FakeQueueManager extends QueueManager
{
    public array $enqueued = [];
    public array $history = [];

    public function __construct()
    {
    }

    public function enqueue(array $item, string $type = 'video'): void
    {
        $this->enqueued[] = ['item' => $item, 'type' => $type];
    }

    public function recordHistory(array $item, string $status, int $fileCount, array $stats = []): void
    {
        $this->history[] = ['item' => $item, 'status' => $status, 'file_count' => $fileCount, 'stats' => $stats];
    }
}

function makeChecker(?string $ffprobe = null): FileIntegrityChecker
{
    return new FileIntegrityChecker(new MediaTypeHelper(), $ffprobe);
}

function makeServices(string $project, string $root, array $history, array $config = []): array
{
    $storage = storageAt($project . '/var/storage', array_replace(['default_path' => $root], $config));
    $storage->set('history', $history);
    $store = new IntegrityStore($project);
    $settings = new IntegritySettings($store);
    $checker = makeChecker();
    $service = new VideoIntegrityService($storage, $store, $settings, $checker);

    return [$storage, $store, $settings, $service];
}

$sandbox = tempDir('integrity-smoke');
$hasSqlite = extension_loaded('pdo_sqlite');

try {
    $root = $sandbox . '/root';
    mkdir($root, 0700, true);

    $healthy = $root . '/healthy.mkv';
    writeFile($healthy, 2 * 1024 * 1024);
    $truncated = $root . '/truncated.mkv';
    writeFile($truncated, 1024 * 1024);
    $html = $root . '/html.mkv';
    writeFile($html, 2048, '<');
    file_put_contents($html, '<!DOCTYPE html><html><body>expired</body></html>');
    $json = $root . '/json.mkv';
    file_put_contents($json, '{"error":"link expired"}');
    $tiny = $root . '/tiny.mkv';
    writeFile($tiny, 512, "\0");
    $empty = $root . '/empty.mkv';
    file_put_contents($empty, '');
    $missing = $root . '/missing.mkv';
    $audio = $root . '/track.mp3';
    writeFile($audio, 2 * 1024 * 1024);

    echo "File checker\n";
    $checker = makeChecker();
    $healthyResult = $checker->check($healthy, [$root], 2 * 1024 * 1024);
    eq(FileIntegrityChecker::STATUS_HEALTHY, $healthyResult['status'], 'healthy file is detected');
    eq(2 * 1024 * 1024, $healthyResult['actual_size'], 'healthy file size is reported');

    eq(FileIntegrityChecker::STATUS_MISSING, $checker->check($missing, [$root], 0)['status'], 'missing file is detected');
    $truncatedResult = $checker->check($truncated, [$root], 4 * 1024 * 1024);
    eq(FileIntegrityChecker::STATUS_INCOMPLETE, $truncatedResult['status'], 'truncated file is incomplete');
    check(str_contains($truncatedResult['reason'], 'truncated'), 'truncated reason is explicit', $truncatedResult['reason']);
    eq(FileIntegrityChecker::STATUS_CORRUPTED, $checker->check($html, [$root], 0)['status'], 'HTML signature is corrupted');
    eq(FileIntegrityChecker::STATUS_CORRUPTED, $checker->check($json, [$root], 0)['status'], 'JSON signature is corrupted');
    eq(FileIntegrityChecker::STATUS_INCOMPLETE, $checker->check($tiny, [$root], 0)['status'], 'file below the small threshold is incomplete');
    eq(FileIntegrityChecker::STATUS_CORRUPTED, $checker->check($empty, [$root], 0)['status'], 'empty file is corrupted');
    eq(FileIntegrityChecker::STATUS_UNSUPPORTED, $checker->check($audio, [$root], 0)['status'], 'audio file is unsupported');

    $outsideDir = $sandbox . '/outside';
    mkdir($outsideDir, 0700, true);
    $outside = $outsideDir . '/outside.mkv';
    writeFile($outside, 2 * 1024 * 1024);
    eq(FileIntegrityChecker::STATUS_OUTSIDE_ROOT, $checker->check($outside, [$root], 0)['status'], 'file outside the allowed root is rejected');
    $traversal = $root . '/../outside/outside.mkv';
    eq(FileIntegrityChecker::STATUS_OUTSIDE_ROOT, $checker->check($traversal, [$root], 0)['status'], 'path traversal outside the root is rejected');
    $symlink = $root . '/symlink.mkv';
    if (@symlink($outside, $symlink)) {
        eq(FileIntegrityChecker::STATUS_OUTSIDE_ROOT, $checker->check($symlink, [$root], 0)['status'], 'symlink escaping the root is rejected');
    } else {
        check(true, 'symlink creation skipped');
    }
    eq(FileIntegrityChecker::STATUS_OUTSIDE_ROOT, $checker->check($healthy, [], 0)['status'], 'empty root list rejects every path');

    $boundary = $root . '/boundary.mkv';
    writeFile($boundary, 900);
    eq(FileIntegrityChecker::STATUS_HEALTHY, $checker->check($boundary, [$root], 1000, false, 0)['status'], 'exactly 90 percent of expected size is healthy');
    writeFile($boundary, 899);
    eq(FileIntegrityChecker::STATUS_INCOMPLETE, $checker->check($boundary, [$root], 1000, false, 0)['status'], 'below 90 percent of expected size is incomplete');
    writeFile($boundary, 1024 * 1024);
    eq(FileIntegrityChecker::STATUS_HEALTHY, $checker->check($boundary, [$root], 0, false, 1024 * 1024)['status'], 'size equal to the threshold is healthy');

    echo "Deep check\n";
    $fakeProbe = $sandbox . '/ffprobe-ok';
    file_put_contents($fakeProbe, "#!/bin/sh\nprintf '%s' '{\"format\":{\"duration\":\"120.5\"},\"streams\":[{\"codec_type\":\"video\"}]}'\n");
    chmod($fakeProbe, 0755);
    $deepOk = makeChecker($fakeProbe)->check($healthy, [$root], 2 * 1024 * 1024, true, 0);
    eq(FileIntegrityChecker::STATUS_HEALTHY, $deepOk['status'], 'deep check succeeds with a fake ffprobe');
    eq('ok', $deepOk['extra']['deep'] ?? null, 'deep check status is recorded');
    eq(120.5, $deepOk['extra']['duration'] ?? null, 'deep check duration is recorded');

    $fakeProbeBad = $sandbox . '/ffprobe-bad';
    file_put_contents($fakeProbeBad, "#!/bin/sh\nprintf '%s' '{\"format\":{\"duration\":\"0\"},\"streams\":[]}'\n");
    chmod($fakeProbeBad, 0755);
    $deepBad = makeChecker($fakeProbeBad)->check($healthy, [$root], 2 * 1024 * 1024, true, 0);
    eq(FileIntegrityChecker::STATUS_CORRUPTED, $deepBad['status'], 'deep check rejects a file without video streams');

    $deepMissing = makeChecker('/nonexistent/ffprobe')->check($healthy, [$root], 2 * 1024 * 1024, true, 0);
    eq(FileIntegrityChecker::STATUS_HEALTHY, $deepMissing['status'], 'missing ffprobe keeps the quick status');
    eq('unavailable', $deepMissing['extra']['deep'] ?? null, 'missing ffprobe is reported as unavailable');
    check(str_contains($deepMissing['reason'], 'unavailable'), 'missing ffprobe yields a clear warning', $deepMissing['reason']);

    if (!$hasSqlite) {
        echo "\nSQLite driver unavailable; skipping store, service, requeue, maintenance and command tests.\n";
    } else {
        echo "Integrity store\n";
        $storeProject = projectDir('integrity-store');
        $store = new IntegrityStore($storeProject);
        $store->initialize();
        check(is_file($store->getDbPath()), 'integrity database is created');
        eq('0600', substr(sprintf('%o', fileperms($store->getDbPath())), -4), 'integrity database permissions are 0600');
        $tables = $store->getPdo()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        check(in_array('integrity_checks', $tables, true), 'integrity_checks table exists');
        check(in_array('integrity_settings', $tables, true), 'integrity_settings table exists');

        $id = $store->recordCheck([
            'check_key' => 'download:abc',
            'path' => '/tmp/abc.mkv',
            'download_id' => 'abc',
            'filename' => 'abc.mkv',
            'type' => 'video',
            'expected_size' => 100,
            'actual_size' => 100,
            'status' => 'healthy',
            'reason' => '',
            'last_checked_at' => '2026-06-15 00:00:00',
            'extra' => ['source_available' => true],
        ]);
        check($id > 0, 'check is inserted');
        $store->updateCheck($id, ['attempts' => 2, 'last_requeued_at' => '2026-06-15 01:00:00']);
        $store->recordCheck([
            'check_key' => 'download:abc',
            'path' => '/tmp/abc.mkv',
            'download_id' => 'abc',
            'filename' => 'abc.mkv',
            'type' => 'video',
            'expected_size' => 100,
            'actual_size' => 20,
            'status' => 'incomplete',
            'reason' => 'truncated',
            'last_checked_at' => '2026-06-15 02:00:00',
            'extra' => ['source_available' => true],
        ]);
        $stored = $store->findCheck('download:abc');
        eq('incomplete', $stored['status'], 'recordCheck updates an existing check by check key');
        eq(2, $stored['attempts'], 'recordCheck preserves requeue attempts');
        eq(true, $stored['extra']['source_available'], 'extra JSON is decoded');
        eq(1, $store->countChecks(), 'checks are deduplicated by check key');
        eq(1, count($store->getChecks(['status' => 'incomplete'])), 'status filter works');
        eq(1, count($store->getChecks(['requeued' => true])), 'requeued filter works');
        eq(1, count($store->getChecks(['search' => 'abc.mkv'])), 'search filter works');
        eq(0, count($store->getChecks(['search' => 'nothing'])), 'search filter excludes other rows');
        $summary = $store->getSummary();
        eq(1, $summary['total'], 'summary counts checks');
        eq(1, $summary['incomplete'], 'summary counts incomplete checks');
        eq(1, $summary['requeued'], 'summary counts requeued checks');

        $store->recordCheck([
            'check_key' => 'path:/tmp/old.mkv',
            'path' => '/tmp/old.mkv',
            'filename' => 'old.mkv',
            'status' => 'healthy',
            'last_checked_at' => '2020-01-01 00:00:00',
        ]);
        eq(1, $store->purgeOldChecks(30, new DateTimeImmutable('2026-06-15 00:00:00', new DateTimeZone('UTC'))), 'old checks are purged');
        eq(1, $store->countChecks(), 'recent check survives retention');

        $settings = new IntegritySettings($store);
        $defaults = $settings->all();
        eq(3, $defaults['max_requeue_attempts'], 'max requeue attempts defaults to 3');
        eq(false, $defaults['auto_requeue'], 'auto requeue is disabled by default');
        eq(false, $defaults['deep_check'], 'deep check is disabled by default');
        eq(1024 * 1024, $defaults['small_file_threshold'], 'small file threshold defaults to 1 MB');
        $saved = $settings->save([
            'max_requeue_attempts' => 50,
            'auto_requeue' => '1',
            'deep_check' => 'yes',
            'small_file_threshold' => 2048,
            'scan_limit' => 5,
        ]);
        eq(20, $saved['max_requeue_attempts'], 'max requeue attempts is clamped');
        eq(true, $saved['auto_requeue'], 'auto requeue is saved as a boolean');
        eq(true, $saved['deep_check'], 'deep check is saved as a boolean');
        eq(2048, $saved['small_file_threshold'], 'small file threshold is persisted');

        echo "Queue history source URL\n";
        $historyProject = projectDir('integrity-history');
        $historyStorage = storageAt($historyProject . '/var/storage', ['history_retention_limit' => 50]);
        $queueManager = new QueueManager($historyStorage, $historyProject, new MediaTypeHelper());
        $queueManager->recordHistory([
            'download_id' => 'new1',
            'filename' => 'new.mkv',
            'path' => '/downloads',
            'original_url' => 'https://example.test/file?token=abc',
        ], 'success', 1, ['size' => 123]);
        $queueManager->recordHistory([
            'download_id' => 'old1',
            'filename' => 'old.mkv',
            'path' => '/downloads',
        ], 'success', 1, ['size' => 10]);
        $historyRows = $historyStorage->get('history', []);
        eq('https://example.test/file?token=abc', $historyRows[0]['source_url'] ?? null, 'recordHistory stores source_url from original_url');
        eq(null, $historyRows[1]['source_url'] ?? null, 'legacy history entry without a source is handled');

        echo "Video integrity scan\n";
        $scanProject = projectDir('integrity-scan');
        $scanRoot = $sandbox . '/scan-root';
        mkdir($scanRoot, 0700, true);
        writeFile($scanRoot . '/healthy.mkv', 2 * 1024 * 1024);
        writeFile($scanRoot . '/truncated.mkv', 1024 * 1024);
        writeFile($scanRoot . '/html.mkv', 1024 * 1024, 'H');
        file_put_contents($scanRoot . '/html.mkv', '<html>error</html>');
        writeFile($scanRoot . '/audio.mp3', 2 * 1024 * 1024);
        $scanHistory = [
            ['download_id' => 'h1', 'filename' => 'healthy.mkv', 'path' => $scanRoot, 'type' => 'video', 'actual_size' => 2 * 1024 * 1024, 'source_url' => 'https://example.test/h1?token=secret'],
            ['download_id' => 't1', 'filename' => 'truncated.mkv', 'path' => $scanRoot, 'type' => 'video', 'size' => 4 * 1024 * 1024, 'source_url' => 'https://example.test/t1?token=secret'],
            ['download_id' => 'c1', 'filename' => 'html.mkv', 'path' => $scanRoot, 'type' => 'video', 'size' => 2 * 1024 * 1024, 'source_url' => 'https://example.test/c1?token=secret'],
            ['download_id' => 'a1', 'filename' => 'audio.mp3', 'path' => $scanRoot, 'type' => 'music'],
            ['download_id' => 'old', 'filename' => 'missing.mkv', 'path' => $scanRoot, 'type' => 'video', 'size' => 2 * 1024 * 1024],
            ['download_id' => 'h1', 'filename' => 'healthy.mkv', 'path' => $scanRoot, 'type' => 'video', 'actual_size' => 2 * 1024 * 1024, 'source_url' => 'https://example.test/h1?token=secret'],
        ];
        [$scanStorage, $scanStore, $scanSettings, $scanService] = makeServices($scanProject, $scanRoot, $scanHistory);
        $scanResult = $scanService->scan(10, false, true);
        eq(4, $scanResult['checked'], 'scan checks unique video files only');
        eq(1, $scanResult['summary']['healthy'], 'scan counts healthy files');
        eq(1, $scanResult['summary']['incomplete'], 'scan counts truncated files as incomplete');
        eq(1, $scanResult['summary']['missing'], 'scan counts missing files separately');
        eq(1, $scanResult['summary']['corrupted'], 'scan counts corrupted files');
        eq(4, $scanStore->countChecks(), 'scan persists one row per unique video');
        $healthyCheck = $scanStore->findCheck('download:h1');
        eq(false, str_contains((string) $healthyCheck['extra']['source_display'], 'token'), 'stored source display hides query tokens');
        eq('https://example.test', $healthyCheck['extra']['source_display'], 'stored source display keeps only the host');
        $listed = $scanService->listChecks([], 10);
        eq(true, $listed[0]['source_available'], 'listed checks expose source availability');
        eq(4, count($listed), 'listed checks include every stored row');

        $limited = $scanService->scan(1, false, true);
        eq(1, $limited['checked'], 'scan respects the limit');
        $previewProject = projectDir('integrity-preview');
        [$previewStorage, $previewStore, $previewSettings, $previewService] = makeServices($previewProject, $scanRoot, $scanHistory);
        $preview = $previewService->scan(10, false, false);
        eq(4, $preview['checked'], 'dry scan still evaluates files');
        eq(0, $previewStore->countChecks(), 'dry scan does not persist checks');

        echo "Requeue\n";
        $requeueProject = projectDir('integrity-requeue');
        $requeueRoot = $sandbox . '/requeue-root';
        mkdir($requeueRoot, 0700, true);
        writeFile($requeueRoot . '/small.mkv', 1024);
        $requeueHistory = [
            ['download_id' => 'r1', 'filename' => 'small.mkv', 'path' => $requeueRoot, 'type' => 'video', 'size' => 2 * 1024 * 1024, 'source_url' => 'https://source.example.test/file?token=secret'],
            ['download_id' => 'r2', 'filename' => 'unknown.mkv', 'path' => $requeueRoot, 'type' => 'video', 'size' => 2 * 1024 * 1024],
            ['download_id' => 'r3', 'filename' => 'magnet.mkv', 'path' => $requeueRoot, 'type' => 'video', 'size' => 2 * 1024 * 1024, 'source_url' => 'magnet:?xt=urn:btih:abc'],
        ];
        [$requeueStorage, $requeueStore, $requeueSettings, $requeueIntegrity] = makeServices($requeueProject, $requeueRoot, $requeueHistory);
        $requeueIntegrity->scan(10, false, true);
        $fakeAlldebrid = new FakeAlldebrid();
        $fakeQueue = new FakeQueueManager();
        $requeueService = new IntegrityRequeueService($requeueStore, $requeueSettings, $requeueStorage, $fakeQueue, $fakeAlldebrid);

        $check = $requeueStore->findCheck('download:r1');
        $result = $requeueService->requeueCheck($check);
        eq(true, $result['success'], 'HTTP source can be requeued');
        eq('https://source.example.test/file?token=secret', $fakeAlldebrid->unlocked[0] ?? null, 'the original HTTP source is unlocked');
        eq('https://cdn.example.test/new-link?token=secret', $fakeQueue->enqueued[0]['item']['url'] ?? null, 'the unlocked URL is enqueued');
        eq('https://source.example.test/file?token=secret', $fakeQueue->enqueued[0]['item']['original_url'] ?? null, 'the original URL is kept on the queue item');
        eq('small.mkv', $fakeQueue->enqueued[0]['item']['filename'] ?? null, 'the filename is preserved');
        eq($requeueRoot, $fakeQueue->enqueued[0]['item']['path'] ?? null, 'the destination path is preserved');
        eq(true, $fakeQueue->enqueued[0]['item']['overwrite'] ?? null, 'a partial file is overwritten');
        check(($fakeQueue->enqueued[0]['item']['download_id'] ?? '') !== 'r1', 'a new download id is generated');
        eq('requeued', $fakeQueue->history[0]['status'] ?? null, 'the requeue is written to history');
        $updatedCheck = $requeueStore->findCheck('download:r1');
        eq(1, $updatedCheck['attempts'], 'requeue attempts are incremented');
        check(is_string($updatedCheck['last_requeued_at']) && $updatedCheck['last_requeued_at'] !== '', 'last_requeued_at is recorded');
        check(!str_contains($result['message'], 'token='), 'requeue message never leaks the full URL', $result['message']);
        check(str_contains($result['message'], 'https://source.example.test'), 'requeue message keeps a safe source display');

        $unknown = $requeueService->requeueCheck($requeueStore->findCheck('download:r2'));
        eq(false, $unknown['success'], 'unknown source is refused');
        check(str_contains($unknown['message'], 'manual retry'), 'unknown source message is clear', $unknown['message']);
        $magnet = $requeueService->requeueCheck($requeueStore->findCheck('download:r3'));
        eq(false, $magnet['success'], 'magnet source is refused');
        check(str_contains($magnet['message'], 'manual retry'), 'magnet source message is clear', $magnet['message']);

        $alreadyQueuedStorage = $requeueStorage->get('server_queue', []);
        $alreadyQueuedStorage[] = ['path' => $requeueRoot, 'filename' => 'small.mkv', 'source_url' => 'https://source.example.test/file?token=secret'];
        $requeueStorage->set('server_queue', $alreadyQueuedStorage);
        $alreadyQueued = $requeueService->requeueCheck($requeueStore->findCheck('download:r1'));
        eq(false, $alreadyQueued['success'], 'an already queued file is refused');
        $requeueStorage->set('server_queue', []);

        $requeueSettings->save(['max_requeue_attempts' => 2]);
        $requeueStore->updateCheck((int) $updatedCheck['id'], ['attempts' => 2]);
        $tooMany = $requeueService->requeueCheck($requeueStore->findCheck('download:r1'));
        eq(false, $tooMany['success'], 'max requeue attempts is enforced');
        check(str_contains($tooMany['message'], 'Maximum requeue attempts'), 'max attempts message is clear', $tooMany['message']);

        $requeueSettings->save(['max_requeue_attempts' => 3]);
        $requeueStore->updateCheck((int) $updatedCheck['id'], ['attempts' => 0]);
        $bulkProject = projectDir('integrity-bulk');
        $bulkRoot = $sandbox . '/bulk-root';
        mkdir($bulkRoot, 0700, true);
        $bulkHistory = [];
        for ($i = 1; $i <= 3; $i++) {
            writeFile($bulkRoot . '/bulk' . $i . '.mkv', 1024);
            $bulkHistory[] = [
                'download_id' => 'b' . $i,
                'filename' => 'bulk' . $i . '.mkv',
                'path' => $bulkRoot,
                'type' => 'video',
                'size' => 2 * 1024 * 1024,
                'source_url' => 'https://bulk.example.test/' . $i . '?token=secret',
            ];
        }
        [$bulkStorage, $bulkStore, $bulkSettings, $bulkIntegrity] = makeServices($bulkProject, $bulkRoot, $bulkHistory);
        $bulkIntegrity->scan(10, false, true);
        $bulkQueue = new FakeQueueManager();
        $bulkAlldebrid = new FakeAlldebrid();
        $bulkService = new IntegrityRequeueService($bulkStore, $bulkSettings, $bulkStorage, $bulkQueue, $bulkAlldebrid);
        $bulkResult = $bulkService->requeueMany(['download:b1', 'download:b2', 'download:b3'], false, 2);
        eq(2, $bulkResult['processed'], 'bulk requeue processes up to the limit');
        eq(2, $bulkResult['succeeded'], 'bulk requeue reports success');
        eq(1, $bulkResult['skipped'], 'bulk requeue reports skipped items');
        eq(2, count($bulkQueue->enqueued), 'bulk requeue enqueues only the allowed items');
        $dryBulk = $bulkService->requeueMany(['download:b3'], true, 2);
        eq(true, $dryBulk['results'][0]['dry_run'] ?? null, 'dry bulk requeue is labelled');
        eq(2, count($bulkQueue->enqueued), 'dry bulk requeue does not enqueue');

        echo "Maintenance job\n";
        $jobProject = projectDir('integrity-job');
        $jobRoot = $sandbox . '/job-root';
        mkdir($jobRoot, 0700, true);
        writeFile($jobRoot . '/job1.mkv', 1024);
        writeFile($jobRoot . '/job2.mkv', 2 * 1024 * 1024);
        $jobHistory = [
            ['download_id' => 'j1', 'filename' => 'job1.mkv', 'path' => $jobRoot, 'type' => 'video', 'size' => 2 * 1024 * 1024, 'source_url' => 'https://job.example.test/1?token=secret'],
            ['download_id' => 'j2', 'filename' => 'job2.mkv', 'path' => $jobRoot, 'type' => 'video', 'actual_size' => 2 * 1024 * 1024, 'source_url' => 'https://job.example.test/2?token=secret'],
        ];
        [$jobStorage, $jobStore, $jobIntegritySettings, $jobIntegrity] = makeServices($jobProject, $jobRoot, $jobHistory);
        $jobQueue = new FakeQueueManager();
        $jobAlldebrid = new FakeAlldebrid();
        $jobRequeue = new IntegrityRequeueService($jobStore, $jobIntegritySettings, $jobStorage, $jobQueue, $jobAlldebrid);
        $maintenanceStore = new MaintenanceStore($jobProject);
        $maintenanceSettings = new MaintenanceSettings($maintenanceStore);
        $job = new IntegrityCheckJob($jobIntegrity, $jobRequeue, $jobIntegritySettings, $maintenanceSettings);
        eq('integrity', $job->key(), 'integrity job key is stable');
        eq('Integrity deep check', $job->label(), 'integrity job label is stable');
        check($job instanceof MaintenanceJobInterface, 'integrity job implements the maintenance interface');
        $defaultMaintenance = (new MaintenanceSettings($maintenanceStore))->defaults();
        eq(false, $defaultMaintenance['jobs']['integrity'], 'integrity job is disabled by default');
        eq(false, $maintenanceSettings->isJobEnabled($defaultMaintenance, 'integrity'), 'default settings keep integrity disabled');
        eq(true, $maintenanceSettings->isJobEnabled(['jobs' => ['integrity' => true]], 'integrity'), 'integrity job can be enabled');

        $jobIntegritySettings->save(['scan_limit' => 1, 'auto_requeue' => false]);
        $logLines = [];
        $jobResult = $job->run(static function (string $line) use (&$logLines): void {
            $logLines[] = $line;
        });
        eq(1, $jobResult['checked'], 'job run is bounded by the scan limit');
        eq(0, $jobResult['requeued'], 'auto requeue stays off when the policy is disabled');
        eq(0, count($jobQueue->enqueued), 'disabled auto requeue does not enqueue');
        check($logLines !== [], 'job writes log lines');

        $jobStore->getPdo()->exec('DELETE FROM integrity_checks');
        $jobIntegritySettings->save(['scan_limit' => 10, 'auto_requeue' => true, 'requeue_bulk_limit' => 5]);
        $jobResult = $job->run(static function (string $line): void {
        });
        eq(2, $jobResult['checked'], 'job scans all eligible files when the limit allows');
        eq(1, $jobResult['requeued'], 'auto requeue requeues eligible files');
        eq(1, count($jobQueue->enqueued), 'auto requeue enqueues exactly one file');
        check(!str_contains((string) ($jobResult['requeue']['results'][0]['message'] ?? ''), 'token='), 'auto requeue summary does not leak URLs');

        echo "Integrity command\n";
        $commandProject = projectDir('integrity-command');
        $commandRoot = $sandbox . '/command-root';
        mkdir($commandRoot, 0700, true);
        writeFile($commandRoot . '/command.mkv', 1024);
        $commandHistory = [
            ['download_id' => 'cmd1', 'filename' => 'command.mkv', 'path' => $commandRoot, 'type' => 'video', 'size' => 2 * 1024 * 1024, 'source_url' => 'https://cmd.example.test/1?token=secret'],
        ];
        [$commandStorage, $commandStore, $commandSettings, $commandIntegrity] = makeServices($commandProject, $commandRoot, $commandHistory);
        $commandQueue = new FakeQueueManager();
        $commandAlldebrid = new FakeAlldebrid();
        $commandRequeue = new IntegrityRequeueService($commandStore, $commandSettings, $commandStorage, $commandQueue, $commandAlldebrid);
        $command = new IntegrityCheckCommand($commandIntegrity, $commandRequeue);
        $tester = new CommandTester($command);
        $tester->execute(['--dry-run' => true, '--limit' => '20', '--json' => true]);
        eq(0, $tester->getStatusCode(), 'integrity command dry run exits successfully');
        $payload = json_decode(trim($tester->getDisplay()), true);
        eq(true, $payload['dry_run'] ?? null, 'integrity command reports dry run');
        eq(1, $payload['checked'] ?? null, 'integrity command honours the limit');
        eq(1, $payload['summary']['incomplete'] ?? null, 'integrity command reports the summary');
        eq(0, $commandStore->countChecks(), 'integrity command dry run does not persist checks');

        $tester->execute(['--dry-run' => true, '--requeue' => true, '--limit' => '20', '--json' => true]);
        $dryRequeuePayload = json_decode(trim($tester->getDisplay()), true);
        eq(1, $dryRequeuePayload['requeue']['succeeded'] ?? null, 'dry-run requeue previews eligible files');
        eq(0, count($commandQueue->enqueued), 'dry-run requeue does not enqueue');
        eq(0, $commandStore->countChecks(), 'dry-run requeue does not persist checks');

        $tester->execute(['--requeue' => true, '--limit' => '20', '--json' => true]);
        eq(0, $tester->getStatusCode(), 'integrity command requeue exits successfully');
        $requeuePayload = json_decode(trim($tester->getDisplay()), true);
        eq(1, $requeuePayload['requeue']['succeeded'] ?? null, 'integrity command requeues a file');
        eq(1, count($commandQueue->enqueued), 'integrity command enqueues through the requeue service');
        eq(1, $commandStore->countChecks(), 'integrity command persists checks when not dry running');

        echo "Routes and navigation\n";
        $routes = routerInventory();
        check($routes !== [], 'debug:router inventory is readable');
        $expectedRoutes = [
            'video_integrity' => ['/video/integrity', 'GET'],
            'video_integrity_check' => ['/video/integrity/check', 'POST'],
            'video_integrity_requeue' => ['/video/integrity/requeue', 'POST'],
            'video_integrity_status' => ['/video/integrity/status', 'GET'],
            'video_integrity_settings' => ['/video/integrity/settings', 'POST'],
        ];
        foreach ($expectedRoutes as $name => [$path, $method]) {
            check(isset($routes[$name]), 'route ' . $name . ' exists');
            if (isset($routes[$name])) {
                eq($path, $routes[$name]['path'] ?? null, $name . ' path is stable');
                check(str_contains(strtoupper((string) ($routes[$name]['method'] ?? '')), $method), $name . ' accepts ' . $method);
                check(str_contains((string) ($routes[$name]['defaults']['_controller'] ?? ''), 'IntegrityController'), $name . ' maps to IntegrityController');
            }
        }

        $navigationProject = projectDir('integrity-navigation');
        $navigationStorage = storageAt($navigationProject . '/var/storage', ['api_key' => 'smoke', 'default_path' => '/tmp']);
        $navigation = new NavigationService($navigationStorage);
        $groups = $navigation->getNavigation('video_integrity');
        $integrityItem = null;
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                if ($item['key'] === 'video_integrity') {
                    $integrityItem = $item;
                }
            }
        }
        check($integrityItem !== null, 'Video integrity navigation entry exists');
        if ($integrityItem !== null) {
            eq(true, $integrityItem['enabled'], 'Video integrity navigation entry is enabled');
            eq('video_integrity', $integrityItem['route'], 'Video integrity navigation points to the route');
            eq(true, $integrityItem['active'], 'Video integrity entry is active on its route');
        }
        foreach (['video_integrity_check', 'video_integrity_requeue', 'video_integrity_status'] as $routeName) {
            $probe = $navigation->getNavigation($routeName);
            $found = false;
            foreach ($probe as $group) {
                foreach ($group['items'] as $item) {
                    if ($item['key'] === 'video_integrity' && $item['active']) {
                        $found = true;
                    }
                }
            }
            check($found, $routeName . ' keeps Video integrity active');
        }

        echo "Release integrity regression\n";
        $automationProject = projectDir('integrity-release');
        $automationStore = new AutomationStore($automationProject);
        $releaseStorage = storageAt($automationProject . '/var/storage');
        $releaseIntegrity = new ReleaseIntegrityService($automationStore, $releaseStorage);
        $releaseResult = $releaseIntegrity->verifySuccessfulIntents(false, false, 5);
        eq(true, $releaseResult['success'], 'ReleaseIntegrityService still previews successfully');
        eq(0, $releaseResult['stats']['checked'], 'ReleaseIntegrityService handles an empty intent list');
        $releaseCommand = new ReleaseVerifyDownloadsCommand($releaseIntegrity);
        $releaseTester = new CommandTester($releaseCommand);
        $releaseTester->execute(['--limit' => '5']);
        eq(0, $releaseTester->getStatusCode(), 'app:release-verify-downloads command still works');
        check(str_contains($releaseTester->getDisplay(), 'Checked: 0'), 'release verify command reports its counters');
    }
} finally {
    removeTree($sandbox);
}

echo "\n$passes passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
