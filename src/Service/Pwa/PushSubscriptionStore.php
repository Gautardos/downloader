<?php

namespace App\Service\Pwa;

use PDO;
use PDOException;

class PushSubscriptionStore
{
    public const PREFERENCE_KEYS = ['downloads', 'errors', 'automation', 'integrity', 'daily_summary'];

    private const DEFAULT_PREFERENCES = [
        'downloads' => true,
        'errors' => true,
        'automation' => true,
        'integrity' => true,
        'daily_summary' => false,
    ];

    private string $dbPath;
    private ?PDO $pdo = null;
    private bool $initialized = false;

    public function __construct(
        private string $projectDir,
        private int $busyTimeoutMs = 15000
    ) {
        $dir = $projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'db';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
            @chmod($dir, 0700);
        }
        $this->dbPath = $dir . DIRECTORY_SEPARATOR . 'pwa.db';
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
            $this->pdo->exec('PRAGMA busy_timeout = ' . max(0, $this->busyTimeoutMs));
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
            $this->tightenPermissions();
        }

        return $this->pdo;
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->withRetry(function (PDO $pdo): void {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS subscriptions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    endpoint TEXT NOT NULL UNIQUE,
                    p256dh TEXT NOT NULL,
                    auth TEXT NOT NULL,
                    user_agent TEXT NULL,
                    created_at TEXT NOT NULL,
                    last_seen TEXT NOT NULL,
                    enabled INTEGER NOT NULL DEFAULT 1
                )'
            );
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pwa_subscriptions_enabled ON subscriptions (enabled)');
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS preferences (
                    key TEXT PRIMARY KEY,
                    value INTEGER NOT NULL,
                    updated_at TEXT NOT NULL
                )'
            );
        });

        $this->initialized = true;
    }

    public function upsertSubscription(string $endpoint, string $p256dh, string $auth, ?string $userAgent = null): void
    {
        $this->initialize();
        $now = date('c');
        $userAgent = $userAgent !== null ? substr($userAgent, 0, 255) : null;

        $this->withRetry(function (PDO $pdo) use ($endpoint, $p256dh, $auth, $userAgent, $now): void {
            $stmt = $pdo->prepare(
                'INSERT INTO subscriptions (endpoint, p256dh, auth, user_agent, created_at, last_seen, enabled)
                 VALUES (:endpoint, :p256dh, :auth, :user_agent, :created_at, :last_seen, 1)
                 ON CONFLICT(endpoint) DO UPDATE SET
                    p256dh = excluded.p256dh,
                    auth = excluded.auth,
                    user_agent = excluded.user_agent,
                    last_seen = excluded.last_seen,
                    enabled = 1'
            );
            $stmt->execute([
                ':endpoint' => $endpoint,
                ':p256dh' => $p256dh,
                ':auth' => $auth,
                ':user_agent' => $userAgent,
                ':created_at' => $now,
                ':last_seen' => $now,
            ]);
        });

        $this->tightenPermissions();
    }

    public function removeSubscription(string $endpoint): bool
    {
        $this->initialize();

        $removed = $this->withRetry(function (PDO $pdo) use ($endpoint): int {
            $stmt = $pdo->prepare('DELETE FROM subscriptions WHERE endpoint = :endpoint');
            $stmt->execute([':endpoint' => $endpoint]);

            return $stmt->rowCount();
        });

        $this->tightenPermissions();

        return $removed > 0;
    }

    /**
     * @param string[] $endpoints
     */
    public function removeExpired(array $endpoints): int
    {
        $this->initialize();
        $endpoints = array_values(array_unique(array_filter(
            array_map(static fn ($value): string => is_string($value) ? trim($value) : '', $endpoints),
            static fn (string $value): bool => $value !== ''
        )));
        if ($endpoints === []) {
            return 0;
        }

        $removed = $this->withRetry(function (PDO $pdo) use ($endpoints): int {
            $placeholders = implode(',', array_fill(0, count($endpoints), '?'));
            $stmt = $pdo->prepare('DELETE FROM subscriptions WHERE endpoint IN (' . $placeholders . ')');
            $stmt->execute($endpoints);

            return $stmt->rowCount();
        });

        $this->tightenPermissions();

        return $removed;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getSubscriptions(bool $enabledOnly = true): array
    {
        $this->initialize();
        $sql = 'SELECT id, endpoint, p256dh, auth, user_agent, created_at, last_seen, enabled FROM subscriptions';
        if ($enabledOnly) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY id ASC';

        $rows = $this->withRetry(fn (PDO $pdo): array => $pdo->query($sql)->fetchAll());

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['enabled'] = (int) $row['enabled'] === 1;

            return $row;
        }, $rows);
    }

    public function getSubscription(string $endpoint): ?array
    {
        $this->initialize();
        $stmt = $this->withRetry(function (PDO $pdo) use ($endpoint): \PDOStatement {
            $stmt = $pdo->prepare('SELECT id, endpoint, p256dh, auth, user_agent, created_at, last_seen, enabled FROM subscriptions WHERE endpoint = :endpoint');
            $stmt->execute([':endpoint' => $endpoint]);

            return $stmt;
        });
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['enabled'] = (int) $row['enabled'] === 1;

        return $row;
    }

    public function countSubscriptions(bool $enabledOnly = false): int
    {
        $this->initialize();
        $sql = 'SELECT COUNT(*) FROM subscriptions';
        if ($enabledOnly) {
            $sql .= ' WHERE enabled = 1';
        }

        return (int) $this->withRetry(fn (PDO $pdo): int => (int) $pdo->query($sql)->fetchColumn());
    }

    public function touchSubscription(string $endpoint): void
    {
        $this->initialize();

        $this->withRetry(function (PDO $pdo) use ($endpoint): void {
            $stmt = $pdo->prepare('UPDATE subscriptions SET last_seen = :last_seen, enabled = 1 WHERE endpoint = :endpoint');
            $stmt->execute([':last_seen' => date('c'), ':endpoint' => $endpoint]);
        });

        $this->tightenPermissions();
    }

    /**
     * @return array<string,bool>
     */
    public function getPreferences(): array
    {
        $this->initialize();
        $preferences = self::DEFAULT_PREFERENCES;

        $rows = $this->withRetry(fn (PDO $pdo): array => $pdo->query('SELECT key, value FROM preferences')->fetchAll());
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if (in_array($key, self::PREFERENCE_KEYS, true)) {
                $preferences[$key] = (int) ($row['value'] ?? 0) === 1;
            }
        }

        return $preferences;
    }

    /**
     * @param array<string,mixed> $preferences
     * @return array<string,bool>
     */
    public function savePreferences(array $preferences): array
    {
        $this->initialize();

        $this->withRetry(function (PDO $pdo) use ($preferences): void {
            $stmt = $pdo->prepare(
                'INSERT INTO preferences (key, value, updated_at)
                 VALUES (:key, :value, :updated_at)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
            );
            $now = date('c');
            foreach (self::PREFERENCE_KEYS as $key) {
                if (!array_key_exists($key, $preferences)) {
                    continue;
                }
                $stmt->execute([
                    ':key' => $key,
                    ':value' => $preferences[$key] ? 1 : 0,
                    ':updated_at' => $now,
                ]);
            }
        });

        $this->tightenPermissions();

        return $this->getPreferences();
    }

    public function isEventEnabled(string $event): bool
    {
        if (!in_array($event, self::PREFERENCE_KEYS, true)) {
            return false;
        }

        $preferences = $this->getPreferences();

        return $preferences[$event] === true;
    }

    private function tightenPermissions(): void
    {
        @chmod($this->dbPath, 0600);
        @chmod($this->dbPath . '-wal', 0600);
        @chmod($this->dbPath . '-shm', 0600);
    }

    /**
     * @template T
     * @param callable(PDO):T $operation
     * @return T
     */
    private function withRetry(callable $operation, int $attempts = 5): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $operation($this->getPdo());
            } catch (PDOException $e) {
                $attempt++;
                $message = strtolower($e->getMessage());
                $retryable = str_contains($message, 'database is locked')
                    || str_contains($message, 'database is busy')
                    || str_contains($message, 'database table is locked');
                if (!$retryable || $attempt >= $attempts) {
                    throw $e;
                }
                usleep((int) (10000 * (2 ** ($attempt - 1))));
            }
        }
    }
}
