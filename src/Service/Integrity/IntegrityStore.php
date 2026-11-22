<?php

namespace App\Service\Integrity;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

class IntegrityStore
{
    private string $projectDir;
    private string $dbPath;
    private ?PDO $pdo = null;
    private bool $initialized = false;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
        $this->dbPath = $projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'integrity.db';

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
        $paths = [$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'];

        foreach ($paths as $path) {
            @chmod($path, 0600);
        }

        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }

        $dir = dirname($this->dbPath);
        $dirUid = @fileowner($dir);
        $dirGid = @filegroup($dir);
        if ($dirUid === false || $dirGid === false) {
            return;
        }

        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            if (@fileowner($path) !== $dirUid) {
                @chown($path, $dirUid);
            }
            if (@filegroup($path) !== $dirGid) {
                @chgrp($path, $dirGid);
            }
        }
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $pdo = $this->getPdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS integrity_checks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                check_key TEXT NOT NULL UNIQUE,
                path TEXT NOT NULL,
                download_id TEXT NULL,
                filename TEXT NULL,
                type TEXT NOT NULL DEFAULT \'video\',
                expected_size INTEGER NOT NULL DEFAULT 0,
                actual_size INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL,
                reason TEXT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                last_checked_at TEXT NULL,
                last_requeued_at TEXT NULL,
                extra_json TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_integrity_checks_status ON integrity_checks (status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_integrity_checks_download ON integrity_checks (download_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_integrity_checks_path ON integrity_checks (path)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_integrity_checks_checked ON integrity_checks (last_checked_at)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS integrity_settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $pdo->exec('PRAGMA user_version = 1');

        $this->tightenDatabasePermissions();
        $this->initialized = true;
    }

    public function recordCheck(array $data): int
    {
        $this->initialize();
        $now = $this->utcNow();
        $checkKey = trim((string) ($data['check_key'] ?? ''));
        if ($checkKey === '') {
            $downloadId = trim((string) ($data['download_id'] ?? ''));
            $checkKey = $downloadId !== ''
                ? 'download:' . $downloadId
                : 'path:' . (string) ($data['path'] ?? '');
        }

        $path = trim((string) ($data['path'] ?? ''));
        $existing = $path !== '' ? $this->findCheckByPath($path) : null;
        if ($existing === null) {
            $existing = $this->findCheck($checkKey);
        }
        if ($existing !== null) {
            $checkKey = (string) $existing['check_key'];
        }

        $extra = $data['extra'] ?? [];
        $extraJson = is_string($extra) ? $extra : json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = $this->getPdo()->prepare(
            'INSERT INTO integrity_checks
                (check_key, path, download_id, filename, type, expected_size, actual_size, status, reason,
                 attempts, last_checked_at, last_requeued_at, extra_json, created_at, updated_at)
             VALUES
                (:check_key, :path, :download_id, :filename, :type, :expected_size, :actual_size, :status, :reason,
                 :attempts, :last_checked_at, :last_requeued_at, :extra_json, :created_at, :updated_at)
             ON CONFLICT(check_key) DO UPDATE SET
                path = excluded.path,
                download_id = excluded.download_id,
                filename = excluded.filename,
                type = excluded.type,
                expected_size = excluded.expected_size,
                actual_size = excluded.actual_size,
                status = excluded.status,
                reason = excluded.reason,
                last_checked_at = excluded.last_checked_at,
                extra_json = excluded.extra_json,
                updated_at = excluded.updated_at'
        );

        $this->withRetry(function () use ($stmt, $data, $checkKey, $extraJson, $now) {
            $stmt->execute([
                ':check_key' => $checkKey,
                ':path' => (string) ($data['path'] ?? ''),
                ':download_id' => (string) ($data['download_id'] ?? ''),
                ':filename' => (string) ($data['filename'] ?? ''),
                ':type' => (string) ($data['type'] ?? 'video'),
                ':expected_size' => (int) ($data['expected_size'] ?? 0),
                ':actual_size' => (int) ($data['actual_size'] ?? 0),
                ':status' => (string) ($data['status'] ?? 'healthy'),
                ':reason' => (string) ($data['reason'] ?? ''),
                ':attempts' => (int) ($data['attempts'] ?? 0),
                ':last_checked_at' => (string) ($data['last_checked_at'] ?? $now),
                ':last_requeued_at' => $data['last_requeued_at'] ?? null,
                ':extra_json' => $extraJson,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        });

        $find = $this->getPdo()->prepare('SELECT id FROM integrity_checks WHERE check_key = :check_key LIMIT 1');
        $find->execute([':check_key' => $checkKey]);
        $id = $find->fetchColumn();

        return $id !== false ? (int) $id : 0;
    }

    public function getCheck(int $id): ?array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare('SELECT * FROM integrity_checks WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $this->hydrate($stmt->fetch() ?: null);
    }

    public function findCheck(string $identifier): ?array
    {
        $this->initialize();
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (ctype_digit($identifier)) {
            $check = $this->getCheck((int) $identifier);
            if ($check !== null) {
                return $check;
            }
        }

        $stmt = $this->getPdo()->prepare('SELECT * FROM integrity_checks WHERE check_key = :key LIMIT 1');
        $stmt->execute([':key' => $identifier]);
        $row = $stmt->fetch();
        if ($row) {
            return $this->hydrate($row);
        }

        $stmt = $this->getPdo()->prepare(
            'SELECT * FROM integrity_checks WHERE download_id = :download_id ORDER BY last_checked_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([':download_id' => $identifier]);

        return $this->hydrate($stmt->fetch() ?: null);
    }

    public function findCheckByPath(string $path): ?array
    {
        $this->initialize();
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $stmt = $this->getPdo()->prepare(
            'SELECT * FROM integrity_checks WHERE path = :path ORDER BY last_checked_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([':path' => $path]);

        return $this->hydrate($stmt->fetch() ?: null);
    }

    public function getChecks(array $filters = [], int $limit = 200, int $offset = 0): array
    {
        $this->initialize();
        $limit = max(1, min(2000, $limit));
        $offset = max(0, $offset);

        $where = [];
        $params = [];

        $status = $filters['status'] ?? null;
        if (is_array($status) && $status !== []) {
            $placeholders = [];
            foreach (array_values($status) as $index => $value) {
                $placeholder = ':status' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = (string) $value;
            }
            $where[] = 'status IN (' . implode(', ', $placeholders) . ')';
        } elseif (is_string($status) && $status !== '') {
            $where[] = 'status = :status';
            $params[':status'] = $status;
        }

        if (!empty($filters['requeued'])) {
            $where[] = 'last_requeued_at IS NOT NULL';
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(filename LIKE :search OR path LIKE :search OR reason LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        $sql = 'SELECT * FROM integrity_checks';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY last_checked_at DESC, id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->getPdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map(fn ($row) => $this->hydrate($row), $stmt->fetchAll()));
    }

    public function countChecks(): int
    {
        $this->initialize();

        return (int) $this->getPdo()->query('SELECT COUNT(*) FROM integrity_checks')->fetchColumn();
    }

    public function getSummary(): array
    {
        $this->initialize();
        $summary = [
            'total' => 0,
            'healthy' => 0,
            'incomplete' => 0,
            'corrupted' => 0,
            'missing' => 0,
            'outside_root' => 0,
            'unsupported' => 0,
            'requeued' => 0,
        ];

        $rows = $this->getPdo()->query('SELECT status, COUNT(*) AS total FROM integrity_checks GROUP BY status')->fetchAll();
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? 'other');
            $count = (int) ($row['total'] ?? 0);
            $summary['total'] += $count;
            if (array_key_exists($status, $summary)) {
                $summary[$status] = $count;
            }
        }

        $summary['requeued'] = (int) $this->getPdo()
            ->query('SELECT COUNT(*) FROM integrity_checks WHERE last_requeued_at IS NOT NULL')
            ->fetchColumn();

        return $summary;
    }

    public function updateCheck(int $id, array $fields): void
    {
        $this->initialize();
        $allowed = [
            'path', 'download_id', 'filename', 'type', 'expected_size', 'actual_size',
            'status', 'reason', 'attempts', 'last_checked_at', 'last_requeued_at', 'extra_json',
        ];
        $sets = [];
        $params = [':id' => $id, ':updated_at' => $this->utcNow()];

        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $sets[] = $key . ' = :' . $key;
            if ($key === 'extra_json' && is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $params[':' . $key] = $value;
        }

        if ($sets === []) {
            return;
        }

        $sets[] = 'updated_at = :updated_at';
        $stmt = $this->getPdo()->prepare('UPDATE integrity_checks SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $this->withRetry(fn () => $stmt->execute($params));
    }

    public function purgeOldChecks(int $days, ?DateTimeImmutable $now = null): int
    {
        $this->initialize();
        $days = max(1, $days);
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $threshold = $now->setTimezone(new DateTimeZone('UTC'))->modify('-' . $days . ' days')->format('Y-m-d H:i:s');

        $stmt = $this->getPdo()->prepare('DELETE FROM integrity_checks WHERE last_checked_at IS NOT NULL AND last_checked_at < :threshold');

        return $this->withRetry(function () use ($stmt, $threshold) {
            $stmt->execute([':threshold' => $threshold]);

            return $stmt->rowCount();
        });
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        if (!is_file($this->dbPath)) {
            return $default;
        }

        $this->initialize();
        $stmt = $this->getPdo()->prepare('SELECT value FROM integrity_settings WHERE key = :key');
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
            'INSERT INTO integrity_settings (key, value, updated_at) VALUES (:key, :value, :updated_at)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );
        $this->withRetry(fn () => $stmt->execute([
            ':key' => $key,
            ':value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':updated_at' => $this->utcNow(),
        ]));
    }

    private function hydrate(?array $row): ?array
    {
        if (!$row) {
            return null;
        }

        $row['id'] = (int) ($row['id'] ?? 0);
        $row['expected_size'] = (int) ($row['expected_size'] ?? 0);
        $row['actual_size'] = (int) ($row['actual_size'] ?? 0);
        $row['attempts'] = (int) ($row['attempts'] ?? 0);
        $row['extra'] = [];
        if (!empty($row['extra_json'])) {
            $decoded = json_decode((string) $row['extra_json'], true);
            if (is_array($decoded)) {
                $row['extra'] = $decoded;
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
