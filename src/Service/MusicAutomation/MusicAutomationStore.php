<?php

namespace App\Service\MusicAutomation;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

class MusicAutomationStore
{
    private string $projectDir;
    private string $dbPath;
    private ?PDO $pdo = null;
    private bool $initialized = false;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
        $this->dbPath = $projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'music_automations.db';

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
        return $this->getStorageDir() . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'music_automations_worker.log';
    }

    public function getLockPath(): string
    {
        return $this->getStorageDir() . DIRECTORY_SEPARATOR . 'music_automations_worker.lock';
    }

    public function getHeartbeatPath(): string
    {
        return $this->getStorageDir() . DIRECTORY_SEPARATOR . 'music_automations_worker.heartbeat';
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
            'CREATE TABLE IF NOT EXISTS rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                playlist_id TEXT NOT NULL UNIQUE,
                playlist_url TEXT NOT NULL,
                name TEXT NOT NULL,
                owner TEXT NULL,
                visibility TEXT NOT NULL DEFAULT \'public\',
                frequency TEXT NOT NULL DEFAULT \'daily\',
                run_time TEXT NULL,
                weekday INTEGER NULL,
                timezone TEXT NOT NULL DEFAULT \'UTC\',
                enabled INTEGER NOT NULL DEFAULT 1,
                last_snapshot_id TEXT NULL,
                last_run_at TEXT NULL,
                next_run_at TEXT NULL,
                last_status TEXT NULL,
                last_summary TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                rule_id INTEGER NOT NULL,
                started_at TEXT NOT NULL,
                finished_at TEXT NULL,
                status TEXT NOT NULL DEFAULT \'running\',
                found INTEGER NOT NULL DEFAULT 0,
                new INTEGER NOT NULL DEFAULT 0,
                skipped INTEGER NOT NULL DEFAULT 0,
                queued INTEGER NOT NULL DEFAULT 0,
                error TEXT NULL,
                trigger TEXT NOT NULL DEFAULT \'schedule\',
                summary_json TEXT NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_music_rules_due ON rules (enabled, next_run_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_music_runs_rule ON runs (rule_id, started_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_music_runs_started ON runs (started_at)');
        $pdo->exec('PRAGMA user_version = 1');

        $this->tightenDatabasePermissions();
        $this->initialized = true;
    }

    public function createRule(array $data): int
    {
        $this->initialize();
        $playlistId = trim((string) ($data['playlist_id'] ?? ''));
        if ($playlistId === '') {
            throw new \InvalidArgumentException('Playlist ID is required.');
        }
        if ($this->getRuleByPlaylistId($playlistId) !== null) {
            throw new \InvalidArgumentException('A rule already exists for this playlist.');
        }

        $now = $this->utcNow();
        try {
            return $this->withRetry(function () use ($data, $playlistId, $now): int {
                $stmt = $this->getPdo()->prepare(
                    'INSERT INTO rules (
                        playlist_id, playlist_url, name, owner, visibility, frequency, run_time,
                        weekday, timezone, enabled, last_snapshot_id, last_run_at, next_run_at,
                        last_status, last_summary, created_at, updated_at
                    ) VALUES (
                        :playlist_id, :playlist_url, :name, :owner, :visibility, :frequency, :run_time,
                        :weekday, :timezone, :enabled, :last_snapshot_id, :last_run_at, :next_run_at,
                        :last_status, :last_summary, :created_at, :updated_at
                    )'
                );

                $stmt->execute([
                    ':playlist_id' => $playlistId,
                    ':playlist_url' => (string) ($data['playlist_url'] ?? 'https://open.spotify.com/playlist/' . $playlistId),
                    ':name' => (string) ($data['name'] ?? 'Playlist'),
                    ':owner' => (string) ($data['owner'] ?? ''),
                    ':visibility' => (string) ($data['visibility'] ?? 'public'),
                    ':frequency' => (string) ($data['frequency'] ?? 'daily'),
                    ':run_time' => $data['run_time'] ?? null,
                    ':weekday' => isset($data['weekday']) && $data['weekday'] !== null ? (int) $data['weekday'] : null,
                    ':timezone' => (string) ($data['timezone'] ?? 'UTC'),
                    ':enabled' => !empty($data['enabled']) ? 1 : 0,
                    ':last_snapshot_id' => $data['last_snapshot_id'] ?? null,
                    ':last_run_at' => $data['last_run_at'] ?? null,
                    ':next_run_at' => $data['next_run_at'] ?? null,
                    ':last_status' => $data['last_status'] ?? null,
                    ':last_summary' => $data['last_summary'] ?? null,
                    ':created_at' => $data['created_at'] ?? $now,
                    ':updated_at' => $now,
                ]);

                return (int) $this->getPdo()->lastInsertId();
            });
        } catch (\PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                throw new \InvalidArgumentException('A rule already exists for this playlist.');
            }
            throw $e;
        }
    }

    public function updateRule(int $id, array $data): bool
    {
        $this->initialize();
        $existing = $this->getRule($id);
        if ($existing === null) {
            return false;
        }

        $playlistId = array_key_exists('playlist_id', $data)
            ? trim((string) $data['playlist_id'])
            : null;
        if ($playlistId !== null && $playlistId !== '') {
            $conflict = $this->getRuleByPlaylistId($playlistId);
            if ($conflict !== null && (int) $conflict['id'] !== $id) {
                throw new \InvalidArgumentException('A rule already exists for this playlist.');
            }
        }

        $allowed = [
            'playlist_id', 'playlist_url', 'name', 'owner', 'visibility', 'frequency',
            'run_time', 'weekday', 'timezone', 'enabled', 'last_snapshot_id',
            'last_run_at', 'next_run_at', 'last_status', 'last_summary',
        ];
        $sets = [];
        $params = [':id' => $id, ':updated_at' => $this->utcNow()];
        foreach ($allowed as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $value = $data[$column];
            if ($column === 'enabled') {
                $value = !empty($value) ? 1 : 0;
            } elseif ($column === 'weekday' && $value !== null && $value !== '') {
                $value = (int) $value;
            }
            $sets[] = $column . ' = :' . $column;
            $params[':' . $column] = $value === '' && in_array($column, ['run_time', 'weekday'], true) ? null : $value;
        }

        $sets[] = 'updated_at = :updated_at';

        $this->withRetry(function () use ($sets, $params): void {
            $stmt = $this->getPdo()->prepare(
                'UPDATE rules SET ' . implode(', ', $sets) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        });

        return true;
    }

    public function deleteRule(int $id): bool
    {
        $this->initialize();

        return $this->withRetry(function () use ($id): bool {
            $stmt = $this->getPdo()->prepare('DELETE FROM rules WHERE id = :id');
            $stmt->execute([':id' => $id]);

            return $stmt->rowCount() > 0;
        });
    }

    public function getRule(int $id): ?array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare('SELECT * FROM rules WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrateRule($row) : null;
    }

    public function getRuleByPlaylistId(string $playlistId): ?array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare('SELECT * FROM rules WHERE playlist_id = :playlist_id');
        $stmt->execute([':playlist_id' => trim($playlistId)]);
        $row = $stmt->fetch();

        return $row ? $this->hydrateRule($row) : null;
    }

    public function getRules(bool $includeDisabled = true): array
    {
        $this->initialize();
        $sql = 'SELECT * FROM rules' . ($includeDisabled ? '' : ' WHERE enabled = 1');
        $sql .= ' ORDER BY name COLLATE NOCASE';
        $rows = $this->getPdo()->query($sql)->fetchAll();

        return array_map([$this, 'hydrateRule'], $rows);
    }

    public function getDueRules(?string $nowUtc = null, ?int $ruleId = null): array
    {
        $this->initialize();
        $now = $nowUtc ?? $this->utcNow();
        $sql = 'SELECT * FROM rules WHERE enabled = 1 AND next_run_at IS NOT NULL AND next_run_at <= :now';
        $params = [':now' => $now];
        if ($ruleId !== null) {
            $sql .= ' AND id = :id';
            $params[':id'] = $ruleId;
        }
        $sql .= ' ORDER BY next_run_at ASC';
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'hydrateRule'], $stmt->fetchAll());
    }

    public function startRun(int $ruleId, string $trigger = 'schedule'): int
    {
        $this->initialize();

        return $this->withRetry(function () use ($ruleId, $trigger): int {
            $stmt = $this->getPdo()->prepare(
                'INSERT INTO runs (rule_id, started_at, status, trigger) VALUES (:rule_id, :started_at, :status, :trigger)'
            );
            $stmt->execute([
                ':rule_id' => $ruleId,
                ':started_at' => $this->utcNow(),
                ':status' => 'running',
                ':trigger' => $trigger,
            ]);

            return (int) $this->getPdo()->lastInsertId();
        });
    }

    public function finishRun(int $runId, array $stats, string $status, ?string $error = null): void
    {
        $this->initialize();
        $this->withRetry(function () use ($runId, $stats, $status, $error): void {
            $stmt = $this->getPdo()->prepare(
                'UPDATE runs SET
                    finished_at = :finished_at,
                    status = :status,
                    found = :found,
                    new = :new,
                    skipped = :skipped,
                    queued = :queued,
                    error = :error,
                    summary_json = :summary_json
                 WHERE id = :id'
            );
            $stmt->execute([
                ':finished_at' => $this->utcNow(),
                ':status' => $status,
                ':found' => (int) ($stats['found'] ?? 0),
                ':new' => (int) ($stats['new'] ?? 0),
                ':skipped' => (int) ($stats['skipped'] ?? 0),
                ':queued' => (int) ($stats['queued'] ?? 0),
                ':error' => $error,
                ':summary_json' => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':id' => $runId,
            ]);
        });
    }

    public function getRuns(int $limit = 20, ?int $ruleId = null): array
    {
        $this->initialize();
        $limit = max(1, min($limit, 200));
        if ($ruleId !== null) {
            $stmt = $this->getPdo()->prepare('SELECT * FROM runs WHERE rule_id = :rule_id ORDER BY id DESC LIMIT ' . $limit);
            $stmt->execute([':rule_id' => $ruleId]);
        } else {
            $stmt = $this->getPdo()->query('SELECT * FROM runs ORDER BY id DESC LIMIT ' . $limit);
        }
        $rows = $stmt->fetchAll();

        return array_map([$this, 'hydrateRun'], $rows);
    }

    public function markStaleRuns(int $seconds = 7200): int
    {
        $this->initialize();
        $threshold = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . max(60, $seconds) . ' seconds')
            ->format('Y-m-d H:i:s');
        return $this->withRetry(function () use ($threshold): int {
            $stmt = $this->getPdo()->prepare(
                'UPDATE runs
                 SET status = :status, finished_at = :finished_at, error = COALESCE(error, :message)
                 WHERE status = :running AND started_at < :threshold'
            );
            $stmt->execute([
                ':status' => 'error',
                ':finished_at' => $this->utcNow(),
                ':message' => 'Music automation run interrupted or stale.',
                ':running' => 'running',
                ':threshold' => $threshold,
            ]);

            return $stmt->rowCount();
        });
    }

    public function purgeRunsOlderThan(int $days): int
    {
        $this->initialize();
        $days = max(1, $days);
        $threshold = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $days . ' days')
            ->format('Y-m-d H:i:s');
        return $this->withRetry(function () use ($threshold): int {
            $stmt = $this->getPdo()->prepare('DELETE FROM runs WHERE started_at < :threshold');
            $stmt->execute([':threshold' => $threshold]);

            return $stmt->rowCount();
        });
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

    private function hydrateRule(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['weekday'] = $row['weekday'] !== null ? (int) $row['weekday'] : null;
        $row['enabled'] = (bool) $row['enabled'];

        return $row;
    }

    private function hydrateRun(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['rule_id'] = (int) $row['rule_id'];
        $row['found'] = (int) $row['found'];
        $row['new'] = (int) $row['new'];
        $row['skipped'] = (int) $row['skipped'];
        $row['queued'] = (int) $row['queued'];
        $row['summary'] = [];
        if (!empty($row['summary_json'])) {
            $decoded = json_decode((string) $row['summary_json'], true);
            if (is_array($decoded)) {
                $row['summary'] = $decoded;
            }
        }

        return $row;
    }

    public function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
