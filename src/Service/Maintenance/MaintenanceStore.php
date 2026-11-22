<?php

namespace App\Service\Maintenance;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

class MaintenanceStore
{
    private string $projectDir;
    private string $dbPath;
    private ?PDO $pdo = null;
    private bool $initialized = false;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
        $this->dbPath = $projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'maintenance.db';

        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    public function getDbPath(): string
    {
        return $this->dbPath;
    }

    public function getStorageDir(): string
    {
        return $this->projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'storage';
    }

    public function getLogPath(): string
    {
        return $this->getStorageDir() . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'maintenance.log';
    }

    public function getLockPath(): string
    {
        return $this->getStorageDir() . DIRECTORY_SEPARATOR . 'maintenance.lock';
    }

    public function getPausePath(): string
    {
        return $this->getStorageDir() . DIRECTORY_SEPARATOR . 'maintenance.pause';
    }

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO('sqlite:' . $this->dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA busy_timeout = 15000');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
            $this->tightenDatabasePermissions();
        }

        return $this->pdo;
    }

    private function tightenDatabasePermissions(): void
    {
        @chmod($this->dbPath, 0600);
        @chmod($this->dbPath . '-wal', 0600);
        @chmod($this->dbPath . '-shm', 0600);
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $pdo = $this->getPdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS maintenance_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_key TEXT NOT NULL,
                trigger TEXT NOT NULL DEFAULT \'schedule\',
                forced INTEGER NOT NULL DEFAULT 0,
                started_at TEXT NOT NULL,
                finished_at TEXT NULL,
                status TEXT NOT NULL DEFAULT \'running\',
                duration REAL NULL,
                summary_json TEXT NULL,
                error TEXT NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_maintenance_runs_job ON maintenance_runs (job_key, started_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_maintenance_runs_started ON maintenance_runs (started_at)');
        $pdo->exec('PRAGMA user_version = 1');

        $this->tightenDatabasePermissions();
        $this->initialized = true;
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();

        if (!$row) {
            return $default;
        }

        $decoded = json_decode((string) $row['value'], true);
        return $decoded === null && strtolower((string) $row['value']) !== 'null' ? $default : $decoded;
    }

    public function setSetting(string $key, mixed $value): void
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );
        $this->withRetry(function () use ($stmt, $key, $value) {
            $stmt->execute([
                ':key' => $key,
                ':value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':updated_at' => $this->utcNow(),
            ]);
        });
    }

    public function allSettings(): array
    {
        $this->initialize();
        $rows = $this->getPdo()->query('SELECT key, value FROM settings')->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[(string) $row['key']] = json_decode((string) $row['value'], true);
        }

        return $settings;
    }

    public function startRun(string $jobKey, string $trigger = 'schedule', bool $forced = false): int
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'INSERT INTO maintenance_runs (job_key, trigger, forced, started_at, status)
             VALUES (:job_key, :trigger, :forced, :started_at, :status)'
        );

        return $this->withRetry(function () use ($stmt, $jobKey, $trigger, $forced) {
            $stmt->execute([
                ':job_key' => $jobKey,
                ':trigger' => $trigger,
                ':forced' => $forced ? 1 : 0,
                ':started_at' => $this->utcNow(),
                ':status' => 'running',
            ]);

            return (int) $this->getPdo()->lastInsertId();
        });
    }

    public function finishRun(
        int $runId,
        string $status,
        array $summary = [],
        ?string $error = null,
        ?float $duration = null
    ): void {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'UPDATE maintenance_runs
             SET finished_at = :finished_at,
                 status = :status,
                 duration = :duration,
                 summary_json = :summary_json,
                 error = :error
             WHERE id = :id'
        );

        $this->withRetry(function () use ($stmt, $runId, $status, $summary, $error, $duration) {
            $stmt->execute([
                ':id' => $runId,
                ':finished_at' => $this->utcNow(),
                ':status' => $status,
                ':duration' => $duration,
                ':summary_json' => json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':error' => $error,
            ]);
        });
    }

    public function getRun(int $runId): ?array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare('SELECT * FROM maintenance_runs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $runId]);

        return $this->hydrateRun($stmt->fetch() ?: null);
    }

    public function getLastRun(string $jobKey): ?array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'SELECT * FROM maintenance_runs WHERE job_key = :job_key ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':job_key' => $jobKey]);
        $row = $stmt->fetch();

        return $this->hydrateRun($row ?: null);
    }

    public function getRecentRuns(int $limit = 20, ?string $jobKey = null): array
    {
        $this->initialize();
        $limit = max(1, min(200, $limit));

        if ($jobKey !== null && $jobKey !== '') {
            $stmt = $this->getPdo()->prepare(
                'SELECT * FROM maintenance_runs WHERE job_key = :job_key ORDER BY id DESC LIMIT :limit'
            );
            $stmt->bindValue(':job_key', $jobKey);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        } else {
            $stmt = $this->getPdo()->prepare('SELECT * FROM maintenance_runs ORDER BY id DESC LIMIT :limit');
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();

        return array_values(array_map(fn ($row) => $this->hydrateRun($row), $stmt->fetchAll()));
    }

    public function purgeRunsOlderThan(int $days, ?DateTimeImmutable $now = null): int
    {
        $this->initialize();
        $days = max(1, $days);
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $threshold = $now->setTimezone(new DateTimeZone('UTC'))->modify('-' . $days . ' days')->format('Y-m-d H:i:s');

        return $this->withRetry(function () use ($threshold) {
            $stmt = $this->getPdo()->prepare('DELETE FROM maintenance_runs WHERE started_at < :threshold');
            $stmt->execute([':threshold' => $threshold]);

            return $stmt->rowCount();
        });
    }

    public function markStaleRuns(): int
    {
        $this->initialize();
        $threshold = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-2 hours')
            ->format('Y-m-d H:i:s');

        return $this->withRetry(function () use ($threshold) {
            $stmt = $this->getPdo()->prepare(
                'UPDATE maintenance_runs
                 SET status = :status, finished_at = :finished_at,
                     error = COALESCE(error, :message)
                 WHERE status = :running AND started_at < :threshold'
            );
            $stmt->execute([
                ':status' => 'error',
                ':finished_at' => $this->utcNow(),
                ':message' => 'Maintenance run interrupted or stale.',
                ':running' => 'running',
                ':threshold' => $threshold,
            ]);

            return $stmt->rowCount();
        });
    }

    private function hydrateRun(?array $row): ?array
    {
        if (!$row) {
            return null;
        }

        $row['forced'] = (bool) ($row['forced'] ?? false);
        $row['summary'] = [];
        if (!empty($row['summary_json'])) {
            $decoded = json_decode((string) $row['summary_json'], true);
            if (is_array($decoded)) {
                $row['summary'] = $decoded;
            }
        }

        return $row;
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function withRetry(callable $callback): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $callback();
            } catch (\PDOException $e) {
                $attempt++;
                if (!$this->isBusy($e) || $attempt >= 7) {
                    throw $e;
                }
                usleep(50000 * (2 ** ($attempt - 1)));
            }
        }
    }

    private function isBusy(\PDOException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;
        $message = strtolower($e->getMessage());

        return in_array($driverCode, [5, 6], true)
            || str_contains($message, 'database is locked')
            || str_contains($message, 'database table is locked');
    }
}
