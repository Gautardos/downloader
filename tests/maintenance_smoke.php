<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\JsonStorage;
use App\Service\LogRedactor;
use App\Service\LogRotator;
use App\Service\Maintenance\Job\LogRotationJob;
use App\Service\Maintenance\Job\MaintenanceJobInterface;
use App\Service\Maintenance\Job\TorrentDatabaseUpdateJob;
use App\Service\Maintenance\MaintenanceRunner;
use App\Service\Maintenance\MaintenanceScheduler;
use App\Service\Maintenance\MaintenanceSettings;
use App\Service\Maintenance\MaintenanceStore;
use App\Service\Maintenance\TorrentDatabaseUpdater;
use App\Service\NavigationService;
use App\Command\DownloadWorkerCommand;
use App\Service\ServerNotificationService;
use App\Command\MaintenanceCommand;
use Symfony\Component\Console\Application;
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

function schedulerSettings(array $overrides = []): array
{
    return array_replace([
        'enabled' => true,
        'frequency' => 'daily',
        'time' => '03:00',
        'days' => [1, 2, 3, 4, 5, 6, 7],
        'weekday' => 1,
        'timezone' => 'UTC',
        'pause_downloads' => true,
        'run_retention_days' => 30,
        'jobs' => ['torrent_db' => true, 'log_rotation' => true],
        'enabled_at' => null,
    ], $overrides);
}

function storeWithSettings(MaintenanceStore $store, MaintenanceSettings $settings, array $overrides = []): array
{
    $value = array_replace($settings->defaults(), $overrides);
    $store->setSetting(MaintenanceSettings::SETTINGS_KEY, $value);

    return $value;
}

function makeRunner(
    MaintenanceStore $store,
    MaintenanceSettings $settings,
    MaintenanceScheduler $scheduler,
    array $jobs,
    JsonStorage $storage,
    ?DateTimeImmutable $now = null
): MaintenanceRunner {
    return new MaintenanceRunner(
        $store,
        $settings,
        $scheduler,
        $jobs,
        $storage,
        new ServerNotificationService(),
        new LogRedactor(),
        $now
    );
}

function createTorrentSchema(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS torrents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            hash_info TEXT,
            size INTEGER,
            description BLOB,
            parent_category INTEGER,
            category TEXT,
            category_slug TEXT
        )'
    );

    return $pdo;
}

function countTorrents(string $path): int
{
    $pdo = new PDO('sqlite:' . $path);

    return (int) $pdo->query('SELECT COUNT(*) FROM torrents')->fetchColumn();
}

function uniqueIndexExists(string $path, string $indexName): bool
{
    $pdo = new PDO('sqlite:' . $path);
    $rows = $pdo->query('PRAGMA index_list(torrents)')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        if (($row['name'] ?? '') === $indexName) {
            return (int) ($row['unique'] ?? 0) === 1;
        }
    }

    return false;
}

final class FakeMaintenanceJob implements MaintenanceJobInterface
{
    public int $runCount = 0;

    public function __construct(
        private string $jobKey,
        private string $jobLabel,
        private bool $enabled = true,
        private array $summary = ['status' => 'success', 'value' => 1],
        private ?Throwable $failure = null
    ) {
    }

    public function key(): string
    {
        return $this->jobKey;
    }

    public function label(): string
    {
        return $this->jobLabel;
    }

    public function isEnabled(array $settings): bool
    {
        return $this->enabled;
    }

    public function run(callable $log): array
    {
        $this->runCount++;
        $log('Fake job ' . $this->jobKey . ' ran.');
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->summary;
    }
}

$hasSqlite = in_array('sqlite', PDO::getAvailableDrivers(), true);

