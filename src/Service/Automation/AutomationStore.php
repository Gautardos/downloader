<?php

namespace App\Service\Automation;

use PDO;

class AutomationStore
{
    private string $projectDir;
    private string $dbPath;
    private ?PDO $pdo = null;
    private bool $initialized = false;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
        $this->dbPath = $projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'automation.db';

        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    public function getDbPath(): string
    {
        return $this->dbPath;
    }

    public function getStorageDir(): string
    {
        return $this->projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'storage';
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
            'CREATE TABLE IF NOT EXISTS scan_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                started_at TEXT NOT NULL,
                finished_at TEXT NULL,
                status TEXT NOT NULL DEFAULT \'running\',
                roots_json TEXT NULL,
                files_seen INTEGER NOT NULL DEFAULT 0,
                files_indexed INTEGER NOT NULL DEFAULT 0,
                files_updated INTEGER NOT NULL DEFAULT 0,
                files_missing INTEGER NOT NULL DEFAULT 0,
                duration REAL NULL,
                warnings_json TEXT NULL,
                error TEXT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS resolution_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                started_at TEXT NOT NULL,
                finished_at TEXT NULL,
                status TEXT NOT NULL DEFAULT \'running\',
                groups_seen INTEGER NOT NULL DEFAULT 0,
                groups_resolved INTEGER NOT NULL DEFAULT 0,
                entries_resolved INTEGER NOT NULL DEFAULT 0,
                groups_skipped INTEGER NOT NULL DEFAULT 0,
                lookups INTEGER NOT NULL DEFAULT 0,
                duration REAL NULL,
                error TEXT NULL,
                stats_json TEXT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS rename_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                started_at TEXT NOT NULL,
                finished_at TEXT NULL,
                status TEXT NOT NULL DEFAULT \'running\',
                scope TEXT NULL,
                entries_seen INTEGER NOT NULL DEFAULT 0,
                entries_renamed INTEGER NOT NULL DEFAULT 0,
                entries_skipped INTEGER NOT NULL DEFAULT 0,
                entries_failed INTEGER NOT NULL DEFAULT 0,
                duration REAL NULL,
                details_json TEXT NULL,
                error TEXT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS library_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                root_key TEXT NOT NULL,
                path TEXT NOT NULL UNIQUE,
                relative_path TEXT NOT NULL,
                folder_path TEXT NULL,
                filename TEXT NOT NULL,
                extension TEXT NOT NULL,
                media_type TEXT NOT NULL,
                title TEXT NOT NULL,
                title_normalized TEXT NOT NULL,
                year INTEGER NULL,
                season INTEGER NULL,
                episode INTEGER NULL,
                quality TEXT NULL,
                size INTEGER NOT NULL DEFAULT 0,
                mtime INTEGER NOT NULL DEFAULT 0,
                media_key TEXT NULL,
                tmdb_id INTEGER NULL,
                imdb_id TEXT NULL,
                confidence REAL NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT \'active\',
                parse_source TEXT NULL,
                parser_version INTEGER NOT NULL DEFAULT 0,
                scan_id INTEGER NULL,
                show_folder TEXT NULL,
                ignored_at TEXT NULL,
                resolved_at TEXT NULL,
                resolution_source TEXT NULL,
                first_seen_at TEXT NOT NULL,
                last_seen_at TEXT NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS metadata_cache (
                cache_key TEXT PRIMARY KEY,
                payload TEXT NOT NULL,
                created_at TEXT NOT NULL,
                expires_at TEXT NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS list_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_id TEXT NOT NULL,
                provider TEXT NOT NULL,
                mode TEXT NOT NULL DEFAULT \'dry_run\',
                started_at TEXT NOT NULL,
                finished_at TEXT NULL,
                status TEXT NOT NULL DEFAULT \'running\',
                lists_seen INTEGER NOT NULL DEFAULT 0,
                items_seen INTEGER NOT NULL DEFAULT 0,
                items_added INTEGER NOT NULL DEFAULT 0,
                items_removed INTEGER NOT NULL DEFAULT 0,
                present INTEGER NOT NULL DEFAULT 0,
                missing INTEGER NOT NULL DEFAULT 0,
                ambiguous INTEGER NOT NULL DEFAULT 0,
                ignored INTEGER NOT NULL DEFAULT 0,
                episodes_present INTEGER NOT NULL DEFAULT 0,
                episodes_missing INTEGER NOT NULL DEFAULT 0,
                lookups INTEGER NOT NULL DEFAULT 0,
                duration REAL NULL,
                error TEXT NULL,
                stats_json TEXT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS list_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_id TEXT NOT NULL,
                provider TEXT NOT NULL,
                list_ref TEXT NOT NULL,
                remote_key TEXT NOT NULL,
                remote_id TEXT NULL,
                media_type TEXT NOT NULL,
                tmdb_id INTEGER NULL,
                imdb_id TEXT NULL,
                tvdb_id INTEGER NULL,
                title TEXT NOT NULL,
                title_normalized TEXT NOT NULL,
                original_title TEXT NULL,
                year INTEGER NULL,
                season INTEGER NULL,
                episode INTEGER NULL,
                raw_json TEXT NULL,
                status TEXT NOT NULL DEFAULT \'active\',
                match_status TEXT NOT NULL DEFAULT \'pending\',
                match_reason TEXT NULL,
                matched_entry_id INTEGER NULL,
                matched_tmdb_id INTEGER NULL,
                matched_folder TEXT NULL,
                episodes_total INTEGER NOT NULL DEFAULT 0,
                episodes_present INTEGER NOT NULL DEFAULT 0,
                episodes_missing INTEGER NOT NULL DEFAULT 0,
                missing_json TEXT NULL,
                last_run_id INTEGER NULL,
                ignored_at TEXT NULL,
                first_seen_at TEXT NOT NULL,
                last_seen_at TEXT NOT NULL,
                last_matched_at TEXT NULL,
                UNIQUE(source_id, remote_key)
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS intents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_id TEXT NULL,
                source_list_id TEXT NULL,
                media_key TEXT NOT NULL,
                media_type TEXT NOT NULL DEFAULT \'movie\',
                tmdb_id INTEGER NULL,
                imdb_id TEXT NULL,
                title TEXT NOT NULL,
                year INTEGER NULL,
                season INTEGER NULL,
                episode INTEGER NULL,
                release_season INTEGER NULL,
                release_episode INTEGER NULL,
                numbering_source TEXT NULL,
                status TEXT NOT NULL DEFAULT \'pending\',
                attempts INTEGER NOT NULL DEFAULT 0,
                next_attempt_at TEXT NULL,
                download_id TEXT NULL,
                info_hash TEXT NULL,
                file_idx INTEGER NULL,
                selected_source TEXT NULL,
                score REAL NULL,
                release_explanation TEXT NULL,
                error_message TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS intent_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                started_at TEXT NOT NULL,
                finished_at TEXT NULL,
                status TEXT NOT NULL DEFAULT \'running\',
                intents_seen INTEGER NOT NULL DEFAULT 0,
                intents_queued INTEGER NOT NULL DEFAULT 0,
                intents_skipped INTEGER NOT NULL DEFAULT 0,
                intents_no_source INTEGER NOT NULL DEFAULT 0,
                intents_error INTEGER NOT NULL DEFAULT 0,
                queue_depth INTEGER NOT NULL DEFAULT 0,
                duration REAL NULL,
                error TEXT NULL,
                stats_json TEXT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS series_numbering (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tmdb_id INTEGER NULL,
                imdb_id TEXT NULL,
                title TEXT NOT NULL,
                source TEXT NOT NULL DEFAULT \'auto\',
                source_ref TEXT NULL,
                segments_json TEXT NULL,
                mapping_json TEXT NULL,
                confidence REAL NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT \'ok\',
                reason TEXT NULL,
                tmdb_snapshot_json TEXT NULL,
                target_mode TEXT NOT NULL DEFAULT \'local\',
                jellyfin_mode TEXT NOT NULL DEFAULT \'local\',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(tmdb_id)
            )'
        );

        $this->ensureColumn('scan_runs', 'warnings_json', 'TEXT NULL');
        $this->ensureColumn('library_entries', 'folder_path', 'TEXT NULL');
        $this->ensureColumn('library_entries', 'parser_version', 'INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumn('library_entries', 'scan_id', 'INTEGER NULL');
        $this->ensureColumn('library_entries', 'show_folder', 'TEXT NULL');
        $this->ensureColumn('library_entries', 'ignored_at', 'TEXT NULL');
        $this->ensureColumn('library_entries', 'resolved_at', 'TEXT NULL');
        $this->ensureColumn('library_entries', 'resolution_source', 'TEXT NULL');
        $this->ensureColumn('intents', 'season', 'INTEGER NULL');
        $this->ensureColumn('intents', 'episode', 'INTEGER NULL');
        $this->ensureColumn('intents', 'release_season', 'INTEGER NULL');
        $this->ensureColumn('intents', 'release_episode', 'INTEGER NULL');
        $this->ensureColumn('intents', 'numbering_source', 'TEXT NULL');

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_library_media_key ON library_entries (media_key)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_library_title ON library_entries (title_normalized, year)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_library_status ON library_entries (status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_library_root ON library_entries (root_key)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_library_folder ON library_entries (root_key, folder_path)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_library_scan ON library_entries (scan_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_library_ignored ON library_entries (ignored_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_library_last_seen ON library_entries (last_seen_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scan_status ON scan_runs (status, started_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_metadata_cache_expires ON metadata_cache (expires_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_resolution_status ON resolution_runs (status, started_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rename_status ON rename_runs (status, started_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_list_runs_source ON list_runs (source_id, started_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_list_runs_status ON list_runs (status, started_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_list_items_source ON list_items (source_id, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_list_items_match ON list_items (match_status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_list_items_tmdb ON list_items (tmdb_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_intents_media_key ON intents (media_key, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_intents_status ON intents (status, next_attempt_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_intents_type_status ON intents (media_type, status, next_attempt_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_intents_tmdb_episode ON intents (tmdb_id, season, episode)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_intents_updated ON intents (updated_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_intent_runs_status ON intent_runs (status, started_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_series_numbering_imdb ON series_numbering (imdb_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_series_numbering_status ON series_numbering (status)');

        // Recovery from the previous pending-based scan implementation.
        $pdo->exec('UPDATE library_entries SET status = \'active\' WHERE status = \'pending\'');
        $pdo->exec('UPDATE library_entries SET folder_path = CASE WHEN length(relative_path) > length(filename) + 1 THEN substr(relative_path, 1, length(relative_path) - length(filename) - 1) ELSE \'\' END WHERE folder_path IS NULL OR folder_path = \'\'');
        $pdo->exec('PRAGMA user_version = 6');

        $this->tightenDatabasePermissions();
        $this->initialized = true;
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $columns = $this->getPdo()->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        foreach ($columns as $existing) {
            if (($existing['name'] ?? '') === $column) {
                return;
            }
        }

        $this->getPdo()->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
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
                ':updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
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

    public function startScan(array $roots): int
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'INSERT INTO scan_runs (started_at, status, roots_json) VALUES (:started_at, :status, :roots_json)'
        );
        return $this->withRetry(function () use ($stmt, $roots) {
            $stmt->execute([
                ':started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ':status' => 'running',
                ':roots_json' => json_encode($roots, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            return (int) $this->getPdo()->lastInsertId();
        });
    }

    public function updateScan(int $runId, array $stats): void
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'UPDATE scan_runs
             SET files_seen = :files_seen,
                 files_indexed = :files_indexed,
                 files_updated = :files_updated,
                 files_missing = :files_missing
             WHERE id = :id'
        );
        $this->withRetry(function () use ($stmt, $runId, $stats) {
            $stmt->execute([
                ':id' => $runId,
                ':files_seen' => (int) ($stats['files_seen'] ?? 0),
                ':files_indexed' => (int) ($stats['files_indexed'] ?? 0),
                ':files_updated' => (int) ($stats['files_updated'] ?? 0),
                ':files_missing' => (int) ($stats['files_missing'] ?? 0),
            ]);
        });
    }

    public function finishScan(int $runId, array $stats, string $status = 'success', ?string $error = null): void
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'UPDATE scan_runs
             SET finished_at = :finished_at,
                 status = :status,
                 files_seen = :files_seen,
                 files_indexed = :files_indexed,
                 files_updated = :files_updated,
                 files_missing = :files_missing,
                 duration = :duration,
                 warnings_json = :warnings_json,
                 error = :error
             WHERE id = :id'
        );
        $warnings = is_array($stats['warnings'] ?? null) ? $stats['warnings'] : [];
        $this->withRetry(function () use ($stmt, $runId, $stats, $status, $error, $warnings) {
            $stmt->execute([
                ':id' => $runId,
                ':finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ':status' => $status,
                ':files_seen' => (int) ($stats['files_seen'] ?? 0),
                ':files_indexed' => (int) ($stats['files_indexed'] ?? 0),
                ':files_updated' => (int) ($stats['files_updated'] ?? 0),
                ':files_missing' => (int) ($stats['files_missing'] ?? 0),
                ':duration' => isset($stats['duration']) ? (float) $stats['duration'] : null,
                ':warnings_json' => $warnings ? json_encode(array_values($warnings), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                ':error' => $error,
            ]);
        });
    }

    public function getScan(int $runId): ?array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare('SELECT * FROM scan_runs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $runId]);

        return $this->hydrateScanRow($stmt->fetch() ?: null);
    }

    public function getLastScan(): ?array
    {
        $this->initialize();
        $row = $this->getPdo()->query('SELECT * FROM scan_runs ORDER BY id DESC LIMIT 1')->fetch();

        return $this->hydrateScanRow($row ?: null);
    }

    public function getRunningScan(): ?array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'SELECT * FROM scan_runs
             WHERE status = :status AND started_at >= :threshold
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            ':status' => 'running',
            ':threshold' => (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'),
        ]);

        return $this->hydrateScanRow($stmt->fetch() ?: null);
    }

    public function markStaleScans(): int
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'UPDATE scan_runs
             SET status = :error_status,
                 finished_at = :finished_at,
                 error = COALESCE(error, :message)
             WHERE status = :running_status AND started_at < :threshold'
        );
        $stmt->execute([
            ':error_status' => 'error',
            ':running_status' => 'running',
            ':finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':message' => 'Scan interrupted or stale.',
            ':threshold' => (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'),
        ]);

        return $stmt->rowCount();
    }

    private function hydrateScanRow(?array $row): ?array
    {
        if (!$row) {
            return null;
        }

        $row['warnings'] = [];
        if (!empty($row['warnings_json'])) {
            $decoded = json_decode((string) $row['warnings_json'], true);
            if (is_array($decoded)) {
                $row['warnings'] = $decoded;
            }
        }

        return $row;
    }

    public function getRootActiveCount(string $rootKey): int
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'SELECT COUNT(*) FROM library_entries WHERE root_key = :root_key AND status = \'active\''
        );
        $stmt->execute([':root_key' => $rootKey]);

        return (int) $stmt->fetchColumn();
    }

    public function getActiveLibraryEntriesForRoots(?array $rootKeys = null): array
    {
        $this->initialize();
        $sql = "SELECT id, root_key, path, title, season, episode, show_folder
                FROM library_entries
                WHERE status = 'active'";
        $params = [];
        if ($rootKeys !== null && $rootKeys !== []) {
            $placeholders = [];
            foreach (array_values($rootKeys) as $index => $rootKey) {
                $key = ':root_' . $index;
                $placeholders[] = $key;
                $params[$key] = (string) $rootKey;
            }
            $sql .= ' AND root_key IN (' . implode(', ', $placeholders) . ')';
        }
        $sql .= ' ORDER BY root_key, title, season, episode, id';
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function markLibraryEntriesMissing(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0));
        if ($ids === []) {
            return 0;
        }
        $this->initialize();
        $placeholders = [];
        $params = [':missing' => 'missing'];
        foreach ($ids as $index => $id) {
            $key = ':id_' . $index;
            $placeholders[] = $key;
            $params[$key] = $id;
        }
        $stmt = $this->getPdo()->prepare(
            "UPDATE library_entries SET status = :missing WHERE id IN (" . implode(', ', $placeholders) . ") AND status = 'active'"
        );

        return $this->withRetry(function () use ($stmt, $params) {
            $stmt->execute($params);

            return $stmt->rowCount();
        });
    }

    public function finishRootScan(string $rootKey, int $scanId): int
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'UPDATE library_entries
             SET status = :missing
             WHERE root_key = :root_key
               AND status = \'active\'
               AND (scan_id IS NULL OR scan_id != :scan_id)'
        );
        $stmt->execute([
            ':missing' => 'missing',
            ':root_key' => $rootKey,
            ':scan_id' => $scanId,
        ]);

        return $stmt->rowCount();
    }

    public function upsertLibraryEntry(array $entry): string
    {
        return $this->withRetry(fn () => $this->doUpsertLibraryEntry($entry));
    }

    private function doUpsertLibraryEntry(array $entry): string
    {
        $this->initialize();
        $pdo = $this->getPdo();

        $stmt = $pdo->prepare(
            'SELECT id, size, mtime, status, parser_version, tmdb_id, imdb_id, year, media_key, resolved_at, resolution_source
             FROM library_entries WHERE path = :path LIMIT 1'
        );
        $stmt->execute([':path' => $entry['path']]);
        $existing = $stmt->fetch();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $parserVersion = (int) ($entry['parser_version'] ?? 0);
        $scanId = isset($entry['scan_id']) ? (int) $entry['scan_id'] : null;

        if (!$existing) {
            $insert = $pdo->prepare(
                'INSERT INTO library_entries (
                    root_key, path, relative_path, folder_path, filename, extension, media_type,
                    title, title_normalized, year, season, episode, quality,
                    size, mtime, media_key, tmdb_id, imdb_id, confidence,
                    status, parse_source, parser_version, scan_id, show_folder,
                    resolved_at, resolution_source, first_seen_at, last_seen_at
                ) VALUES (
                    :root_key, :path, :relative_path, :folder_path, :filename, :extension, :media_type,
                    :title, :title_normalized, :year, :season, :episode, :quality,
                    :size, :mtime, :media_key, :tmdb_id, :imdb_id, :confidence,
                    :status, :parse_source, :parser_version, :scan_id, :show_folder,
                    :resolved_at, :resolution_source, :first_seen_at, :last_seen_at
                )'
            );
            $insert->execute($this->entryParams($entry, $now, $now, 'active'));

            return 'inserted';
        }

        $contentSame = (int) $existing['size'] === (int) ($entry['size'] ?? 0)
            && (int) $existing['mtime'] === (int) ($entry['mtime'] ?? 0)
            && (int) $existing['parser_version'] === $parserVersion;
        $wasActive = (string) $existing['status'] === 'active';

        if ($contentSame) {
            $update = $pdo->prepare(
                'UPDATE library_entries
                 SET status = :status, scan_id = :scan_id, last_seen_at = :last_seen_at
                 WHERE path = :path'
            );
            $update->execute([
                ':status' => 'active',
                ':scan_id' => $scanId,
                ':last_seen_at' => $now,
                ':path' => $entry['path'],
            ]);

            return $wasActive ? 'unchanged' : 'updated';
        }

        $tmdbId = isset($entry['tmdb_id']) ? (int) $entry['tmdb_id'] : null;
        $imdbId = isset($entry['imdb_id']) ? (string) $entry['imdb_id'] : null;
        $mediaKey = isset($entry['media_key']) ? (string) $entry['media_key'] : null;
        $resolvedAt = isset($entry['resolved_at']) ? (string) $entry['resolved_at'] : null;
        $resolutionSource = isset($entry['resolution_source']) ? (string) $entry['resolution_source'] : null;

        if ($tmdbId === null && $imdbId === null && (!empty($existing['tmdb_id']) || !empty($existing['imdb_id']))) {
            $tmdbId = !empty($existing['tmdb_id']) ? (int) $existing['tmdb_id'] : null;
            $imdbId = !empty($existing['imdb_id']) ? (string) $existing['imdb_id'] : null;
            $mediaKey = $existing['media_key'] ?: $mediaKey;
            $resolvedAt = $existing['resolved_at'] ?: $resolvedAt;
            $resolutionSource = $existing['resolution_source'] ?: $resolutionSource;
        }
        if (empty($entry['year']) && !empty($existing['year'])) {
            $entry['year'] = (int) $existing['year'];
        }

        $entry['tmdb_id'] = $tmdbId;
        $entry['imdb_id'] = $imdbId;
        $entry['media_key'] = $mediaKey;
        $entry['resolved_at'] = $resolvedAt;
        $entry['resolution_source'] = $resolutionSource;

        $update = $pdo->prepare(
            'UPDATE library_entries SET
                root_key = :root_key,
                relative_path = :relative_path,
                folder_path = :folder_path,
                filename = :filename,
                extension = :extension,
                media_type = :media_type,
                title = :title,
                title_normalized = :title_normalized,
                year = :year,
                season = :season,
                episode = :episode,
                quality = :quality,
                size = :size,
                mtime = :mtime,
                media_key = :media_key,
                tmdb_id = :tmdb_id,
                imdb_id = :imdb_id,
                confidence = :confidence,
                status = :status,
                parse_source = :parse_source,
                parser_version = :parser_version,
                scan_id = :scan_id,
                show_folder = :show_folder,
                resolved_at = :resolved_at,
                resolution_source = :resolution_source,
                last_seen_at = :last_seen_at
             WHERE path = :path'
        );
        $params = $this->entryParams($entry, $now, $now, 'active');
        unset($params[':first_seen_at']);
        $update->execute($params);

        return 'updated';
    }

    private function folderPathForEntry(array $entry): string
    {
        if (array_key_exists('folder_path', $entry)) {
            return (string) ($entry['folder_path'] ?? '');
        }

        $folder = dirname((string) ($entry['relative_path'] ?? ''));

        return $folder === '.' ? '' : $folder;
    }

    private function entryParams(array $entry, string $firstSeenAt, string $lastSeenAt, string $status): array
    {
        return [
            ':root_key' => (string) ($entry['root_key'] ?? ''),
            ':path' => (string) ($entry['path'] ?? ''),
            ':relative_path' => (string) ($entry['relative_path'] ?? ''),
            ':folder_path' => $this->folderPathForEntry($entry),
            ':filename' => (string) ($entry['filename'] ?? ''),
            ':extension' => (string) ($entry['extension'] ?? ''),
            ':media_type' => (string) ($entry['media_type'] ?? 'unknown'),
            ':title' => (string) ($entry['title'] ?? ''),
            ':title_normalized' => (string) ($entry['title_normalized'] ?? ''),
            ':year' => isset($entry['year']) ? (int) $entry['year'] : null,
            ':season' => isset($entry['season']) ? (int) $entry['season'] : null,
            ':episode' => isset($entry['episode']) ? (int) $entry['episode'] : null,
            ':quality' => isset($entry['quality']) ? (string) $entry['quality'] : null,
            ':size' => (int) ($entry['size'] ?? 0),
            ':mtime' => (int) ($entry['mtime'] ?? 0),
            ':media_key' => isset($entry['media_key']) ? (string) $entry['media_key'] : null,
            ':tmdb_id' => isset($entry['tmdb_id']) ? (int) $entry['tmdb_id'] : null,
            ':imdb_id' => isset($entry['imdb_id']) ? (string) $entry['imdb_id'] : null,
            ':confidence' => (float) ($entry['confidence'] ?? 0),
            ':status' => $status,
            ':parse_source' => isset($entry['parse_source']) ? (string) $entry['parse_source'] : null,
            ':parser_version' => (int) ($entry['parser_version'] ?? 0),
            ':scan_id' => isset($entry['scan_id']) ? (int) $entry['scan_id'] : null,
            ':show_folder' => isset($entry['show_folder']) ? (string) $entry['show_folder'] : null,
            ':resolved_at' => isset($entry['resolved_at']) ? (string) $entry['resolved_at'] : null,
            ':resolution_source' => isset($entry['resolution_source']) ? (string) $entry['resolution_source'] : null,
            ':first_seen_at' => $firstSeenAt,
            ':last_seen_at' => $lastSeenAt,
        ];
    }

    public function reconcileSeriesAmbiguity(): int
    {
        $this->initialize();
        $rows = $this->getPdo()->query(
            "SELECT id, title_normalized, media_type, show_folder, confidence
             FROM library_entries
             WHERE status = 'active'
               AND ignored_at IS NULL
               AND tmdb_id IS NULL
               AND imdb_id IS NULL
               AND media_type IN ('series', 'anime')
               AND year IS NULL
               AND episode IS NOT NULL
               AND confidence >= 0.75
               AND parse_source = 'filename'
               AND show_folder IS NOT NULL
               AND show_folder != ''"
        )->fetchAll();

        $groups = [];
        foreach ($rows as $row) {
            $key = $row['media_type'] . '|' . $row['title_normalized'];
            $groups[$key]['folders'][(string) $row['show_folder']] = true;
            $groups[$key]['entries'][] = $row;
        }

        $updated = 0;
        $update = $this->getPdo()->prepare('UPDATE library_entries SET confidence = :confidence WHERE id = :id');
        foreach ($groups as $group) {
            $target = count($group['folders']) === 1 ? 0.84 : 0.78;
            foreach ($group['entries'] as $entry) {
                if ((float) $entry['confidence'] === $target) {
                    continue;
                }
                $update->execute([':confidence' => $target, ':id' => (int) $entry['id']]);
                $updated++;
            }
        }

        return $updated;
    }


    public function getMetadataCache(string $key): ?array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'SELECT payload FROM metadata_cache WHERE cache_key = :cache_key AND expires_at > :now LIMIT 1'
        );
        $stmt->execute([
            ':cache_key' => $key,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $decoded = json_decode((string) $row['payload'], true);
        return is_array($decoded) ? $decoded : null;
    }

    public function setMetadataCache(string $key, array $payload, int $ttlSeconds): void
    {
        $this->initialize();
        $now = new \DateTimeImmutable();
        $stmt = $this->getPdo()->prepare(
            'INSERT INTO metadata_cache (cache_key, payload, created_at, expires_at)
             VALUES (:cache_key, :payload, :created_at, :expires_at)
             ON CONFLICT(cache_key) DO UPDATE SET
                payload = excluded.payload,
                created_at = excluded.created_at,
                expires_at = excluded.expires_at'
        );
        $this->withRetry(function () use ($stmt, $key, $payload, $now, $ttlSeconds) {
            $stmt->execute([
                ':cache_key' => $key,
                ':payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':created_at' => $now->format('Y-m-d H:i:s'),
                ':expires_at' => $now->modify('+' . max(60, $ttlSeconds) . ' seconds')->format('Y-m-d H:i:s'),
            ]);
        });
    }

    public function purgeExpiredMetadataCache(): int
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare('DELETE FROM metadata_cache WHERE expires_at <= :now');
        $stmt->execute([':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);

        return $stmt->rowCount();
    }

    public function getUnresolvedGroups(int $limit = 50): array
    {
        $this->initialize();
        $groups = [];
        $limit = max(1, $limit);

        $movieStmt = $this->getPdo()->prepare(
            "SELECT 'movie' AS media_type, title_normalized, MAX(title) AS title, year, COUNT(*) AS entries,
                    MAX(last_seen_at) AS last_seen
             FROM library_entries
             WHERE status = 'active'
               AND ignored_at IS NULL
               AND media_type = 'movie'
               AND tmdb_id IS NULL
               AND imdb_id IS NULL
               AND title_normalized != ''
             GROUP BY title_normalized, year
             ORDER BY MAX(last_seen_at) DESC
             LIMIT :limit"
        );
        $movieStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $movieStmt->execute();
        foreach ($movieStmt->fetchAll() as $row) {
            $row['folders'] = 0;
            $groups[] = $row;
        }

        $seriesStmt = $this->getPdo()->prepare(
            "SELECT media_type, title_normalized, MAX(title) AS title, year, COUNT(*) AS entries,
                    COUNT(DISTINCT show_folder) AS folders,
                    MAX(last_seen_at) AS last_seen
             FROM library_entries
             WHERE status = 'active'
               AND ignored_at IS NULL
               AND media_type IN ('series', 'anime')
               AND tmdb_id IS NULL
               AND imdb_id IS NULL
               AND title_normalized != ''
             GROUP BY media_type, title_normalized, year
             ORDER BY MAX(last_seen_at) DESC
             LIMIT :limit"
        );
        $seriesStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $seriesStmt->execute();
        foreach ($seriesStmt->fetchAll() as $row) {
            $groups[] = $row;
        }

        usort($groups, static fn ($a, $b) => strcmp((string) $b['last_seen'], (string) $a['last_seen']));

        return array_slice($groups, 0, $limit);
    }

    public function getEntriesForResolution(string $mediaType, string $titleNormalized, ?int $year, ?string $showFolder = null, bool $includeMissing = false): array
    {
        $this->initialize();
        $statusCondition = $includeMissing ? "status IN ('active', 'missing')" : "status = 'active'";
        $sql = 'SELECT id, media_type, season, episode, year, show_folder
                FROM library_entries
                WHERE ' . $statusCondition . '
                  AND media_type = :media_type
                  AND title_normalized = :title_normalized';
        $params = [
            ':media_type' => $mediaType,
            ':title_normalized' => $titleNormalized,
        ];
        if ($year === null) {
            $sql .= ' AND year IS NULL';
        } else {
            $sql .= ' AND year = :year';
            $params[':year'] = $year;
        }
        if ($showFolder !== null && $showFolder !== '') {
            $sql .= ' AND show_folder = :show_folder';
            $params[':show_folder'] = $showFolder;
        }

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function getEntriesByShowFolder(string $mediaType, string $showFolder): array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            "SELECT id, media_type, season, episode, year
             FROM library_entries
             WHERE status IN ('active', 'missing')
               AND media_type = :media_type
               AND show_folder = :show_folder"
        );
        $stmt->execute([
            ':media_type' => $mediaType,
            ':show_folder' => $showFolder,
        ]);

        return $stmt->fetchAll();
    }

    public function unignoreByShowFolder(string $mediaType, string $showFolder): void
    {
        $this->withRetry(function () use ($mediaType, $showFolder) {
            $stmt = $this->getPdo()->prepare(
                'UPDATE library_entries
                 SET ignored_at = NULL
                 WHERE media_type = :media_type AND show_folder = :show_folder'
            );
            $stmt->execute([
                ':media_type' => $mediaType,
                ':show_folder' => $showFolder,
            ]);
        });
    }

    public function updateEntryResolution(int $id, int $tmdbId, ?string $imdbId, ?int $year, string $mediaKey, string $source): void
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'UPDATE library_entries
             SET tmdb_id = :tmdb_id,
                 imdb_id = :imdb_id,
                 year = :year,
                 media_key = :media_key,
                 confidence = :confidence,
                 resolved_at = :resolved_at,
                 resolution_source = :resolution_source
             WHERE id = :id'
        );
        $this->withRetry(function () use ($stmt, $id, $tmdbId, $imdbId, $year, $mediaKey, $source) {
            $stmt->execute([
                ':id' => $id,
                ':tmdb_id' => $tmdbId,
                ':imdb_id' => $imdbId,
                ':year' => $year,
                ':media_key' => $mediaKey,
                ':confidence' => 0.99,
                ':resolved_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ':resolution_source' => $source,
            ]);
        });
    }


    public function getAttentionGroups(int $limit = 50): array
    {
        return $this->getGroupedAttention(false, $limit);
    }

    public function getIgnoredGroups(int $limit = 50): array
    {
        return $this->getGroupedAttention(true, $limit);
    }

    private function getGroupedAttention(bool $ignored, int $limit): array
    {
        $this->initialize();
        $limit = max(1, $limit);
        $ignoredClause = $ignored ? 'ignored_at IS NOT NULL' : 'ignored_at IS NULL';
        $seriesAmbiguity = $ignored ? '1=1' : "(confidence < 0.8 OR episode IS NULL)";
        $movieAmbiguity = $ignored ? '1=1' : 'confidence < 0.8';
        $groups = [];

        $seriesStmt = $this->getPdo()->prepare(
            "SELECT media_type, title_normalized, MAX(title) AS title, year, show_folder,
                    COUNT(*) AS entries,
                    MIN(confidence) AS min_confidence,
                    MAX(confidence) AS max_confidence,
                    MIN(relative_path) AS sample_path,
                    SUM(CASE WHEN episode IS NULL THEN 1 ELSE 0 END) AS missing_episode,
                    SUM(CASE WHEN parse_source = 'multi_episode' THEN 1 ELSE 0 END) AS multi_episodes
             FROM library_entries
             WHERE status = 'active'
               AND {$ignoredClause}
               AND tmdb_id IS NULL
               AND imdb_id IS NULL
               AND media_type IN ('series', 'anime')
               AND {$seriesAmbiguity}
             GROUP BY media_type, title_normalized, year, show_folder
             ORDER BY MIN(confidence) ASC, COUNT(*) DESC
             LIMIT :limit"
        );
        $seriesStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $seriesStmt->execute();
        $seriesRows = $seriesStmt->fetchAll();

        $movieStmt = $this->getPdo()->prepare(
            "SELECT 'movie' AS media_type, title_normalized, MAX(title) AS title, year, NULL AS show_folder,
                    COUNT(*) AS entries,
                    MIN(confidence) AS min_confidence,
                    MAX(confidence) AS max_confidence,
                    MIN(relative_path) AS sample_path,
                    0 AS missing_episode,
                    0 AS multi_episodes
             FROM library_entries
             WHERE status = 'active'
               AND {$ignoredClause}
               AND tmdb_id IS NULL
               AND imdb_id IS NULL
               AND media_type = 'movie'
               AND {$movieAmbiguity}
             GROUP BY title_normalized, year
             ORDER BY MIN(confidence) ASC, COUNT(*) DESC
             LIMIT :limit"
        );
        $movieStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $movieStmt->execute();
        $movieRows = $movieStmt->fetchAll();

        foreach (array_merge($seriesRows, $movieRows) as $row) {
            $mediaType = (string) $row['media_type'];
            $titleNormalized = (string) $row['title_normalized'];
            $year = $row['year'] !== null ? (int) $row['year'] : null;
            $showFolder = $row['show_folder'] !== null && $row['show_folder'] !== '' ? (string) $row['show_folder'] : null;
            $missingEpisode = (int) $row['missing_episode'];
            $multiEpisodes = (int) $row['multi_episodes'];

            if ($missingEpisode > 0) {
                $reason = 'Missing episode number';
            } elseif ($multiEpisodes > 0) {
                $reason = 'Multi-episode file';
            } elseif ($mediaType === 'movie') {
                $reason = 'Missing year or low confidence';
            } else {
                $reason = 'Possible title collision / low confidence';
            }

            $groups[] = [
                'group_key' => $mediaType . '|' . $titleNormalized . '|' . ($year ?? 'unknown') . '|' . ($showFolder ?? ''),
                'media_type' => $mediaType,
                'title' => (string) $row['title'],
                'title_normalized' => $titleNormalized,
                'year' => $year,
                'show_folder' => $showFolder,
                'entries' => (int) $row['entries'],
                'min_confidence' => (float) $row['min_confidence'],
                'max_confidence' => (float) $row['max_confidence'],
                'sample_path' => (string) $row['sample_path'],
                'reason' => $reason,
            ];
        }

        usort($groups, static function ($a, $b) {
            return ($a['min_confidence'] <=> $b['min_confidence'])
                ?: ($b['entries'] <=> $a['entries']);
        });

        return array_slice($groups, 0, $limit);
    }

    public function ignoreGroup(array $group, bool $ignored): int
    {
        $this->initialize();
        $mediaType = (string) ($group['media_type'] ?? '');
        $titleNormalized = (string) ($group['title_normalized'] ?? '');
        if (!in_array($mediaType, ['movie', 'series', 'anime'], true) || $titleNormalized === '') {
            return 0;
        }

        $sql = 'UPDATE library_entries SET ignored_at = :ignored_at
                WHERE status = \'active\'
                  AND media_type = :media_type
                  AND title_normalized = :title_normalized';
        $params = [
            ':ignored_at' => $ignored ? (new \DateTimeImmutable())->format('Y-m-d H:i:s') : null,
            ':media_type' => $mediaType,
            ':title_normalized' => $titleNormalized,
        ];

        $year = isset($group['year']) && $group['year'] !== null ? (int) $group['year'] : null;
        if ($year === null) {
            $sql .= ' AND year IS NULL';
        } else {
            $sql .= ' AND year = :year';
            $params[':year'] = $year;
        }

        $showFolder = isset($group['show_folder']) && $group['show_folder'] !== '' ? (string) $group['show_folder'] : null;
        if ($showFolder !== null && in_array($mediaType, ['series', 'anime'], true)) {
            $sql .= ' AND show_folder = :show_folder';
            $params[':show_folder'] = $showFolder;
        }

        $stmt = $this->getPdo()->prepare($sql);

        return $this->withRetry(function () use ($stmt, $params) {
            $stmt->execute($params);
            return $stmt->rowCount();
        });
    }


    public function startResolutionRun(array $stats = []): int
    {
        return $this->withRetry(function () use ($stats) {
            $stmt = $this->getPdo()->prepare(
                'INSERT INTO resolution_runs (started_at, status, stats_json)
                 VALUES (:started_at, :status, :stats_json)'
            );
            $stmt->execute([
                ':started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ':status' => 'running',
                ':stats_json' => json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            return (int) $this->getPdo()->lastInsertId();
        });
    }

    public function finishResolutionRun(int $runId, array $stats, string $status, ?string $error = null): void
    {
        $this->withRetry(function () use ($runId, $stats, $status, $error) {
            $stmt = $this->getPdo()->prepare(
                'UPDATE resolution_runs
                 SET finished_at = :finished_at,
                     status = :status,
                     groups_seen = :groups_seen,
                     groups_resolved = :groups_resolved,
                     entries_resolved = :entries_resolved,
                     groups_skipped = :groups_skipped,
                     lookups = :lookups,
                     duration = :duration,
                     error = :error,
                     stats_json = :stats_json
                 WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $runId,
                ':finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ':status' => $status,
                ':groups_seen' => (int) ($stats['groups_seen'] ?? 0),
                ':groups_resolved' => (int) ($stats['groups_resolved'] ?? 0),
                ':entries_resolved' => (int) ($stats['entries_resolved'] ?? 0),
                ':groups_skipped' => (int) ($stats['groups_skipped'] ?? 0),
                ':lookups' => (int) ($stats['lookups'] ?? 0),
                ':duration' => isset($stats['duration']) ? (float) $stats['duration'] : null,
                ':error' => $error,
                ':stats_json' => json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    public function markStaleResolutionRuns(): int
    {
        return $this->withRetry(function () {
            $stmt = $this->getPdo()->prepare(
                'UPDATE resolution_runs
                 SET status = :error_status,
                     finished_at = :finished_at,
                     error = COALESCE(error, :message)
                 WHERE status = :running_status AND started_at < :threshold'
            );
            $stmt->execute([
                ':error_status' => 'error',
                ':running_status' => 'running',
                ':finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ':message' => 'Resolution interrupted or stale.',
                ':threshold' => (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'),
            ]);

            return $stmt->rowCount();
        });
    }

    public function getRecentScans(int $limit = 10): array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare('SELECT * FROM scan_runs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return array_values(array_map(fn ($row) => $this->hydrateScanRow($row), $rows));
    }

    public function getRecentResolutions(int $limit = 10): array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare('SELECT * FROM resolution_runs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['stats'] = [];
            if (!empty($row['stats_json'])) {
                $decoded = json_decode((string) $row['stats_json'], true);
                if (is_array($decoded)) {
                    $row['stats'] = $decoded;
                }
            }
        }
        unset($row);

        return $rows;
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


    public function deleteMissingEntry(int $id): bool
    {
        $this->initialize();

        return $this->withRetry(function () use ($id) {
            $stmt = $this->getPdo()->prepare("DELETE FROM library_entries WHERE id = :id AND status = 'missing'");
            $stmt->execute([':id' => $id]);

            return $stmt->rowCount() > 0;
        });
    }

    public function purgeMissingEntries(): int
    {
        $this->initialize();

        return $this->withRetry(function () {
            return $this->getPdo()->exec("DELETE FROM library_entries WHERE status = 'missing'");
        });
    }

    public function getLibraryEntriesForFolderTree(array $filters = []): array
    {
        $this->initialize();
        [$where, $params] = $this->folderTreeWhere($filters);
        $stmt = $this->getPdo()->prepare(
            'SELECT * FROM library_entries ' . $where . '
             ORDER BY root_key, folder_path, relative_path'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function folderTreeWhere(array $filters): array
    {
        $status = (string) ($filters['status'] ?? 'all');
        $type = (string) ($filters['type'] ?? 'all');
        $query = trim((string) ($filters['q'] ?? ''));
        $conditions = [];
        $params = [];

        switch ($status) {
            case 'matched':
                $conditions[] = "status IN ('active', 'missing') AND ignored_at IS NULL AND (tmdb_id IS NOT NULL OR imdb_id IS NOT NULL)";
                break;
            case 'unmatched':
                $conditions[] = "status IN ('active', 'missing') AND ignored_at IS NULL AND tmdb_id IS NULL AND imdb_id IS NULL";
                break;
            case 'ignored':
                $conditions[] = "status IN ('active', 'missing') AND ignored_at IS NOT NULL";
                break;
            case 'missing':
                $conditions[] = "status = 'missing'";
                break;
            default:
                $conditions[] = "status IN ('active', 'missing')";
                break;
        }

        if (in_array($type, ['movie', 'series', 'anime'], true)) {
            $conditions[] = 'media_type = :media_type';
            $params[':media_type'] = $type;
        }
        if ($query !== '') {
            $conditions[] = '(title LIKE :query OR relative_path LIKE :query OR media_key LIKE :query OR filename LIKE :query)';
            $params[':query'] = '%' . $query . '%';
        }

        return ['WHERE ' . implode(' AND ', $conditions), $params];
    }

    public function getLibraryEntriesForElement(string $mediaType, string $titleNormalized, ?int $year, ?int $tmdbId, ?string $showFolder): array
    {
        $this->initialize();
        if (in_array($mediaType, ['series', 'anime'], true) && $showFolder !== null && $showFolder !== '') {
            return $this->getLibraryEntriesByShowFolder($mediaType, $showFolder);
        }

        $sql = "SELECT * FROM library_entries
                WHERE status IN ('active', 'missing')
                  AND media_type = :media_type";
        $params = [':media_type' => $mediaType];

        if ($tmdbId !== null && $tmdbId > 0) {
            $sql .= ' AND tmdb_id = :tmdb_id';
            $params[':tmdb_id'] = $tmdbId;
        } else {
            $sql .= ' AND title_normalized = :title_normalized';
            $params[':title_normalized'] = $titleNormalized;
            if ($year === null) {
                $sql .= ' AND year IS NULL';
            } else {
                $sql .= ' AND year = :year';
                $params[':year'] = $year;
            }
        }

        $sql .= ' ORDER BY relative_path';
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function getLibraryEntriesByShowFolder(string $mediaType, string $showFolder): array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            "SELECT * FROM library_entries
             WHERE status IN ('active', 'missing')
               AND media_type = :media_type
               AND show_folder = :show_folder
             ORDER BY season, episode, relative_path"
        );
        $stmt->execute([
            ':media_type' => $mediaType,
            ':show_folder' => $showFolder,
        ]);

        return $stmt->fetchAll();
    }

    public function getLibraryEntriesByFolder(string $rootKey, string $folderPath): array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            "SELECT * FROM library_entries
             WHERE root_key = :root_key
               AND folder_path = :folder_path
               AND status IN ('active', 'missing')
             ORDER BY relative_path"
        );
        $stmt->execute([
            ':root_key' => $rootKey,
            ':folder_path' => $folderPath,
        ]);

        return $stmt->fetchAll();
    }

    public function markEntryMissingByPath(string $path): int
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            "UPDATE library_entries SET status = 'missing' WHERE path = :path AND status = 'active'"
        );

        return $this->withRetry(function () use ($stmt, $path) {
            $stmt->execute([':path' => $path]);

            return $stmt->rowCount();
        });
    }

    public function getEntryById(int $id): ?array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            "SELECT * FROM library_entries WHERE id = :id AND status = 'active' LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function getActiveEntriesByRoot(string $rootKey): array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            "SELECT * FROM library_entries
             WHERE root_key = :root_key
               AND status = 'active'
             ORDER BY title_normalized, year, season, episode, relative_path"
        );
        $stmt->execute([':root_key' => $rootKey]);

        return $stmt->fetchAll();
    }

    public function getLibraryEntries(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $this->initialize();
        [$where, $params] = $this->libraryWhere($filters);
        $stmt = $this->getPdo()->prepare(
            'SELECT * FROM library_entries ' . $where . ' ORDER BY last_seen_at DESC, id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countLibraryEntries(array $filters = []): int
    {
        $this->initialize();
        [$where, $params] = $this->libraryWhere($filters);
        $stmt = $this->getPdo()->prepare('SELECT COUNT(*) FROM library_entries ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function getLibraryFilterCounts(): array
    {
        $this->initialize();
        $row = $this->getPdo()->query(
            "SELECT
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS all_count,
                SUM(CASE WHEN status = 'active' AND ignored_at IS NULL AND (tmdb_id IS NOT NULL OR imdb_id IS NOT NULL) THEN 1 ELSE 0 END) AS matched,
                SUM(CASE WHEN status = 'active' AND ignored_at IS NULL AND tmdb_id IS NULL AND imdb_id IS NULL THEN 1 ELSE 0 END) AS unmatched,
                SUM(CASE WHEN status = 'active' AND ignored_at IS NOT NULL THEN 1 ELSE 0 END) AS ignored,
                SUM(CASE WHEN status = 'missing' THEN 1 ELSE 0 END) AS missing
             FROM library_entries"
        )->fetch();

        return [
            'all' => (int) ($row['all_count'] ?? 0),
            'matched' => (int) ($row['matched'] ?? 0),
            'unmatched' => (int) ($row['unmatched'] ?? 0),
            'ignored' => (int) ($row['ignored'] ?? 0),
            'missing' => (int) ($row['missing'] ?? 0),
        ];
    }

    private function libraryWhere(array $filters): array
    {
        $status = (string) ($filters['status'] ?? 'all');
        $type = (string) ($filters['type'] ?? 'all');
        $query = trim((string) ($filters['q'] ?? ''));
        $conditions = [];
        $params = [];

        switch ($status) {
            case 'matched':
                $conditions[] = "status = 'active' AND ignored_at IS NULL AND (tmdb_id IS NOT NULL OR imdb_id IS NOT NULL)";
                break;
            case 'unmatched':
                $conditions[] = "status = 'active' AND ignored_at IS NULL AND tmdb_id IS NULL AND imdb_id IS NULL";
                break;
            case 'ignored':
                $conditions[] = "status = 'active' AND ignored_at IS NOT NULL";
                break;
            case 'missing':
                $conditions[] = "status = 'missing'";
                break;
            default:
                $conditions[] = "status = 'active'";
                break;
        }

        if (!empty($filters['root_key'])) {
            $conditions[] = 'root_key = :root_key';
            $params[':root_key'] = (string) $filters['root_key'];
        }

        if (in_array($type, ['movie', 'series', 'anime'], true)) {
            $conditions[] = 'media_type = :media_type';
            $params[':media_type'] = $type;
        }

        if ($query !== '') {
            $conditions[] = '(title LIKE :query OR relative_path LIKE :query OR media_key LIKE :query OR filename LIKE :query)';
            $params[':query'] = '%' . $query . '%';
        }

        return ['WHERE ' . implode(' AND ', $conditions), $params];
    }

    public function getEntriesForRename(array $filters = [], int $limit = 500): array
    {
        $this->initialize();
        $filters['status'] = 'matched';
        [$where, $params] = $this->libraryWhere($filters);
        $stmt = $this->getPdo()->prepare(
            'SELECT * FROM library_entries ' . $where . ' ORDER BY root_key, relative_path LIMIT :limit'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', max(1, min(2000, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getMoviesForTitleRefresh(int $limit = 5000): array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            "SELECT id, tmdb_id, title, title_normalized, year, path
             FROM library_entries
             WHERE status IN ('active', 'missing')
               AND media_type = 'movie'
               AND tmdb_id IS NOT NULL
               AND ignored_at IS NULL
             ORDER BY id
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', max(1, min(10000, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function updateEntryTitle(int $id, string $title, string $titleNormalized, ?int $year = null): void
    {
        $this->withRetry(function () use ($id, $title, $titleNormalized, $year) {
            $stmt = $this->getPdo()->prepare(
                'UPDATE library_entries
                 SET title = :title,
                     title_normalized = :title_normalized,
                     year = COALESCE(:year, year),
                     last_seen_at = :last_seen_at
                 WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $id,
                ':title' => $title,
                ':title_normalized' => $titleNormalized,
                ':year' => $year,
                ':last_seen_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        });
    }

    public function updateEntryPath(int $id, string $path, string $relativePath, string $filename, ?string $showFolder = null, ?string $title = null, ?int $year = null): void
    {
        $this->withRetry(function () use ($id, $path, $relativePath, $filename, $showFolder, $title, $year) {
            $stmt = $this->getPdo()->prepare(
                'UPDATE library_entries
                 SET path = :path,
                     relative_path = :relative_path,
                     filename = :filename,
                     show_folder = :show_folder,
                     title = COALESCE(:title, title),
                     year = COALESCE(:year, year),
                     last_seen_at = :last_seen_at
                 WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $id,
                ':path' => $path,
                ':relative_path' => $relativePath,
                ':filename' => $filename,
                ':show_folder' => $showFolder,
                ':title' => $title,
                ':year' => $year,
                ':last_seen_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        });
    }

    public function startRenameRun(string $scope = 'matched'): int
    {
        return $this->withRetry(function () use ($scope) {
            $stmt = $this->getPdo()->prepare(
                'INSERT INTO rename_runs (started_at, status, scope) VALUES (:started_at, :status, :scope)'
            );
            $stmt->execute([
                ':started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ':status' => 'running',
                ':scope' => $scope,
            ]);

            return (int) $this->getPdo()->lastInsertId();
        });
    }

    public function finishRenameRun(int $runId, array $stats, string $status, ?string $error = null): void
    {
        $this->withRetry(function () use ($runId, $stats, $status, $error) {
            $stmt = $this->getPdo()->prepare(
                'UPDATE rename_runs
                 SET finished_at = :finished_at,
                     status = :status,
                     entries_seen = :entries_seen,
                     entries_renamed = :entries_renamed,
                     entries_skipped = :entries_skipped,
                     entries_failed = :entries_failed,
                     duration = :duration,
                     details_json = :details_json,
                     error = :error
                 WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $runId,
                ':finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ':status' => $status,
                ':entries_seen' => (int) ($stats['entries_seen'] ?? 0),
                ':entries_renamed' => (int) ($stats['entries_renamed'] ?? 0),
                ':entries_skipped' => (int) ($stats['entries_skipped'] ?? 0),
                ':entries_failed' => (int) ($stats['entries_failed'] ?? 0),
                ':duration' => isset($stats['duration']) ? (float) $stats['duration'] : null,
                ':details_json' => json_encode($stats['details'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':error' => $error,
            ]);
        });
    }

    public function getRenameRun(int $id): ?array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare('SELECT * FROM rename_runs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getRecentRenameRuns(int $limit = 10): array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare('SELECT * FROM rename_runs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getStats(): array
    {
        $this->initialize();
        $row = $this->getPdo()->query(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN ignored_at IS NOT NULL THEN 1 ELSE 0 END) AS ignored,
                SUM(CASE WHEN media_type = \'movie\' THEN 1 ELSE 0 END) AS movies,
                SUM(CASE WHEN media_type IN (\'series\', \'anime\') THEN 1 ELSE 0 END) AS series,
                SUM(CASE WHEN media_type IN (\'series\', \'anime\') AND episode IS NOT NULL THEN 1 ELSE 0 END) AS episodes,
                SUM(CASE WHEN media_type = \'anime\' THEN 1 ELSE 0 END) AS anime,
                SUM(CASE WHEN ignored_at IS NULL AND tmdb_id IS NULL AND imdb_id IS NULL AND (confidence < 0.8 OR (media_type IN (\'series\', \'anime\') AND episode IS NULL)) THEN 1 ELSE 0 END) AS ambiguous
             FROM library_entries
             WHERE status = \'active\''
        )->fetch();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'ignored' => (int) ($row['ignored'] ?? 0),
            'movies' => (int) ($row['movies'] ?? 0),
            'series' => (int) ($row['series'] ?? 0),
            'episodes' => (int) ($row['episodes'] ?? 0),
            'anime' => (int) ($row['anime'] ?? 0),
            'ambiguous' => (int) ($row['ambiguous'] ?? 0),
        ];
    }

    public function getRootStats(): array
    {
        $this->initialize();
        $rows = $this->getPdo()->query(
            'SELECT root_key, COUNT(*) AS files,
                SUM(CASE WHEN media_type = \'movie\' THEN 1 ELSE 0 END) AS movies,
                SUM(CASE WHEN media_type IN (\'series\', \'anime\') THEN 1 ELSE 0 END) AS series,
                SUM(CASE WHEN media_type IN (\'series\', \'anime\') AND episode IS NOT NULL THEN 1 ELSE 0 END) AS episodes,
                SUM(CASE WHEN ignored_at IS NULL AND tmdb_id IS NULL AND imdb_id IS NULL AND (confidence < 0.8 OR (media_type IN (\'series\', \'anime\') AND episode IS NULL)) THEN 1 ELSE 0 END) AS ambiguous
             FROM library_entries
             WHERE status = \'active\'
             GROUP BY root_key'
        )->fetchAll();

        $stats = [];
        foreach ($rows as $row) {
            $stats[(string) $row['root_key']] = [
                'files' => (int) $row['files'],
                'movies' => (int) $row['movies'],
                'series' => (int) $row['series'],
                'episodes' => (int) $row['episodes'],
                'ambiguous' => (int) $row['ambiguous'],
            ];
        }

        return $stats;
    }

    public function getRecentEntries(int $limit = 8): array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'SELECT * FROM library_entries WHERE status = \'active\' ORDER BY last_seen_at DESC, id DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getAmbiguousEntries(int $limit = 10): array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'SELECT * FROM library_entries
             WHERE status = \'active\'
               AND (confidence < 0.8 OR (media_type IN (\'series\', \'anime\') AND episode IS NULL))
             ORDER BY confidence ASC, last_seen_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function startListRun(string $sourceId, string $provider, string $mode = 'dry_run'): int
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'INSERT INTO list_runs (source_id, provider, mode, started_at, status)
             VALUES (:source_id, :provider, :mode, :started_at, :status)'
        );

        return $this->withRetry(function () use ($stmt, $sourceId, $provider, $mode) {
            $stmt->execute([
                ':source_id' => $sourceId,
                ':provider' => $provider,
                ':mode' => $mode,
                ':started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ':status' => 'running',
            ]);

            return (int) $this->getPdo()->lastInsertId();
        });
    }

    public function finishListRun(int $runId, array $stats, string $status = 'success', ?string $error = null): void
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'UPDATE list_runs
             SET finished_at = :finished_at,
                 status = :status,
                 lists_seen = :lists_seen,
                 items_seen = :items_seen,
                 items_added = :items_added,
                 items_removed = :items_removed,
                 present = :present,
                 missing = :missing,
                 ambiguous = :ambiguous,
                 ignored = :ignored,
                 episodes_present = :episodes_present,
                 episodes_missing = :episodes_missing,
                 lookups = :lookups,
                 duration = :duration,
                 error = :error,
                 stats_json = :stats_json
             WHERE id = :id'
        );
        $this->withRetry(function () use ($stmt, $runId, $stats, $status, $error) {
            $stmt->execute([
                ':id' => $runId,
                ':finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ':status' => $status,
                ':lists_seen' => (int) ($stats['lists_seen'] ?? 0),
                ':items_seen' => (int) ($stats['items_seen'] ?? 0),
                ':items_added' => (int) ($stats['items_added'] ?? 0),
                ':items_removed' => (int) ($stats['items_removed'] ?? 0),
                ':present' => (int) ($stats['present'] ?? 0),
                ':missing' => (int) ($stats['missing'] ?? 0),
                ':ambiguous' => (int) ($stats['ambiguous'] ?? 0),
                ':ignored' => (int) ($stats['ignored'] ?? 0),
                ':episodes_present' => (int) ($stats['episodes_present'] ?? 0),
                ':episodes_missing' => (int) ($stats['episodes_missing'] ?? 0),
                ':lookups' => (int) ($stats['lookups'] ?? 0),
                ':duration' => isset($stats['duration']) ? (float) $stats['duration'] : null,
                ':error' => $error,
                ':stats_json' => json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    public function updateListRunProgress(int $runId, array $stats): void
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'UPDATE list_runs
             SET lists_seen = :lists_seen,
                 items_seen = :items_seen,
                 items_added = :items_added,
                 items_removed = :items_removed,
                 present = :present,
                 missing = :missing,
                 ambiguous = :ambiguous,
                 ignored = :ignored,
                 episodes_present = :episodes_present,
                 episodes_missing = :episodes_missing,
                 lookups = :lookups,
                 stats_json = :stats_json
             WHERE id = :id'
        );
        $this->withRetry(function () use ($stmt, $runId, $stats) {
            $stmt->execute([
                ':id' => $runId,
                ':lists_seen' => (int) ($stats['lists_seen'] ?? 0),
                ':items_seen' => (int) ($stats['items_seen'] ?? 0),
                ':items_added' => (int) ($stats['items_added'] ?? 0),
                ':items_removed' => (int) ($stats['items_removed'] ?? 0),
                ':present' => (int) ($stats['present'] ?? 0),
                ':missing' => (int) ($stats['missing'] ?? 0),
                ':ambiguous' => (int) ($stats['ambiguous'] ?? 0),
                ':ignored' => (int) ($stats['ignored'] ?? 0),
                ':episodes_present' => (int) ($stats['episodes_present'] ?? 0),
                ':episodes_missing' => (int) ($stats['episodes_missing'] ?? 0),
                ':lookups' => (int) ($stats['lookups'] ?? 0),
                ':stats_json' => json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    public function getLastListRun(?string $sourceId = null): ?array
    {
        $this->initialize();
        if ($sourceId !== null && $sourceId !== '') {
            $stmt = $this->getPdo()->prepare('SELECT * FROM list_runs WHERE source_id = :source_id ORDER BY id DESC LIMIT 1');
            $stmt->execute([':source_id' => $sourceId]);
            $row = $stmt->fetch();
        } else {
            $row = $this->getPdo()->query('SELECT * FROM list_runs ORDER BY id DESC LIMIT 1')->fetch();
        }

        return $this->hydrateListRun($row ?: null);
    }

    public function getRunningListRun(?string $sourceId = null): ?array
    {
        $this->initialize();
        $sql = 'SELECT * FROM list_runs WHERE status = :status AND started_at >= :threshold';
        $params = [
            ':status' => 'running',
            ':threshold' => (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'),
        ];
        if ($sourceId !== null && $sourceId !== '') {
            $sql .= ' AND source_id = :source_id';
            $params[':source_id'] = $sourceId;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);

        return $this->hydrateListRun($stmt->fetch() ?: null);
    }

    public function markStaleListRuns(): int
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'UPDATE list_runs
             SET status = :error_status,
                 finished_at = :finished_at,
                 error = COALESCE(error, :message)
             WHERE status = :running_status AND started_at < :threshold'
        );
        $stmt->execute([
            ':error_status' => 'error',
            ':running_status' => 'running',
            ':finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':message' => 'List sync interrupted or stale.',
            ':threshold' => (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'),
        ]);

        return $stmt->rowCount();
    }

    private function hydrateListRun(?array $row): ?array
    {
        if (!$row) {
            return null;
        }

        $row['stats'] = [];
        if (!empty($row['stats_json'])) {
            $decoded = json_decode((string) $row['stats_json'], true);
            if (is_array($decoded)) {
                $row['stats'] = $decoded;
            }
        }

        return $row;
    }

    public function getRecentListRuns(int $limit = 10, ?string $sourceId = null): array
    {
        $this->initialize();
        $sql = 'SELECT * FROM list_runs';
        $params = [];
        if ($sourceId !== null && $sourceId !== '') {
            $sql .= ' WHERE source_id = :source_id';
            $params[':source_id'] = $sourceId;
        }
        $sql .= ' ORDER BY id DESC LIMIT :limit';
        $stmt = $this->getPdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map(fn ($row) => $this->hydrateListRun($row), $stmt->fetchAll()));
    }

    public function upsertListItem(array $item, int $runId): string
    {
        return $this->withRetry(fn () => $this->doUpsertListItem($item, $runId));
    }

    private function doUpsertListItem(array $item, int $runId): string
    {
        $this->initialize();
        $pdo = $this->getPdo();
        $sourceId = (string) ($item['source_id'] ?? '');
        $remoteKey = (string) ($item['remote_key'] ?? '');
        if ($sourceId === '' || $remoteKey === '') {
            return 'skipped';
        }

        $stmt = $pdo->prepare('SELECT id FROM list_items WHERE source_id = :source_id AND remote_key = :remote_key LIMIT 1');
        $stmt->execute([':source_id' => $sourceId, ':remote_key' => $remoteKey]);
        $existing = $stmt->fetch();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $payload = [
            ':source_id' => $sourceId,
            ':provider' => (string) ($item['provider'] ?? ''),
            ':list_ref' => (string) ($item['list_ref'] ?? ''),
            ':remote_key' => $remoteKey,
            ':remote_id' => isset($item['remote_id']) ? (string) $item['remote_id'] : null,
            ':media_type' => (string) ($item['media_type'] ?? 'movie'),
            ':tmdb_id' => isset($item['tmdb_id']) ? (int) $item['tmdb_id'] : null,
            ':imdb_id' => isset($item['imdb_id']) && $item['imdb_id'] !== '' ? (string) $item['imdb_id'] : null,
            ':tvdb_id' => isset($item['tvdb_id']) ? (int) $item['tvdb_id'] : null,
            ':title' => (string) ($item['title'] ?? ''),
            ':title_normalized' => (string) ($item['title_normalized'] ?? ''),
            ':original_title' => isset($item['original_title']) && $item['original_title'] !== '' ? (string) $item['original_title'] : null,
            ':year' => isset($item['year']) ? (int) $item['year'] : null,
            ':season' => isset($item['season']) ? (int) $item['season'] : null,
            ':episode' => isset($item['episode']) ? (int) $item['episode'] : null,
            ':raw_json' => json_encode($item['raw'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':last_run_id' => $runId,
            ':last_seen_at' => $now,
            ':first_seen_at' => $now,
        ];

        if (!$existing) {
            $insert = $pdo->prepare(
                'INSERT INTO list_items (
                    source_id, provider, list_ref, remote_key, remote_id, media_type,
                    tmdb_id, imdb_id, tvdb_id, title, title_normalized, original_title,
                    year, season, episode, raw_json, status, match_status,
                    last_run_id, first_seen_at, last_seen_at
                ) VALUES (
                    :source_id, :provider, :list_ref, :remote_key, :remote_id, :media_type,
                    :tmdb_id, :imdb_id, :tvdb_id, :title, :title_normalized, :original_title,
                    :year, :season, :episode, :raw_json, \'active\', \'pending\',
                    :last_run_id, :first_seen_at, :last_seen_at
                )'
            );
            $insert->execute($payload);

            return 'inserted';
        }

        $update = $pdo->prepare(
            'UPDATE list_items SET
                provider = :provider,
                list_ref = :list_ref,
                remote_id = :remote_id,
                media_type = :media_type,
                tmdb_id = :tmdb_id,
                imdb_id = :imdb_id,
                tvdb_id = :tvdb_id,
                title = :title,
                title_normalized = :title_normalized,
                original_title = :original_title,
                year = :year,
                season = :season,
                episode = :episode,
                raw_json = :raw_json,
                status = \'active\',
                match_status = \'pending\',
                last_run_id = :last_run_id,
                last_seen_at = :last_seen_at
             WHERE id = :id'
        );
        unset($payload[':source_id'], $payload[':remote_key'], $payload[':first_seen_at']);
        $payload[':id'] = (int) $existing['id'];
        $update->execute($payload);

        return 'updated';
    }

    public function markListItemsRemoved(string $sourceId, int $runId): int
    {
        if ($sourceId === '') {
            return 0;
        }

        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            "UPDATE list_items
             SET status = 'removed'
             WHERE source_id = :source_id
               AND status = 'active'
               AND (last_run_id IS NULL OR last_run_id != :run_id)"
        );

        return $this->withRetry(function () use ($stmt, $sourceId, $runId) {
            $stmt->execute([':source_id' => $sourceId, ':run_id' => $runId]);

            return $stmt->rowCount();
        });
    }

    public function getActiveListItems(string $sourceId, int $limit = 500): array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            "SELECT * FROM list_items
             WHERE source_id = :source_id AND status = 'active'
             ORDER BY media_type, title, season, episode, id
             LIMIT :limit"
        );
        $stmt->bindValue(':source_id', $sourceId);
        $stmt->bindValue(':limit', max(1, min(5000, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function updateListItemMatch(int $id, array $match, int $runId): void
    {
        $this->withRetry(function () use ($id, $match, $runId) {
            $stmt = $this->getPdo()->prepare(
                'UPDATE list_items SET
                    match_status = :match_status,
                    match_reason = :match_reason,
                    matched_entry_id = :matched_entry_id,
                    matched_tmdb_id = :matched_tmdb_id,
                    matched_folder = :matched_folder,
                    episodes_total = :episodes_total,
                    episodes_present = :episodes_present,
                    episodes_missing = :episodes_missing,
                    missing_json = :missing_json,
                    last_matched_at = :last_matched_at
                 WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $id,
                ':match_status' => (string) ($match['status'] ?? 'ambiguous'),
                ':match_reason' => isset($match['reason']) ? (string) $match['reason'] : null,
                ':matched_entry_id' => isset($match['matched_entry_id']) ? (int) $match['matched_entry_id'] : null,
                ':matched_tmdb_id' => isset($match['matched_tmdb_id']) ? (int) $match['matched_tmdb_id'] : null,
                ':matched_folder' => isset($match['matched_folder']) && $match['matched_folder'] !== '' ? (string) $match['matched_folder'] : null,
                ':episodes_total' => (int) ($match['episodes_total'] ?? 0),
                ':episodes_present' => (int) ($match['episodes_present'] ?? 0),
                ':episodes_missing' => (int) ($match['episodes_missing'] ?? 0),
                ':missing_json' => json_encode(array_values($match['missing_episodes'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':last_matched_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        });
    }

    public function setListItemIgnored(int $id, bool $ignored): bool
    {
        $this->initialize();

        return $this->withRetry(function () use ($id, $ignored) {
            $stmt = $this->getPdo()->prepare(
                'UPDATE list_items SET ignored_at = :ignored_at, match_status = :match_status WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $id,
                ':ignored_at' => $ignored ? (new \DateTimeImmutable())->format('Y-m-d H:i:s') : null,
                ':match_status' => $ignored ? 'ignored' : 'pending',
            ]);

            return $stmt->rowCount() > 0;
        });
    }

    public function getListItem(int $id): ?array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare('SELECT * FROM list_items WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function getListItems(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $this->initialize();
        [$where, $params] = $this->listWhere($filters);
        $stmt = $this->getPdo()->prepare(
            'SELECT * FROM list_items ' . $where . ' ORDER BY
                CASE match_status
                    WHEN \'ambiguous\' THEN 0
                    WHEN \'missing\' THEN 1
                    WHEN \'ignored\' THEN 2
                    WHEN \'present\' THEN 3
                    ELSE 4
                END, last_seen_at DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countListItems(array $filters = []): int
    {
        $this->initialize();
        [$where, $params] = $this->listWhere($filters);
        $stmt = $this->getPdo()->prepare('SELECT COUNT(*) FROM list_items ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function getListStatusCounts(?string $sourceId = null): array
    {
        $this->initialize();
        $sql = "SELECT
                    COUNT(*) AS all_count,
                    SUM(CASE WHEN match_status = 'present' THEN 1 ELSE 0 END) AS present,
                    SUM(CASE WHEN match_status = 'missing' THEN 1 ELSE 0 END) AS missing,
                    SUM(CASE WHEN match_status = 'ambiguous' THEN 1 ELSE 0 END) AS ambiguous,
                    SUM(CASE WHEN match_status = 'ignored' THEN 1 ELSE 0 END) AS ignored,
                    SUM(CASE WHEN match_status = 'pending' THEN 1 ELSE 0 END) AS pending
                FROM list_items
                WHERE status = 'active'";
        $params = [];
        if ($sourceId !== null && $sourceId !== '') {
            $sql .= ' AND source_id = :source_id';
            $params[':source_id'] = $sourceId;
        }
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return [
            'all' => (int) ($row['all_count'] ?? 0),
            'present' => (int) ($row['present'] ?? 0),
            'missing' => (int) ($row['missing'] ?? 0),
            'ambiguous' => (int) ($row['ambiguous'] ?? 0),
            'ignored' => (int) ($row['ignored'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
        ];
    }

    private function listWhere(array $filters): array
    {
        $status = (string) ($filters['status'] ?? 'all');
        $source = trim((string) ($filters['source'] ?? ''));
        $type = (string) ($filters['type'] ?? 'all');
        $query = trim((string) ($filters['q'] ?? ''));
        $conditions = ["status = 'active'"];
        $params = [];

        if (in_array($status, ['present', 'missing', 'ambiguous', 'ignored', 'pending'], true)) {
            $conditions[] = 'match_status = :match_status';
            $params[':match_status'] = $status;
        }
        if ($source !== '') {
            $conditions[] = 'source_id = :source_id';
            $params[':source_id'] = $source;
        }
        if (in_array($type, ['movie', 'series'], true)) {
            $conditions[] = 'media_type = :media_type';
            $params[':media_type'] = $type;
        }
        if ($query !== '') {
            $conditions[] = '(title LIKE :query OR original_title LIKE :query OR imdb_id LIKE :query OR CAST(tmdb_id AS TEXT) LIKE :query)';
            $params[':query'] = '%' . $query . '%';
        }

        return ['WHERE ' . implode(' AND ', $conditions), $params];
    }

    public function getLibraryEntriesForListItem(array $item): array
    {
        $this->initialize();
        $clauses = [];
        $params = [];

        if (!empty($item['tmdb_id'])) {
            $clauses[] = 'tmdb_id = :tmdb_id';
            $params[':tmdb_id'] = (int) $item['tmdb_id'];
        }
        if (!empty($item['imdb_id'])) {
            $clauses[] = 'imdb_id = :imdb_id';
            $params[':imdb_id'] = (string) $item['imdb_id'];
        }
        $titleNormalized = trim((string) ($item['title_normalized'] ?? ''));
        if ($titleNormalized !== '') {
            $titleClause = 'title_normalized = :title_normalized';
            $params[':title_normalized'] = $titleNormalized;
            if (!empty($item['year'])) {
                $titleClause .= ' AND (year = :year OR year IS NULL)';
                $params[':year'] = (int) $item['year'];
            }
            $clauses[] = $titleClause;
        }

        if (empty($clauses)) {
            return [];
        }

        $sql = "SELECT * FROM library_entries
                WHERE status IN ('active', 'missing')
                  AND (" . implode(' OR ', $clauses) . ')
                ORDER BY title, year, show_folder, season, episode, id';
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public const ACTIVE_INTENT_STATUSES = ['pending', 'resolving', 'queued', 'downloading', 'success'];

    public function hasActiveMovieEntry(?int $tmdbId, ?string $imdbId, string $titleNormalized, ?int $year, bool $includeIgnored = false): bool
    {
        $this->initialize();
        $clauses = [];
        $params = [];
        if ($tmdbId !== null && $tmdbId > 0) {
            $clauses[] = 'tmdb_id = :tmdb_id';
            $params[':tmdb_id'] = $tmdbId;
        }
        if ($imdbId !== null && $imdbId !== '') {
            $clauses[] = 'imdb_id = :imdb_id';
            $params[':imdb_id'] = $imdbId;
        }
        if ($titleNormalized !== '') {
            $clause = 'title_normalized = :title_normalized';
            $params[':title_normalized'] = $titleNormalized;
            if ($year !== null) {
                $clause .= ' AND (year = :year OR year IS NULL)';
                $params[':year'] = $year;
            }
            $clauses[] = $clause;
        }
        if (empty($clauses)) {
            return false;
        }

        $ignoredClause = $includeIgnored ? '' : ' AND ignored_at IS NULL';
        $stmt = $this->getPdo()->prepare(
            "SELECT COUNT(*) FROM library_entries
             WHERE status = 'active'
               {$ignoredClause}
               AND media_type = 'movie'
               AND (" . implode(' OR ', $clauses) . ')'
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function hasActiveEpisodeEntry(
        ?int $tmdbId,
        ?string $imdbId,
        string $titleNormalized,
        ?int $year,
        int $season,
        int $episode,
        bool $includeIgnored = false
    ): bool {
        $this->initialize();
        $clauses = [];
        $params = [];
        if ($tmdbId !== null && $tmdbId > 0) {
            $clauses[] = 'tmdb_id = :tmdb_id';
            $params[':tmdb_id'] = $tmdbId;
        }
        if ($imdbId !== null && $imdbId !== '') {
            $clauses[] = 'imdb_id = :imdb_id';
            $params[':imdb_id'] = $imdbId;
        }
        if ($titleNormalized !== '') {
            $clause = 'title_normalized = :title_normalized';
            $params[':title_normalized'] = $titleNormalized;
            if ($year !== null) {
                $clause .= ' AND (year = :year OR year IS NULL)';
                $params[':year'] = $year;
            }
            $clauses[] = $clause;
        }
        if (empty($clauses)) {
            return false;
        }

        $sql = "SELECT COUNT(*) FROM library_entries
                WHERE status = 'active'
                  AND media_type IN ('series', 'anime')
                  AND season = :season
                  AND episode = :episode
                  AND COALESCE(confidence, 0) >= 0.8
                  AND (parse_source IS NULL OR parse_source != 'multi_episode')
                  AND (" . implode(' OR ', $clauses) . ')';
        if (!$includeIgnored) {
            $sql = str_replace("status = 'active'", "status = 'active' AND ignored_at IS NULL", $sql);
        }
        $params[':season'] = $season;
        $params[':episode'] = $episode;

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function getListEpisodeCounts(?string $sourceId = null): array
    {
        $this->initialize();
        $sql = "SELECT
                    COALESCE(SUM(episodes_present), 0) AS present,
                    COALESCE(SUM(episodes_missing), 0) AS missing,
                    COALESCE(SUM(episodes_total), 0) AS total
                FROM list_items
                WHERE status = 'active' AND media_type IN ('series', 'anime')";
        $params = [];
        if ($sourceId !== null && $sourceId !== '') {
            $sql .= ' AND source_id = :source_id';
            $params[':source_id'] = $sourceId;
        }
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return [
            'present' => (int) ($row['present'] ?? 0),
            'missing' => (int) ($row['missing'] ?? 0),
            'total' => (int) ($row['total'] ?? 0),
        ];
    }

    public function findExistingIntent(array $data): ?array
    {
        $this->initialize();
        $mediaType = (string) ($data['media_type'] ?? 'movie');
        $season = isset($data['season']) && $data['season'] !== '' ? (int) $data['season'] : null;
        $episode = isset($data['episode']) && $data['episode'] !== '' ? (int) $data['episode'] : null;
        $isEpisode = in_array($mediaType, ['series', 'anime', 'episode'], true) && $season !== null && $episode !== null;

        $clauses = ['media_key = :media_key'];
        $params = [':media_key' => (string) ($data['media_key'] ?? '')];

        if ($isEpisode) {
            if (!empty($data['tmdb_id'])) {
                $clauses[] = '(tmdb_id = :episode_tmdb_id AND season = :episode_tmdb_season AND episode = :episode_tmdb_episode)';
                $params[':episode_tmdb_id'] = (int) $data['tmdb_id'];
                $params[':episode_tmdb_season'] = $season;
                $params[':episode_tmdb_episode'] = $episode;
            }
            if (!empty($data['imdb_id'])) {
                $clauses[] = '(imdb_id = :episode_imdb_id AND season = :episode_imdb_season AND episode = :episode_imdb_episode)';
                $params[':episode_imdb_id'] = (string) $data['imdb_id'];
                $params[':episode_imdb_season'] = $season;
                $params[':episode_imdb_episode'] = $episode;
            }
            $title = trim((string) ($data['title'] ?? ''));
            if ($title !== '' && empty($data['tmdb_id']) && empty($data['imdb_id'])) {
                $clause = '(title = :episode_title AND season = :episode_title_season AND episode = :episode_title_episode';
                $params[':episode_title'] = $title;
                $params[':episode_title_season'] = $season;
                $params[':episode_title_episode'] = $episode;
                if (!empty($data['year'])) {
                    $clause .= ' AND (year = :episode_title_year OR year IS NULL)';
                    $params[':episode_title_year'] = (int) $data['year'];
                }
                $clauses[] = $clause . ')';
            }
        } else {
            if (!empty($data['tmdb_id'])) {
                $clauses[] = 'tmdb_id = :tmdb_id';
                $params[':tmdb_id'] = (int) $data['tmdb_id'];
            }
            if (!empty($data['imdb_id'])) {
                $clauses[] = 'imdb_id = :imdb_id';
                $params[':imdb_id'] = (string) $data['imdb_id'];
            }
            $title = trim((string) ($data['title'] ?? ''));
            if ($title !== '') {
                $clause = 'title = :title';
                $params[':title'] = $title;
                if (!empty($data['year'])) {
                    $clause .= ' AND (year = :year OR year IS NULL)';
                    $params[':year'] = (int) $data['year'];
                }
                $clauses[] = $clause;
            }
        }

        $stmt = $this->getPdo()->prepare(
            'SELECT * FROM intents WHERE ' . implode(' OR ', $clauses) . ' ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getSeriesNumbering(?int $tmdbId, ?string $imdbId = null): ?array
    {
        $this->initialize();
        if ($tmdbId !== null && $tmdbId > 0) {
            $stmt = $this->getPdo()->prepare('SELECT * FROM series_numbering WHERE tmdb_id = :tmdb_id LIMIT 1');
            $stmt->execute([':tmdb_id' => $tmdbId]);
        } elseif ($imdbId !== null && $imdbId !== '') {
            $stmt = $this->getPdo()->prepare('SELECT * FROM series_numbering WHERE imdb_id = :imdb_id ORDER BY id DESC LIMIT 1');
            $stmt->execute([':imdb_id' => $imdbId]);
        } else {
            return null;
        }

        return $this->hydrateSeriesNumbering($stmt->fetch() ?: null);
    }

    public function getAllSeriesNumbering(int $limit = 500): array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare('SELECT * FROM series_numbering ORDER BY updated_at DESC, id DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, min(5000, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map(fn ($row) => $this->hydrateSeriesNumbering($row), $stmt->fetchAll()));
    }

    public function saveSeriesNumbering(array $data): int
    {
        $this->initialize();
        $tmdbId = !empty($data['tmdb_id']) ? (int) $data['tmdb_id'] : null;
        $imdbId = trim((string) ($data['imdb_id'] ?? ''));
        $existing = $this->getSeriesNumbering($tmdbId, $imdbId !== '' ? $imdbId : null);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $merged = array_merge([
            'title' => '',
            'source' => 'auto',
            'source_ref' => null,
            'segments' => [],
            'mapping' => [],
            'confidence' => 0,
            'status' => 'ok',
            'reason' => null,
            'tmdb_snapshot' => [],
            'target_mode' => 'local',
            'jellyfin_mode' => 'local',
        ], $existing ?? [], $data);
        $payload = [
            ':tmdb_id' => $tmdbId,
            ':imdb_id' => $imdbId !== '' ? $imdbId : null,
            ':title' => (string) ($merged['title'] ?? ''),
            ':source' => (string) ($merged['source'] ?? 'auto'),
            ':source_ref' => isset($merged['source_ref']) && $merged['source_ref'] !== '' ? (string) $merged['source_ref'] : null,
            ':segments_json' => json_encode(is_array($merged['segments'] ?? null) ? $merged['segments'] : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':mapping_json' => json_encode(is_array($merged['mapping'] ?? null) ? $merged['mapping'] : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':confidence' => (float) ($merged['confidence'] ?? 0),
            ':status' => (string) ($merged['status'] ?? 'ok'),
            ':reason' => isset($merged['reason']) && $merged['reason'] !== '' ? (string) $merged['reason'] : null,
            ':tmdb_snapshot_json' => json_encode(is_array($merged['tmdb_snapshot'] ?? null) ? $merged['tmdb_snapshot'] : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':target_mode' => in_array((string) ($merged['target_mode'] ?? 'local'), ['local', 'tmdb'], true) ? (string) $merged['target_mode'] : 'local',
            ':jellyfin_mode' => in_array((string) ($merged['jellyfin_mode'] ?? 'local'), ['local', 'tmdb'], true) ? (string) $merged['jellyfin_mode'] : 'local',
            ':updated_at' => $now,
        ];

        return $this->withRetry(function () use ($payload, $existing, $now) {
            if ($existing !== null) {
                $sets = [];
                foreach ($payload as $key => $value) {
                    if ($key === ':updated_at') {
                        continue;
                    }
                    $sets[] = substr($key, 1) . ' = ' . $key;
                }
                $sets[] = 'updated_at = :updated_at';
                $stmt = $this->getPdo()->prepare('UPDATE series_numbering SET ' . implode(', ', $sets) . ' WHERE id = :id');
                $params = $payload;
                $params[':id'] = (int) $existing['id'];
                $stmt->execute($params);

                return (int) $existing['id'];
            }

            $stmt = $this->getPdo()->prepare(
                'INSERT INTO series_numbering (
                    tmdb_id, imdb_id, title, source, source_ref, segments_json, mapping_json,
                    confidence, status, reason, tmdb_snapshot_json, target_mode, jellyfin_mode,
                    created_at, updated_at
                ) VALUES (
                    :tmdb_id, :imdb_id, :title, :source, :source_ref, :segments_json, :mapping_json,
                    :confidence, :status, :reason, :tmdb_snapshot_json, :target_mode, :jellyfin_mode,
                    :created_at, :updated_at
                )'
            );
            $params = $payload;
            $params[':created_at'] = $now;
            $stmt->execute($params);

            return (int) $this->getPdo()->lastInsertId();
        });
    }

    private function hydrateSeriesNumbering(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        foreach (['segments_json' => 'segments', 'mapping_json' => 'mapping', 'tmdb_snapshot_json' => 'tmdb_snapshot'] as $column => $key) {
            $decoded = json_decode((string) ($row[$column] ?? '[]'), true);
            $row[$key] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }

    public function createIntent(array $data, bool $reactivateOrphanSuccess = false): array
    {
        $this->initialize();
        $existing = $this->findExistingIntent($data);
        if ($existing !== null) {
            if ($reactivateOrphanSuccess
                && (string) ($existing['status'] ?? '') === 'success'
                && !$this->hasActiveEntryForIntent($existing)
            ) {
                $this->updateIntent((int) $existing['id'], [
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_attempt_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'download_id' => null,
                    'info_hash' => null,
                    'file_idx' => null,
                    'error_message' => 'Reactivated because the library file is missing.',
                ]);

                return ['status' => 'reactivated', 'intent' => $this->getIntent((int) $existing['id'])];
            }

            return ['status' => 'existing', 'intent' => $existing];
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->getPdo()->prepare(
            'INSERT INTO intents (
                source_id, source_list_id, media_key, media_type, tmdb_id, imdb_id,
                title, year, season, episode, release_season, release_episode, numbering_source,
                status, attempts, next_attempt_at, created_at, updated_at
            ) VALUES (
                :source_id, :source_list_id, :media_key, :media_type, :tmdb_id, :imdb_id,
                :title, :year, :season, :episode, :release_season, :release_episode, :numbering_source,
                :status, 0, :next_attempt_at, :created_at, :updated_at
            )'
        );
        $id = $this->withRetry(function () use ($stmt, $data, $now) {
            $stmt->execute([
                ':source_id' => isset($data['source_id']) && $data['source_id'] !== '' ? (string) $data['source_id'] : null,
                ':source_list_id' => isset($data['source_list_id']) && $data['source_list_id'] !== '' ? (string) $data['source_list_id'] : null,
                ':media_key' => (string) ($data['media_key'] ?? ''),
                ':media_type' => (string) ($data['media_type'] ?? 'movie'),
                ':tmdb_id' => !empty($data['tmdb_id']) ? (int) $data['tmdb_id'] : null,
                ':imdb_id' => !empty($data['imdb_id']) ? (string) $data['imdb_id'] : null,
                ':title' => (string) ($data['title'] ?? ''),
                ':year' => !empty($data['year']) ? (int) $data['year'] : null,
                ':season' => isset($data['season']) && $data['season'] !== '' ? (int) $data['season'] : null,
                ':episode' => isset($data['episode']) && $data['episode'] !== '' ? (int) $data['episode'] : null,
                ':release_season' => isset($data['release_season']) && $data['release_season'] !== '' ? (int) $data['release_season'] : null,
                ':release_episode' => isset($data['release_episode']) && $data['release_episode'] !== '' ? (int) $data['release_episode'] : null,
                ':numbering_source' => isset($data['numbering_source']) && $data['numbering_source'] !== '' ? (string) $data['numbering_source'] : null,
                ':status' => 'pending',
                ':next_attempt_at' => $now,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            return (int) $this->getPdo()->lastInsertId();
        });

        return ['status' => 'inserted', 'intent' => $this->getIntent($id)];
    }

    private function hasActiveEntryForIntent(array $intent): bool
    {
        $mediaType = (string) ($intent['media_type'] ?? 'movie');
        $tmdbId = !empty($intent['tmdb_id']) ? (int) $intent['tmdb_id'] : null;
        $imdbId = trim((string) ($intent['imdb_id'] ?? ''));
        $title = $this->normalizeLookupTitle((string) ($intent['title'] ?? ''));
        $year = !empty($intent['year']) ? (int) $intent['year'] : null;

        if (in_array($mediaType, ['series', 'anime', 'episode'], true)) {
            $season = isset($intent['season']) && $intent['season'] !== null ? (int) $intent['season'] : null;
            $episode = isset($intent['episode']) && $intent['episode'] !== null ? (int) $intent['episode'] : null;
            $releaseSeason = isset($intent['release_season']) && $intent['release_season'] !== null ? (int) $intent['release_season'] : $season;
            $releaseEpisode = isset($intent['release_episode']) && $intent['release_episode'] !== null ? (int) $intent['release_episode'] : $episode;
            if ($season === null || $episode === null) {
                return false;
            }
            if ($this->hasActiveEpisodeEntry($tmdbId, $imdbId !== '' ? $imdbId : null, $title, $year, $releaseSeason, $releaseEpisode, true)) {
                return true;
            }

            return $releaseSeason !== $season || $releaseEpisode !== $episode
                ? $this->hasActiveEpisodeEntry($tmdbId, $imdbId !== '' ? $imdbId : null, $title, $year, $season, $episode, true)
                : false;
        }

        return $this->hasActiveMovieEntry($tmdbId, $imdbId !== '' ? $imdbId : null, $title, $year, true);
    }

    private function normalizeLookupTitle(string $title): string
    {
        $title = trim($title);
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($title, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $title = $decomposed;
            }
        }
        $title = preg_replace('/\p{M}+/u', '', $title) ?? $title;
        $title = mb_strtolower($title, 'UTF-8');
        $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? $title;

        return trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    }

    public function getIntent(int $id): ?array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare('SELECT * FROM intents WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function updateIntent(int $id, array $fields): void
    {
        $allowed = [
            'source_id', 'source_list_id', 'media_key', 'media_type', 'tmdb_id', 'imdb_id',
            'title', 'year', 'season', 'episode', 'release_season', 'release_episode', 'numbering_source',
            'status', 'attempts', 'next_attempt_at', 'download_id',
            'info_hash', 'file_idx', 'selected_source', 'score', 'release_explanation',
            'error_message',
        ];
        $sets = [];
        $params = [':id' => $id, ':updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $sets[] = $key . ' = :' . $key;
            $params[':' . $key] = $value;
        }
        if (empty($sets)) {
            return;
        }
        $sets[] = 'updated_at = :updated_at';
        $stmt = $this->getPdo()->prepare('UPDATE intents SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $this->withRetry(fn () => $stmt->execute($params));
    }

    public function getPendingIntents(int $limit = 10): array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            "SELECT * FROM intents
             WHERE status IN ('pending', 'no_source', 'error')
               AND next_attempt_at IS NOT NULL
               AND next_attempt_at <= :now
             ORDER BY next_attempt_at, id
             LIMIT :limit"
        );
        $stmt->bindValue(':now', (new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getIntents(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $this->initialize();
        [$where, $params] = $this->intentWhere($filters);
        $stmt = $this->getPdo()->prepare(
            'SELECT * FROM intents ' . $where . ' ORDER BY
                CASE status
                    WHEN \'resolving\' THEN 0
                    WHEN \'pending\' THEN 1
                    WHEN \'queued\' THEN 2
                    WHEN \'downloading\' THEN 3
                    WHEN \'error\' THEN 4
                    WHEN \'no_source\' THEN 5
                    WHEN \'success\' THEN 6
                    ELSE 7
                END, updated_at DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countIntents(array $filters = []): int
    {
        $this->initialize();
        [$where, $params] = $this->intentWhere($filters);
        $stmt = $this->getPdo()->prepare('SELECT COUNT(*) FROM intents ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function intentWhere(array $filters): array
    {
        $status = (string) ($filters['status'] ?? 'all');
        $source = trim((string) ($filters['source'] ?? ''));
        $type = (string) ($filters['type'] ?? 'all');
        $season = $filters['season'] ?? null;
        $query = trim((string) ($filters['q'] ?? ''));
        $conditions = [];
        $params = [];

        if (in_array($status, ['pending', 'resolving', 'queued', 'downloading', 'success', 'error', 'no_source', 'ignored'], true)) {
            $conditions[] = 'status = :status';
            $params[':status'] = $status;
        }
        if ($source !== '') {
            $conditions[] = 'source_id = :source_id';
            $params[':source_id'] = $source;
        }
        if (in_array($type, ['movie', 'series', 'anime'], true)) {
            $conditions[] = 'media_type = :media_type';
            $params[':media_type'] = $type;
        }
        if (is_numeric($season)) {
            $conditions[] = 'season = :season';
            $params[':season'] = (int) $season;
        }
        if ($query !== '') {
            $conditions[] = '(title LIKE :query OR media_key LIKE :query OR imdb_id LIKE :query OR CAST(tmdb_id AS TEXT) LIKE :query)';
            $params[':query'] = '%' . $query . '%';
        }

        return [$conditions ? 'WHERE ' . implode(' AND ', $conditions) : '', $params];
    }

    public function getIntentStatusCounts(): array
    {
        $this->initialize();
        $row = $this->getPdo()->query(
            "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'resolving' THEN 1 ELSE 0 END) AS resolving,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued,
                SUM(CASE WHEN status = 'downloading' THEN 1 ELSE 0 END) AS downloading,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS error,
                SUM(CASE WHEN status = 'no_source' THEN 1 ELSE 0 END) AS no_source,
                SUM(CASE WHEN status = 'ignored' THEN 1 ELSE 0 END) AS ignored,
                SUM(CASE WHEN media_type = 'movie' THEN 1 ELSE 0 END) AS movies,
                SUM(CASE WHEN media_type IN ('series', 'anime') THEN 1 ELSE 0 END) AS series
             FROM intents"
        )->fetch();

        return [
            'all' => (int) ($row['total'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'resolving' => (int) ($row['resolving'] ?? 0),
            'queued' => (int) ($row['queued'] ?? 0),
            'downloading' => (int) ($row['downloading'] ?? 0),
            'success' => (int) ($row['success'] ?? 0),
            'error' => (int) ($row['error'] ?? 0),
            'no_source' => (int) ($row['no_source'] ?? 0),
            'ignored' => (int) ($row['ignored'] ?? 0),
            'movies' => (int) ($row['movies'] ?? 0),
            'series' => (int) ($row['series'] ?? 0),
        ];
    }

    public function markStaleResolvingIntents(int $minutes = 30): int
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            "UPDATE intents
             SET status = 'pending',
                 next_attempt_at = :now,
                 updated_at = :now,
                 error_message = COALESCE(error_message, 'Resolving interrupted; retry scheduled.')
             WHERE status = 'resolving' AND updated_at < :threshold"
        );

        return $this->withRetry(function () use ($stmt, $minutes) {
            $now = new \DateTimeImmutable();
            $stmt->execute([
                ':now' => $now->format('Y-m-d H:i:s'),
                ':threshold' => $now->modify('-' . max(1, $minutes) . ' minutes')->format('Y-m-d H:i:s'),
            ]);

            return $stmt->rowCount();
        });
    }

    public function startIntentRun(array $stats = []): int
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'INSERT INTO intent_runs (started_at, status, stats_json) VALUES (:started_at, :status, :stats_json)'
        );

        return $this->withRetry(function () use ($stmt, $stats) {
            $stmt->execute([
                ':started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ':status' => 'running',
                ':stats_json' => json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            return (int) $this->getPdo()->lastInsertId();
        });
    }

    public function finishIntentRun(int $runId, array $stats, string $status = 'success', ?string $error = null): void
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'UPDATE intent_runs SET
                finished_at = :finished_at,
                status = :status,
                intents_seen = :intents_seen,
                intents_queued = :intents_queued,
                intents_skipped = :intents_skipped,
                intents_no_source = :intents_no_source,
                intents_error = :intents_error,
                queue_depth = :queue_depth,
                duration = :duration,
                error = :error,
                stats_json = :stats_json
             WHERE id = :id'
        );
        $this->withRetry(function () use ($stmt, $runId, $stats, $status, $error) {
            $stmt->execute([
                ':id' => $runId,
                ':finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ':status' => $status,
                ':intents_seen' => (int) ($stats['intents_seen'] ?? 0),
                ':intents_queued' => (int) ($stats['intents_queued'] ?? 0),
                ':intents_skipped' => (int) ($stats['intents_skipped'] ?? 0),
                ':intents_no_source' => (int) ($stats['intents_no_source'] ?? 0),
                ':intents_error' => (int) ($stats['intents_error'] ?? 0),
                ':queue_depth' => (int) ($stats['queue_depth'] ?? 0),
                ':duration' => isset($stats['duration']) ? (float) $stats['duration'] : null,
                ':error' => $error,
                ':stats_json' => json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    public function updateIntentRunProgress(int $runId, array $stats): void
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'UPDATE intent_runs SET
                intents_seen = :intents_seen,
                intents_queued = :intents_queued,
                intents_skipped = :intents_skipped,
                intents_no_source = :intents_no_source,
                intents_error = :intents_error,
                queue_depth = :queue_depth,
                stats_json = :stats_json
             WHERE id = :id'
        );
        $this->withRetry(function () use ($stmt, $runId, $stats) {
            $stmt->execute([
                ':id' => $runId,
                ':intents_seen' => (int) ($stats['intents_seen'] ?? 0),
                ':intents_queued' => (int) ($stats['intents_queued'] ?? 0),
                ':intents_skipped' => (int) ($stats['intents_skipped'] ?? 0),
                ':intents_no_source' => (int) ($stats['intents_no_source'] ?? 0),
                ':intents_error' => (int) ($stats['intents_error'] ?? 0),
                ':queue_depth' => (int) ($stats['queue_depth'] ?? 0),
                ':stats_json' => json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    public function getLastIntentRun(): ?array
    {
        $this->initialize();
        $row = $this->getPdo()->query('SELECT * FROM intent_runs ORDER BY id DESC LIMIT 1')->fetch();

        return $this->hydrateIntentRun($row ?: null);
    }

    public function getRunningIntentRun(): ?array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'SELECT * FROM intent_runs WHERE status = :status AND started_at >= :threshold ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            ':status' => 'running',
            ':threshold' => (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'),
        ]);

        return $this->hydrateIntentRun($stmt->fetch() ?: null);
    }

    public function markStaleIntentRuns(): int
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare(
            'UPDATE intent_runs
             SET status = :error_status,
                 finished_at = :finished_at,
                 error = COALESCE(error, :message)
             WHERE status = :running_status AND started_at < :threshold'
        );
        $stmt->execute([
            ':error_status' => 'error',
            ':running_status' => 'running',
            ':finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':message' => 'Intent run interrupted or stale.',
            ':threshold' => (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'),
        ]);

        return $stmt->rowCount();
    }

    public function getRecentIntentRuns(int $limit = 12): array
    {
        $this->initialize();
        $stmt = $this->getPdo()->prepare('SELECT * FROM intent_runs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map(fn ($row) => $this->hydrateIntentRun($row), $stmt->fetchAll()));
    }

    private function hydrateIntentRun(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        $row['stats'] = [];
        if (!empty($row['stats_json'])) {
            $decoded = json_decode((string) $row['stats_json'], true);
            if (is_array($decoded)) {
                $row['stats'] = $decoded;
            }
        }

        return $row;
    }

    public function setWorkerHeartbeat(): void
    {
        $this->setSetting('automation_worker_heartbeat', time());
    }

    public function getWorkerHeartbeat(): int
    {
        return (int) $this->getSetting('automation_worker_heartbeat', 0);
    }

    public function isWorkerOnline(int $threshold = 15): bool
    {
        $heartbeat = $this->getWorkerHeartbeat();
        return $heartbeat > 0 && (time() - $heartbeat) <= $threshold;
    }
}