try {
    echo "Scheduler\n";
    $scheduler = new MaintenanceScheduler();
    $utc = new DateTimeZone('UTC');

    eq(null, $scheduler->nextRunAt(schedulerSettings(['enabled' => false]), new DateTimeImmutable('2026-06-15 02:00:00', $utc)), 'disabled schedule has no next run');

    $dailyFuture = $scheduler->nextRunAt(schedulerSettings(), new DateTimeImmutable('2026-06-15 02:00:00', $utc));
    eq('2026-06-15 03:00:00', $dailyFuture?->format('Y-m-d H:i:s'), 'daily future time runs today');

    $dailyPast = $scheduler->nextRunAt(schedulerSettings(), new DateTimeImmutable('2026-06-15 04:00:00', $utc));
    eq('2026-06-16 03:00:00', $dailyPast?->format('Y-m-d H:i:s'), 'daily past time runs tomorrow');

    $weekdayOnly = schedulerSettings(['days' => [1]]);
    $fromSunday = $scheduler->nextRunAt($weekdayOnly, new DateTimeImmutable('2026-06-14 04:00:00', $utc));
    eq('2026-06-15 03:00:00', $fromSunday?->format('Y-m-d H:i:s'), 'daily honours active weekdays without occurrence');

    $weeklyMonday = schedulerSettings(['frequency' => 'weekly', 'weekday' => 1]);
    $mondayFuture = $scheduler->nextRunAt($weeklyMonday, new DateTimeImmutable('2026-06-15 02:00:00', $utc));
    eq('2026-06-15 03:00:00', $mondayFuture?->format('Y-m-d H:i:s'), 'weekly selects the configured weekday');
    $mondayPast = $scheduler->nextRunAt($weeklyMonday, new DateTimeImmutable('2026-06-15 04:00:00', $utc));
    eq('2026-06-22 03:00:00', $mondayPast?->format('Y-m-d H:i:s'), 'weekly rolls to the next week after the time');
    $tuesday = $scheduler->nextRunAt($weeklyMonday, new DateTimeImmutable('2026-06-16 02:00:00', $utc));
    eq('2026-06-22 03:00:00', $tuesday?->format('Y-m-d H:i:s'), 'weekly finds the next occurrence on another day');

    $newYork = schedulerSettings(['timezone' => 'America/New_York']);
    $timezoneNext = $scheduler->nextRunAt($newYork, new DateTimeImmutable('2026-06-15 06:00:00', $utc));
    eq('2026-06-15 07:00', $timezoneNext?->setTimezone($utc)->format('Y-m-d H:i'), 'scheduler honours the configured timezone');

    $dst = schedulerSettings(['timezone' => 'Europe/Paris']);
    $dstNext = $scheduler->nextRunAt($dst, new DateTimeImmutable('2026-03-29 00:30:00', $utc));
    check($dstNext instanceof DateTimeImmutable, 'scheduler does not crash around spring DST transition', $dstNext);
    $dstAutumn = $scheduler->nextRunAt($dst, new DateTimeImmutable('2026-10-25 00:30:00', $utc));
    check($dstAutumn instanceof DateTimeImmutable, 'scheduler does not crash around autumn DST transition', $dstAutumn);

    check(
        $scheduler->isDue($weeklyMonday, new DateTimeImmutable('2026-06-08 03:00:00', $utc), new DateTimeImmutable('2026-06-15 03:00:01', $utc)),
        'weekly run is due after the scheduled instant'
    );
    check(
        !$scheduler->isDue($weeklyMonday, new DateTimeImmutable('2026-06-15 03:00:00', $utc), new DateTimeImmutable('2026-06-15 03:00:01', $utc)),
        'weekly run is not due again right after running'
    );

    if (!$hasSqlite) {
        echo "\nSQLite driver unavailable; skipping store, runner, jobs and command tests (run inside the Docker image).\n";
    } else {
        echo "Settings\n";
        $project = projectDir('maintenance-settings');
        $store = new MaintenanceStore($project);
        $settings = new MaintenanceSettings($store);
        $defaults = $settings->all();
        eq(false, $defaults['enabled'], 'maintenance is disabled by default');
        eq('daily', $defaults['frequency'], 'daily is the default frequency');
        eq('03:00', $defaults['time'], '03:00 is the default time');
        eq('UTC', $defaults['timezone'], 'UTC is the default timezone');
        eq([1, 2, 3, 4, 5, 6, 7], $defaults['days'], 'all weekdays are active by default');
        eq(true, $defaults['jobs']['torrent_db'], 'torrent database job is enabled by default');
        eq(true, $defaults['jobs']['log_rotation'], 'log rotation job is enabled by default');
        eq(false, $defaults['jobs']['integrity'], 'integrity job is disabled by default');

        $sanitized = $settings->save([
            '_maintenance_form' => 1,
            'enabled' => '1',
            'frequency' => 'hourly',
            'time' => '99:99',
            'timezone' => 'Invalid/Zone',
            'days' => [0, 8, 'x'],
            'weekday' => 42,
            'run_retention_days' => -3,
            'jobs' => ['torrent_db' => '1'],
        ]);
        eq(true, $sanitized['enabled'], 'enabled is sanitized as a boolean');
        eq('daily', $sanitized['frequency'], 'invalid frequency falls back to daily');
        eq('03:00', $sanitized['time'], 'invalid time falls back to 03:00');
        eq('UTC', $sanitized['timezone'], 'invalid timezone falls back to UTC');
        eq([1, 2, 3, 4, 5, 6, 7], $sanitized['days'], 'invalid days fall back to all days');
        eq(1, $sanitized['weekday'], 'invalid weekday falls back to Monday');
        eq(30, $sanitized['run_retention_days'], 'invalid retention falls back to 30 days');
        eq(false, $sanitized['jobs']['log_rotation'], 'unchecked job toggles are disabled');
        eq(false, $sanitized['jobs']['integrity'], 'integrity job defaults to disabled when not posted');
        check(is_string($sanitized['enabled_at']) && $sanitized['enabled_at'] !== '', 'enabling maintenance records enabled_at');

        $settings->save([
            '_maintenance_form' => 1,
            'enabled' => '1',
            'frequency' => 'weekly',
            'time' => '04:15',
            'timezone' => 'Europe/Paris',
            'weekday' => 7,
            'run_retention_days' => 14,
            'jobs' => ['torrent_db' => '1', 'log_rotation' => '1', 'integrity' => '1'],
        ]);
        $reloaded = (new MaintenanceSettings($store))->all();
        eq('weekly', $reloaded['frequency'], 'frequency is persisted');
        eq('04:15', $reloaded['time'], 'time is persisted');
        eq('Europe/Paris', $reloaded['timezone'], 'timezone is persisted');
        eq(7, $reloaded['weekday'], 'weekday is persisted');
        eq(14, $reloaded['run_retention_days'], 'retention is persisted');
        eq(true, $reloaded['jobs']['torrent_db'], 'torrent job toggle is persisted');
        eq(true, $reloaded['jobs']['log_rotation'], 'log rotation toggle is persisted');
        eq(true, $reloaded['jobs']['integrity'], 'integrity toggle is persisted');

        $settings->save(['enabled' => false]);
        eq(null, (new MaintenanceSettings($store))->all()['enabled_at'], 'disabling maintenance clears enabled_at');

        echo "Worker pause flag\n";
        $pauseStorage = storageAt(tempDir('maintenance-pause'));
        $pausePath = $pauseStorage->getStorageDir() . '/maintenance.pause';
        eq(['paused' => false], DownloadWorkerCommand::maintenancePauseState($pauseStorage), 'worker is not paused without a flag');
        file_put_contents($pausePath, json_encode(['job' => 'torrent_db', 'started_at' => gmdate('c')]));
        $pauseState = DownloadWorkerCommand::maintenancePauseState($pauseStorage);
        eq(true, $pauseState['paused'], 'worker pauses while the maintenance flag exists');
        eq('torrent_db', $pauseState['job'], 'pause state exposes the running job');
        unlink($pausePath);
        eq(['paused' => false], DownloadWorkerCommand::maintenancePauseState($pauseStorage), 'worker resumes when the flag is removed');
        file_put_contents($pausePath, json_encode(['job' => 'torrent_db', 'started_at' => '2020-01-01T00:00:00+00:00']));
        eq(['paused' => false, 'stale' => true], DownloadWorkerCommand::maintenancePauseState($pauseStorage), 'stale pause flags are ignored and removed');
        check(!is_file($pausePath), 'stale pause flag is deleted');

        echo "Store\n";
        $dbStore = new MaintenanceStore(projectDir('maintenance-store'));
        $dbStore->initialize();
        check(is_file($dbStore->getDbPath()), 'maintenance database is created');
        eq('0600', substr(sprintf('%o', fileperms($dbStore->getDbPath())), -4), 'maintenance database permissions are 0600');
        $tables = $dbStore->getPdo()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        check(in_array('settings', $tables, true), 'settings table exists');
        check(in_array('maintenance_runs', $tables, true), 'maintenance_runs table exists');

        $runId = $dbStore->startRun('torrent_db', 'manual', true);
        $dbStore->finishRun($runId, 'success', ['inserted' => 2, 'ignored' => 1], null, 1.25);
        $run = $dbStore->getRun($runId);
        eq('torrent_db', $run['job_key'], 'run stores the job key');
        eq('success', $run['status'], 'run stores the final status');
        eq(true, $run['forced'], 'run stores the forced flag');
        eq(2, $run['summary']['inserted'], 'run stores the summary');
        eq(1.25, $run['duration'], 'run stores the duration');
        $last = $dbStore->getLastRun('torrent_db');
        eq($runId, (int) $last['id'], 'last run is returned for a job');

        $oldRun = $dbStore->startRun('log_rotation', 'schedule', false);
        $dbStore->finishRun($oldRun, 'success', [], null, 0.01);
        $dbStore->getPdo()->prepare('UPDATE maintenance_runs SET started_at = :started WHERE id = :id')
            ->execute([':started' => '2020-01-01 00:00:00', ':id' => $oldRun]);
        $purged = $dbStore->purgeRunsOlderThan(30, new DateTimeImmutable('2026-06-15 00:00:00', $utc));
        eq(1, $purged, 'old maintenance runs are purged');
        check($dbStore->getRun($oldRun) === null, 'purged run no longer exists');
        check($dbStore->getRun($runId) !== null, 'recent run is kept');

        $retry = new ReflectionMethod(MaintenanceStore::class, 'withRetry');
        $retry->setAccessible(true);
        $attempts = 0;
        $retryResult = $retry->invoke($dbStore, function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new PDOException('database is locked');
            }

            return 'ok';
        });
        eq('ok', $retryResult, 'busy retry eventually succeeds');
        eq(3, $attempts, 'busy retry retries SQLITE_BUSY errors');

        echo "Torrent database updater\n";
        $updaterProject = projectDir('maintenance-updater');
        $vendorDir = $updaterProject . '/vendor/gautardos/hash-db';
        mkdir($vendorDir, 0777, true);
        createTorrentSchema($vendorDir . '/torrents.db');
        file_put_contents($vendorDir . '/tos_all_hash.txt', "Movie A###hashA\nMovie A duplicate###hashA\nMovie B###hashB\n");
        $updater = new TorrentDatabaseUpdater($updaterProject);
        $first = $updater->update(false);
        eq('success', $first['status'], 'first import succeeds');
        eq(2, $first['inserted'], 'duplicate CSV rows insert one hash');
        eq(1, $first['ignored'], 'duplicate CSV rows are ignored');
        eq(false, $first['diagnostics']['composer'], 'nightly import does not run Composer');
        eq(2, countTorrents($updaterProject . '/var/db/torrents.db'), 'imported rows are present in the torrent database');
        check(uniqueIndexExists($updaterProject . '/var/db/torrents.db', 'idx_hash_info'), 'unique idx_hash_info index is created');

        $second = $updater->update(false);
        eq('success', $second['status'], 'second import succeeds');
        eq(0, $second['inserted'], 'second import is idempotent');
        eq(3, $second['ignored'], 'second import ignores every existing hash');

        $missingProject = projectDir('maintenance-updater-missing');
        $missing = (new TorrentDatabaseUpdater($missingProject))->update(false);
        eq('error', $missing['status'], 'missing vendor source returns an error');
        check(str_contains(strtolower((string) ($missing['error'] ?? '')), 'source not found'), 'missing source error is explicit');
        eq(false, $missing['diagnostics']['composer'], 'missing source never triggers Composer');

        $rollbackProject = projectDir('maintenance-updater-rollback');
        $rollbackVendor = $rollbackProject . '/vendor/gautardos/hash-db';
        mkdir($rollbackVendor, 0777, true);
        createTorrentSchema($rollbackVendor . '/torrents.db');
        file_put_contents($rollbackVendor . '/tos_all_hash.txt', "Good row###hashgood\nBroken row###\n");
        $rollback = (new TorrentDatabaseUpdater($rollbackProject))->update(false);
        eq('error', $rollback['status'], 'invalid CSV line returns an error');
        eq(0, countTorrents($rollbackProject . '/var/db/torrents.db'), 'transaction rolls back every row on a CSV error');
        check(uniqueIndexExists($rollbackProject . '/var/db/torrents.db', 'idx_hash_info'), 'unique index survives a rolled back import');

        echo "Torrent database job\n";
        $jobProject = projectDir('maintenance-torrent-job');
        $jobVendor = $jobProject . '/vendor/gautardos/hash-db';
        mkdir($jobVendor, 0777, true);
        createTorrentSchema($jobVendor . '/torrents.db');
        file_put_contents($jobVendor . '/tos_all_hash.txt', "One###hash1\nTwo###hash2\n");
        $jobStore = new MaintenanceStore($jobProject);
        $jobSettings = new MaintenanceSettings($jobStore);
        $torrentJob = new TorrentDatabaseUpdateJob(new TorrentDatabaseUpdater($jobProject), $jobSettings);
        $jobSummary = $torrentJob->run(static function (string $message): void {
        });
        eq('success', $jobSummary['status'], 'torrent database job reports success');
        eq(2, $jobSummary['inserted'], 'torrent database job reports inserted rows');
        eq(false, $jobSummary['diagnostics']['composer'], 'torrent database job uses installed files only');

        echo "Runner\n";
        $runnerProject = projectDir('maintenance-runner');
        $runnerStore = new MaintenanceStore($runnerProject);
        $runnerSettings = new MaintenanceSettings($runnerStore);
        storeWithSettings($runnerStore, $runnerSettings, [
            'enabled' => true,
            'time' => '03:00',
            'timezone' => 'UTC',
            'days' => [1, 2, 3, 4, 5, 6, 7],
            'enabled_at' => '2026-06-01T00:00:00+00:00',
            'jobs' => ['torrent_db' => true, 'log_rotation' => true],
        ]);
        $runnerStorage = storageAt($runnerProject . '/var/storage');
        $runnerNow = new DateTimeImmutable('2026-06-15 04:00:00', $utc);
        $torrentFake = new FakeMaintenanceJob('torrent_db', 'Torrent database', true, ['status' => 'success', 'inserted' => 4]);
        $rotationFake = new FakeMaintenanceJob('log_rotation', 'Housekeeping', true, ['status' => 'success', 'rotated' => 0]);
        $runner = makeRunner($runnerStore, $runnerSettings, $scheduler, [$torrentFake, $rotationFake], $runnerStorage, $runnerNow);

        $due = $runner->run();
        eq(true, $due['success'], 'scheduled runner succeeds');
        eq(2, count($due['runs']), 'scheduled runner selects every due job');
        eq(1, $torrentFake->runCount, 'torrent job ran once');
        eq(1, $rotationFake->runCount, 'log rotation job ran once');
        check(is_file($runnerStore->getLogPath()), 'maintenance log file is written');
        check(str_contains((string) file_get_contents($runnerStore->getLogPath()), 'Starting maintenance job'), 'maintenance log contains run start lines');
        $recorded = $runnerStore->getLastRun('torrent_db');
        eq('success', $recorded['status'], 'runner records success');
        eq(4, $recorded['summary']['inserted'], 'runner records the job summary');

        $again = $runner->run();
        eq(0, count($again['runs']), 'scheduled runner does not run twice in the same window');
        $notDue = array_values(array_filter($again['skipped'], static fn (array $skip): bool => ($skip['reason'] ?? '') === 'not_due'));
        eq(2, count($notDue), 'both jobs are reported as not due');

        $forced = $runner->run(['force' => true]);
        eq(true, $forced['success'], 'forced runner succeeds');
        eq(2, count($forced['runs']), 'forced runner bypasses the schedule');
        eq(2, $torrentFake->runCount, 'forced runner executes the torrent job again');

        $filtered = $runner->run(['force' => true, 'job' => 'torrent_db']);
        eq(1, count($filtered['runs']), 'job filter limits the run to one job');
        eq('torrent_db', $filtered['runs'][0]['job'], 'job filter keeps the requested job');
        eq(2, $rotationFake->runCount, 'job filter skips the other job');

        $dryRun = $runner->run(['force' => true, 'dry_run' => true]);
        eq(true, $dryRun['dry_run'], 'dry run is reported');
        eq(['torrent_db', 'log_rotation'], $dryRun['planned'], 'dry run lists the jobs that would run');
        eq(3, $torrentFake->runCount, 'dry run does not execute jobs');

        $lockPath = $runnerStore->getLockPath();
        $lockHandle = fopen($lockPath, 'c+');
        flock($lockHandle, LOCK_EX | LOCK_NB);
        $locked = $runner->run(['force' => true]);
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        eq(true, $locked['locked'], 'a concurrent run is rejected by the lock');
        eq(0, count($locked['runs']), 'a locked run records no runs');
        eq(3, $torrentFake->runCount, 'a locked run does not execute jobs');

        $errorFake = new FakeMaintenanceJob('torrent_db', 'Broken job', true, ['status' => 'success'], new RuntimeException('boom'));
        $errorRunner = makeRunner($runnerStore, $runnerSettings, $scheduler, [$errorFake], $runnerStorage, $runnerNow);
        $failed = $errorRunner->run(['force' => true]);
        eq(false, $failed['success'], 'job exceptions make the run fail');
        eq('error', $failed['runs'][0]['status'], 'job exceptions are marked as errors');
        $failedRun = $runnerStore->getLastRun('torrent_db');
        eq('error', $failedRun['status'], 'errors are persisted in run history');
        check(str_contains((string) $failedRun['error'], 'boom'), 'error message is persisted');
        $notifications = $runnerStorage->get('server_notifications', []);
        check(is_array($notifications) && $notifications !== [], 'failed maintenance runs push a server notification');
        check(str_contains((string) ($notifications[0]['message'] ?? ''), 'Maintenance job failed'), 'notification labels the maintenance failure');

        echo "Log rotation job\n";
        $rotationProject = projectDir('maintenance-rotation');
        $rotationStore = new MaintenanceStore($rotationProject);
        $rotationSettings = new MaintenanceSettings($rotationStore);
        storeWithSettings($rotationStore, $rotationSettings, ['run_retention_days' => 30]);
        $logsDir = $rotationStore->getStorageDir() . '/logs';
        mkdir($logsDir, 0777, true);
        $oversized = $logsDir . '/oversized.log';
        $handle = fopen($oversized, 'c');
        ftruncate($handle, LogRotator::MAX_BYTES + 128);
        fclose($handle);
        $cacheDir = $rotationProject . '/var/cache';
        mkdir($cacheDir, 0777, true);
        file_put_contents($cacheDir . '/keep.txt', 'keep');
        $oldRun = $rotationStore->startRun('torrent_db', 'schedule');
        $rotationStore->finishRun($oldRun, 'success');
        $rotationStore->getPdo()->prepare('UPDATE maintenance_runs SET started_at = :started WHERE id = :id')
            ->execute([':started' => '2020-01-01 00:00:00', ':id' => $oldRun]);
        $recentRun = $rotationStore->startRun('log_rotation', 'schedule');
        $rotationStore->finishRun($recentRun, 'success');
        $rotationJob = new LogRotationJob($rotationStore, $rotationSettings, new DateTimeImmutable('2026-06-15 00:00:00', $utc));
        $rotationSummary = $rotationJob->run(static function (string $message): void {
        });
        eq('success', $rotationSummary['status'], 'housekeeping job succeeds');
        check(is_file($oversized . '.1'), 'oversized log is rotated');
        check(!is_file($oversized), 'rotated log no longer exists at its original path');
        eq(1, $rotationSummary['purged_runs'], 'housekeeping purges old runs');
        check($rotationStore->getRun($oldRun) === null, 'old run is removed');
        check($rotationStore->getRun($recentRun) !== null, 'recent run is kept');
        check(is_file($cacheDir . '/keep.txt'), 'housekeeping never deletes the Symfony cache at runtime');

        echo "Command\n";
        $commandProject = projectDir('maintenance-command');
        $commandStore = new MaintenanceStore($commandProject);
        $commandSettings = new MaintenanceSettings($commandStore);
        storeWithSettings($commandStore, $commandSettings, [
            'enabled' => true,
            'time' => '03:00',
            'timezone' => 'UTC',
            'enabled_at' => '2026-06-01T00:00:00+00:00',
            'jobs' => ['torrent_db' => true, 'log_rotation' => true],
        ]);
        $commandStorage = storageAt($commandProject . '/var/storage');
        $commandFake = new FakeMaintenanceJob('torrent_db', 'Torrent database', true, ['status' => 'success']);
        $commandRunner = makeRunner(
            $commandStore,
            $commandSettings,
            $scheduler,
            [$commandFake],
            $commandStorage,
            new DateTimeImmutable('2026-06-15 04:00:00', $utc)
        );
        $command = new MaintenanceCommand($commandRunner);
        $application = new Application();
        $application->add($command);
        $tester = new CommandTester($command);
        $tester->execute(['--force' => true, '--job' => 'torrent_db', '--dry-run' => true]);
        eq(0, $tester->getStatusCode(), 'maintenance command dry run exits successfully');
        check(str_contains($tester->getDisplay(), 'torrent_db'), 'maintenance command prints the planned job');
        eq(0, $commandFake->runCount, 'maintenance command dry run does not execute the job');
        $tester->execute(['--force' => true, '--job' => 'torrent_db', '--once' => true]);
        eq(0, $tester->getStatusCode(), 'maintenance command once exits successfully');
        eq(1, $commandFake->runCount, 'maintenance command executes the job');
        check(str_contains($tester->getDisplay(), 'torrent_db'), 'maintenance command prints the executed job');
    }

    echo "Integrity maintenance job\n";
    $integrityProject = projectDir('maintenance-integrity');
    $integrityStore = new MaintenanceStore($integrityProject);
    $integritySettings = new MaintenanceSettings($integrityStore);
    storeWithSettings($integrityStore, $integritySettings, [
        'enabled' => true,
        'time' => '03:00',
        'timezone' => 'UTC',
        'enabled_at' => '2026-06-01T00:00:00+00:00',
        'jobs' => ['torrent_db' => false, 'log_rotation' => false, 'integrity' => true],
    ]);
    $integrityStorage = storageAt($integrityProject . '/var/storage');
    $integrityFake = new FakeMaintenanceJob('integrity', 'Integrity deep check', true, ['status' => 'success', 'checked' => 3]);
    $integrityRunner = makeRunner(
        $integrityStore,
        $integritySettings,
        $scheduler,
        [$integrityFake],
        $integrityStorage,
        new DateTimeImmutable('2026-06-15 04:00:00', $utc)
    );
    $integrityRun = $integrityRunner->run(['force' => true, 'job' => 'integrity']);
    eq(true, $integrityRun['success'], 'integrity maintenance job runs');
    eq('integrity', $integrityRun['runs'][0]['job'] ?? null, 'integrity job key is reported');
    eq('Integrity deep check', $integrityRun['runs'][0]['job_label'] ?? null, 'integrity job label is reported');
    eq(1, $integrityFake->runCount, 'integrity job executes once');

    echo "Route inventory\n";
    $routes = routerInventory();
    check($routes !== [], 'debug:router inventory is readable');
    foreach (['maintenance' => '/settings/maintenance', 'maintenance_settings' => '/settings/maintenance/settings', 'maintenance_run' => '/settings/maintenance/run', 'maintenance_status' => '/settings/maintenance/status'] as $name => $path) {
        check(isset($routes[$name]), 'route ' . $name . ' exists');
        if (isset($routes[$name])) {
            eq($path, $routes[$name]['path'] ?? null, $name . ' path is stable');
            check(str_contains((string) ($routes[$name]['defaults']['_controller'] ?? ''), 'MaintenanceController'), $name . ' maps to MaintenanceController');
        }
    }
    if (isset($routes['maintenance_settings'])) {
        check(str_contains(strtoupper((string) ($routes['maintenance_settings']['method'] ?? '')), 'POST'), 'maintenance_settings accepts POST');
    }
    if (isset($routes['maintenance_run'])) {
        check(str_contains(strtoupper((string) ($routes['maintenance_run']['method'] ?? '')), 'POST'), 'maintenance_run accepts POST');
    }
    check(isset($routes['logs']), 'logs route remains available from Maintenance');
    check(isset($routes['config_update_hash_db']), 'config_update_hash_db route still exists');
    if (isset($routes['config_update_hash_db'])) {
        eq('/config/update-hash-db', $routes['config_update_hash_db']['path'] ?? null, 'config_update_hash_db path is unchanged');
        check(str_contains(strtoupper((string) ($routes['config_update_hash_db']['method'] ?? '')), 'POST'), 'config_update_hash_db still accepts POST');
        check(str_contains((string) ($routes['config_update_hash_db']['defaults']['_controller'] ?? ''), '::updateHashDb'), 'config_update_hash_db maps to updateHashDb');
    }

    echo "Navigation\n";
    $navStorage = storageAt(tempDir('maintenance-nav'), ['api_key' => 'smoke', 'default_path' => '/tmp']);
    $navigation = new NavigationService($navStorage);
    $item = findItem($navigation->getNavigation('maintenance'), 'maintenance');
    check($item !== null, 'maintenance navigation item exists');
    if ($item !== null) {
        eq(true, $item['enabled'], 'maintenance navigation item is enabled');
        eq('maintenance', $item['route'], 'maintenance navigation item points to the maintenance route');
        eq(true, $item['active'], 'maintenance navigation item is active on maintenance');
    }
    foreach (['maintenance_settings', 'maintenance_run', 'maintenance_status'] as $route) {
        $routeItem = findItem($navigation->getNavigation($route), 'maintenance');
        check($routeItem !== null && $routeItem['active'] === true, 'maintenance navigation item is active on ' . $route);
    }
    $configurationItem = findItem($navigation->getNavigation('maintenance'), 'configuration');
    check($configurationItem !== null && $configurationItem['active'] === false, 'maintenance does not activate configuration');
} finally {
    foreach (glob(sys_get_temp_dir() . '/maintenance-*', GLOB_ONLYDIR) ?: [] as $dir) {
        removeTree($dir);
    }
}

echo "\n$passes passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
