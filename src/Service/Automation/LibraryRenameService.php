<?php

namespace App\Service\Automation;

use App\Service\TmdbService;

class LibraryRenameService
{
    private const VIDEO_EXTENSIONS = ['mkv', 'mp4', 'avi', 'm4v', 'webm', 'ts', 'm2ts', 'mpg', 'mpeg', 'mov', 'wmv'];

    private AutomationStore $store;
    private AutomationSettings $settings;
    private TmdbService $tmdb;
    private array $detailsCache = [];
    private array $dirCache = [];

    public function __construct(AutomationStore $store, AutomationSettings $settings, TmdbService $tmdb)
    {
        $this->store = $store;
        $this->settings = $settings;
        $this->tmdb = $tmdb;
    }

    public function preview(array $filters = [], int $limit = 500): array
    {
        return $this->buildPlanForEntries($this->store->getEntriesForRename($filters, $limit));
    }

    public function lightPreview(array $filters = [], int $limit = 5000): array
    {
        return $this->buildLightPlanForEntries($this->store->getEntriesForRename($filters, $limit));
    }

    public function lightPreviewEntries(array $entries): array
    {
        return $this->buildLightPlanForEntries($entries);
    }

    public function previewEntries(array $entries): array
    {
        return $this->buildPlanForEntries($entries);
    }

    public function apply(array $filters = [], int $limit = 500): array
    {
        $lock = $this->acquireLock();
        if ($lock === null) {
            return ['success' => false, 'message' => 'A rename run is already in progress.'];
        }

        $runId = $this->store->startRenameRun('matched');
        $stats = ['entries_seen' => 0, 'entries_renamed' => 0, 'entries_skipped' => 0, 'entries_failed' => 0, 'details' => []];
        $started = microtime(true);

        try {
            $plan = $this->preview($filters, $limit);
            $stats['entries_seen'] = $plan['entries_seen'];

            foreach ($plan['items'] as $item) {
                if ($item['status'] !== 'ok') {
                    $stats['entries_skipped']++;
                    continue;
                }

                $entry = $item['entry'];
                $oldPath = (string) $entry['path'];
                $newPath = (string) $item['target'];

                try {
                    if (!is_file($oldPath)) {
                        $stats['entries_failed']++;
                        continue;
                    }
                    if (file_exists($newPath) && realpath($oldPath) !== realpath($newPath)) {
                        $stats['entries_skipped']++;
                        continue;
                    }

                    $newDir = dirname($newPath);
                    if (!is_dir($newDir) && !@mkdir($newDir, 0777, true) && !is_dir($newDir)) {
                        throw new \RuntimeException('Could not create target directory: ' . $newDir);
                    }

                    $this->moveFile($oldPath, $newPath);

                    foreach ($item['sidecars'] as $oldSidecar => $newSidecar) {
                        if (!is_file($oldSidecar)) {
                            continue;
                        }
                        $sidecarDir = dirname($newSidecar);
                        if (!is_dir($sidecarDir) && !@mkdir($sidecarDir, 0777, true) && !is_dir($sidecarDir)) {
                            throw new \RuntimeException('Could not create sidecar directory: ' . $sidecarDir);
                        }
                        if (file_exists($newSidecar) && realpath($oldSidecar) !== realpath($newSidecar)) {
                            continue;
                        }
                        $this->moveFile($oldSidecar, $newSidecar);
                    }

                    $relativePath = $this->relativeToRoot($newPath, (string) $item['root_path']);
                    $this->store->updateEntryPath(
                        (int) $entry['id'],
                        $newPath,
                        $relativePath,
                        basename($newPath),
                        $item['show_folder'],
                        $item['title'],
                        $item['year']
                    );

                    $stats['entries_renamed']++;
                    $stats['details'][] = [
                        'id' => (int) $entry['id'],
                        'from' => $oldPath,
                        'to' => $newPath,
                        'sidecars' => $item['sidecars'],
                        'root_path' => (string) $item['root_path'],
                        'status' => 'renamed',
                    ];
                } catch (\Throwable $e) {
                    $stats['entries_failed']++;
                    $stats['details'][] = [
                        'id' => (int) $entry['id'],
                        'from' => $oldPath,
                        'to' => $newPath,
                        'sidecars' => $item['sidecars'],
                        'root_path' => (string) $item['root_path'],
                        'status' => 'error',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $stats['duration'] = round(microtime(true) - $started, 2);
            $status = $stats['entries_failed'] > 0 ? 'warning' : 'success';
            $this->store->finishRenameRun($runId, $stats, $status);

            return ['success' => true, 'stats' => $stats];
        } catch (\Throwable $e) {
            $stats['duration'] = round(microtime(true) - $started, 2);
            $this->store->finishRenameRun($runId, $stats, 'error', $e->getMessage());
            throw $e;
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function pruneEmptyFolders(?array $rootKeys = null, bool $dryRun = true): array
    {
        $roots = $this->settings->getRoots();
        $stats = [
            'roots_seen' => 0,
            'dirs_seen' => 0,
            'candidates' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'failed' => 0,
            'warnings' => [],
        ];
        $items = [];
        $skipNames = ['@eadir', '#recycle', '$recycle.bin', 'lost+found', 'system volume information'];

        foreach ($roots as $rootKey => $root) {
            if ($rootKeys !== null && !in_array((string) $rootKey, $rootKeys, true)) {
                continue;
            }
            if (!is_array($root) || empty($root['enabled']) || empty($root['path'])) {
                $stats['skipped']++;
                continue;
            }
            $rootPath = rtrim((string) $root['path'], '/\\');
            if (!is_dir($rootPath)) {
                $stats['skipped']++;
                $stats['warnings'][] = $rootKey . ': path does not exist (' . $rootPath . ').';
                continue;
            }
            $stats['roots_seen']++;

            $directories = [];
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($rootPath, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $file) {
                    if (!$file instanceof \SplFileInfo || !$file->isDir() || $file->isLink()) {
                        continue;
                    }
                    $name = strtolower($file->getFilename());
                    if ($name === '' || str_starts_with($name, '.') || in_array($name, $skipNames, true)) {
                        continue;
                    }
                    $path = rtrim(str_replace('\\', '/', $file->getPathname()), '/');
                    $directories[] = $path;
                    $stats['dirs_seen']++;
                }
            } catch (\Throwable $e) {
                $stats['warnings'][] = $rootKey . ': could not browse (' . $e->getMessage() . ').';
                continue;
            }

            usort($directories, static fn (string $a, string $b): int => substr_count($b, '/') <=> substr_count($a, '/'));
            $candidateMap = [];
            $rootCandidates = [];
            foreach ($directories as $path) {
                if (is_link($path) || !is_dir($path)) {
                    continue;
                }
                $children = @scandir($path);
                if ($children === false) {
                    $stats['failed']++;
                    continue;
                }
                $removable = true;
                foreach ($children as $child) {
                    if ($child === '.' || $child === '..') {
                        continue;
                    }
                    $childPath = $path . '/' . $child;
                    if (!isset($candidateMap[$childPath])) {
                        $removable = false;
                        break;
                    }
                }
                if ($removable) {
                    $candidateMap[$path] = true;
                    $rootCandidates[] = $path;
                }
            }

            $stats['candidates'] += count($rootCandidates);
            foreach ($rootCandidates as $path) {
                $items[] = ['root_key' => (string) $rootKey, 'path' => $path];
            }

            if (!$dryRun) {
                foreach ($rootCandidates as $path) {
                    if (@rmdir($path)) {
                        $stats['deleted']++;
                    } else {
                        $stats['failed']++;
                    }
                }
            }
        }

        return [
            'success' => true,
            'dry_run' => $dryRun,
            'stats' => $stats,
            'items' => $items,
        ];
    }

    public function undo(int $runId, bool $apply = true): array
    {
        $run = $this->store->getRenameRun($runId);
        if ($run === null) {
            return ['success' => false, 'message' => 'Rename run not found.'];
        }
        $details = json_decode((string) ($run['details_json'] ?? '[]'), true);
        if (!is_array($details) || empty($details)) {
            return ['success' => false, 'message' => 'Rename run has no recorded file moves.'];
        }

        $lock = $this->acquireLock();
        if ($lock === null) {
            return ['success' => false, 'message' => 'A rename run is already in progress.'];
        }

        $stats = ['run_id' => $runId, 'entries_seen' => count($details), 'entries_reverted' => 0, 'entries_skipped' => 0, 'entries_failed' => 0, 'details' => []];
        try {
            foreach (array_reverse($details) as $detail) {
                $from = (string) ($detail['from'] ?? '');
                $to = (string) ($detail['to'] ?? '');
                if ($from === '' || $to === '') {
                    $stats['entries_skipped']++;
                    continue;
                }
                if (!is_file($to)) {
                    $stats['entries_skipped']++;
                    $stats['details'][] = ['id' => $detail['id'] ?? null, 'from' => $to, 'to' => $from, 'status' => 'skip', 'error' => 'Current target file is missing.'];
                    continue;
                }
                if (file_exists($from) && realpath($from) !== realpath($to)) {
                    $stats['entries_skipped']++;
                    $stats['details'][] = ['id' => $detail['id'] ?? null, 'from' => $to, 'to' => $from, 'status' => 'conflict', 'error' => 'Original path already exists.'];
                    continue;
                }

                if ($apply) {
                    $oldDir = dirname($from);
                    if (!is_dir($oldDir) && !@mkdir($oldDir, 0777, true) && !is_dir($oldDir)) {
                        throw new \RuntimeException('Could not create original directory: ' . $oldDir);
                    }
                    $this->moveFile($to, $from);

                    foreach ((array) ($detail['sidecars'] ?? []) as $oldSidecar => $newSidecar) {
                        if (!is_file($newSidecar)) {
                            continue;
                        }
                        if (file_exists($oldSidecar) && realpath($oldSidecar) !== realpath($newSidecar)) {
                            continue;
                        }
                        $sidecarDir = dirname($oldSidecar);
                        if (!is_dir($sidecarDir) && !@mkdir($sidecarDir, 0777, true) && !is_dir($sidecarDir)) {
                            throw new \RuntimeException('Could not create sidecar directory: ' . $sidecarDir);
                        }
                        $this->moveFile($newSidecar, $oldSidecar);
                    }

                    $entry = $this->store->getEntryById((int) ($detail['id'] ?? 0));
                    if ($entry !== null) {
                        $rootPath = (string) ($detail['root_path'] ?? '');
                        if ($rootPath === '') {
                            $roots = $this->settings->getRoots();
                            $rootPath = (string) ($roots[$entry['root_key']]['path'] ?? '');
                        }
                        $relativePath = $rootPath !== '' ? $this->relativeToRoot($from, $rootPath) : basename($from);
                        $this->store->updateEntryPath(
                            (int) $entry['id'],
                            $from,
                            $relativePath,
                            basename($from),
                            isset($entry['show_folder']) && $entry['show_folder'] !== '' ? (string) $entry['show_folder'] : null,
                            isset($entry['title']) ? (string) $entry['title'] : null,
                            $entry['year'] !== null ? (int) $entry['year'] : null
                        );
                    }
                }

                $stats['entries_reverted']++;
                $stats['details'][] = ['id' => $detail['id'] ?? null, 'from' => $to, 'to' => $from, 'status' => $apply ? 'reverted' : 'would_revert'];
            }

            return ['success' => true, 'stats' => $stats];
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function buildLightPlanForEntries(array $entries): array
    {
        $roots = $this->settings->getRoots();
        $items = [];
        $targets = [];
        $stats = ['entries_seen' => count($entries), 'items' => []];

        foreach ($entries as $entry) {
            $root = $roots[$entry['root_key']] ?? null;
            if (!is_array($root) || empty($root['enabled']) || empty($root['path'])) {
                $stats['items'][] = $this->skipItem($entry, 'Library root is disabled or has no path.');
                continue;
            }
            if (($entry['status'] ?? '') !== 'active') {
                $stats['items'][] = $this->skipItem($entry, 'Entry is not active.', null, (string) $root['path']);
                continue;
            }
            if (!empty($entry['ignored_at'])) {
                $stats['items'][] = $this->skipItem($entry, 'Entry is ignored.', null, (string) $root['path']);
                continue;
            }
            if (empty($entry['tmdb_id']) && empty($entry['imdb_id'])) {
                $stats['items'][] = $this->skipItem($entry, 'Not matched to TMDB.');
                continue;
            }

            $target = $this->buildLightTarget($entry, $root);
            if ($target['status'] !== 'ok') {
                $stats['items'][] = $target + ['entry' => $entry, 'root_path' => $root['path']];
                continue;
            }

            $key = $target['target'];
            if (isset($targets[$key])) {
                $stats['items'][] = $this->skipItem($entry, 'Two files target the same path.', $target['target'], $target['root_path'], 'conflict');
                continue;
            }
            $targets[$key] = true;

            if ($this->normalizePath((string) $entry['path']) === $this->normalizePath($target['target'])) {
                $stats['items'][] = $this->skipItem($entry, 'Already correctly named.', $target['target'], $target['root_path'], 'conform');
                continue;
            }

            $stats['items'][] = $target + [
                'entry' => $entry,
                'root_path' => $root['path'],
                'status' => 'ok',
                'reason' => null,
            ];
        }

        return $stats;
    }

    private function buildLightTarget(array $entry, array $root): array
    {
        $rootPath = rtrim((string) $root['path'], '/\\');
        $extension = strtolower((string) ($entry['extension'] ?: pathinfo((string) $entry['path'], PATHINFO_EXTENSION)));
        $title = $this->sanitizeName((string) $entry['title'], 160);
        if ($title === '') {
            return $this->skipItem($entry, 'Empty title.');
        }

        $year = $entry['year'] !== null ? (int) $entry['year'] : null;
        if ($entry['media_type'] === 'movie') {
            if ($year === null) {
                return $this->skipItem($entry, 'Missing year.');
            }
            $folder = $title . ' (' . $year . ')';
            $filename = $this->sanitizeName($folder . '.' . $extension, 220);

            return [
                'status' => 'ok',
                'reason' => null,
                'target' => $rootPath . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . $filename,
                'sidecars' => [],
                'show_folder' => null,
                'title' => $title,
                'year' => $year,
                'root_path' => $rootPath,
            ];
        }

        $season = $entry['season'] !== null ? (int) $entry['season'] : null;
        $episode = $entry['episode'] !== null ? (int) $entry['episode'] : null;
        if ($season === null || $episode === null) {
            return $this->skipItem($entry, 'Missing season or episode number.');
        }
        if ($season === 0) {
            return $this->skipItem($entry, 'Specials are not renamed automatically.');
        }
        if (empty($entry['tmdb_id'])) {
            return $this->skipItem($entry, 'Missing TMDB ID for episode lookup.');
        }

        $showFolder = $title . ($year !== null ? ' (' . $year . ')' : '');
        $seasonFolder = 'Season ' . str_pad((string) $season, 2, '0', STR_PAD_LEFT);
        $episodeCode = 'S' . str_pad((string) $season, 2, '0', STR_PAD_LEFT) . 'E' . str_pad((string) $episode, 2, '0', STR_PAD_LEFT);
        $filename = $this->sanitizeName($showFolder . ' - ' . $episodeCode . '.' . $extension, 220);

        return [
            'status' => 'ok',
            'reason' => null,
            'target' => $rootPath . DIRECTORY_SEPARATOR . $showFolder . DIRECTORY_SEPARATOR . $seasonFolder . DIRECTORY_SEPARATOR . $filename,
            'sidecars' => [],
            'show_folder' => $showFolder,
            'title' => $title,
            'year' => $year,
            'root_path' => $rootPath,
        ];
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', trim($path)), '/');
    }

    private function buildPlanForEntries(array $entries): array
    {
        $roots = $this->settings->getRoots();
        $items = [];
        $targets = [];
        $stats = ['entries_seen' => count($entries), 'items' => []];

        foreach ($entries as $entry) {
            $root = $roots[$entry['root_key']] ?? null;
            if (!is_array($root) || empty($root['enabled']) || empty($root['path'])) {
                $stats['items'][] = $this->skipItem($entry, 'Library root is disabled or has no path.');
                continue;
            }

            if (($entry['status'] ?? '') !== 'active') {
                $stats['items'][] = $this->skipItem($entry, 'Entry is not active.', null, (string) $root['path']);
                continue;
            }
            if (!empty($entry['ignored_at'])) {
                $stats['items'][] = $this->skipItem($entry, 'Entry is ignored.', null, (string) $root['path']);
                continue;
            }
            if (!is_writable((string) $root['path'])) {
                $stats['items'][] = $this->skipItem($entry, 'Library root is not writable.', null, (string) $root['path']);
                continue;
            }

            if (empty($entry['tmdb_id']) && empty($entry['imdb_id'])) {
                $stats['items'][] = $this->skipItem($entry, 'Not matched to TMDB.');
                continue;
            }

            if (!is_file((string) $entry['path'])) {
                $stats['items'][] = $this->skipItem($entry, 'Source file is missing.', null, $root['path']);
                continue;
            }

            $target = $this->buildTarget($entry, $root);
            if ($target['status'] !== 'ok') {
                $stats['items'][] = $target + ['entry' => $entry, 'root_path' => $root['path']];
                continue;
            }

            $key = $target['target'];
            if (isset($targets[$key])) {
                $stats['items'][] = $this->skipItem($entry, 'Two files target the same path.', $target['target'], $target['root_path'], 'conflict');
                continue;
            }
            $targets[$key] = true;

            if (file_exists($target['target']) && realpath($entry['path']) !== realpath($target['target'])) {
                $stats['items'][] = $this->skipItem($entry, 'Target file already exists.', $target['target'], $target['root_path'], 'conflict');
                continue;
            }
            foreach ($target['sidecars'] as $oldSidecar => $newSidecar) {
                if (file_exists($newSidecar) && realpath($oldSidecar) !== realpath($newSidecar)) {
                    $stats['items'][] = $this->skipItem($entry, 'A sidecar target already exists.', $target['target'], $target['root_path'], 'conflict');
                    continue 2;
                }
            }

            if (realpath($entry['path']) === realpath($target['target'])) {
                $stats['items'][] = $this->skipItem($entry, 'Already correctly named.', $target['target'], $target['root_path'], 'conform');
                continue;
            }

            $stats['items'][] = $target + [
                'entry' => $entry,
                'root_path' => $root['path'],
                'status' => 'ok',
                'reason' => null,
            ];
        }

        return $stats;
    }

    private function buildTarget(array $entry, array $root): array
    {
        $rootPath = rtrim((string) $root['path'], '/\\');
        $extension = strtolower((string) ($entry['extension'] ?: pathinfo((string) $entry['path'], PATHINFO_EXTENSION)));
        $title = $this->sanitizeName((string) $entry['title'], 160);
        if ($title === '') {
            return $this->skipItem($entry, 'Empty title.');
        }

        $year = $entry['year'] !== null ? (int) $entry['year'] : null;
        if ($year === null && !empty($entry['tmdb_id'])) {
            $details = $this->details((string) $entry['media_type'], (int) $entry['tmdb_id']);
            $year = !empty($details['year']) ? (int) $details['year'] : null;
        }

        if ($entry['media_type'] === 'movie') {
            if ($year === null) {
                return $this->skipItem($entry, 'Missing year.');
            }
            $folder = $title . ' (' . $year . ')';
            $filename = $folder . '.' . $extension;
            $filename = $this->sanitizeName($filename, 220);
            $target = $rootPath . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . $filename;
            $sidecars = $this->findSidecars((string) $entry['path'], $target, true);

            return [
                'status' => 'ok',
                'reason' => null,
                'target' => $target,
                'sidecars' => $sidecars,
                'show_folder' => null,
                'title' => $title,
                'year' => $year,
                'root_path' => $rootPath,
            ];
        }

        $season = $entry['season'] !== null ? (int) $entry['season'] : null;
        $episode = $entry['episode'] !== null ? (int) $entry['episode'] : null;
        if ($season === null || $episode === null) {
            return $this->skipItem($entry, 'Missing season or episode number.');
        }
        if ($season === 0) {
            return $this->skipItem($entry, 'Specials are not renamed automatically.');
        }
        if (empty($entry['tmdb_id'])) {
            return $this->skipItem($entry, 'Missing TMDB ID for episode lookup.');
        }

        $showFolder = $title . ($year !== null ? ' (' . $year . ')' : '');
        $seasonFolder = 'Season ' . str_pad((string) $season, 2, '0', STR_PAD_LEFT);
        $episodeCode = 'S' . str_pad((string) $season, 2, '0', STR_PAD_LEFT) . 'E' . str_pad((string) $episode, 2, '0', STR_PAD_LEFT);
        $filename = $showFolder . ' - ' . $episodeCode . '.' . $extension;
        $filename = $this->sanitizeName($filename, 220);
        $target = $rootPath . DIRECTORY_SEPARATOR . $showFolder . DIRECTORY_SEPARATOR . $seasonFolder . DIRECTORY_SEPARATOR . $filename;
        $sidecars = $this->findSidecars((string) $entry['path'], $target, false);

        return [
            'status' => 'ok',
            'reason' => null,
            'target' => $target,
            'sidecars' => $sidecars,
            'show_folder' => $showFolder,
            'title' => $title,
            'year' => $year,
            'root_path' => $rootPath,
        ];
    }

    private function details(string $mediaType, int $tmdbId): array
    {
        $type = $mediaType === 'movie' ? 'movie' : 'series';
        $cacheKey = $type . '|' . $tmdbId;
        if (!isset($this->detailsCache[$cacheKey])) {
            $this->detailsCache[$cacheKey] = $this->tmdb->details($type, $tmdbId) ?? [];
        }

        return $this->detailsCache[$cacheKey];
    }

    private function findSidecars(string $oldPath, string $newPath, bool $movie): array
    {
        $oldDir = dirname($oldPath);
        $newDir = dirname($newPath);
        $oldStem = pathinfo($oldPath, PATHINFO_FILENAME);
        $newStem = pathinfo($newPath, PATHINFO_FILENAME);
        $sidecars = [];

        $files = $this->dirCache[$oldDir] ??= (@scandir($oldDir) ?: []);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $oldSidecar = $oldDir . DIRECTORY_SEPARATOR . $file;
            if (!is_file($oldSidecar) || $oldSidecar === $oldPath) {
                continue;
            }
            $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
                continue;
            }
            $stem = pathinfo($file, PATHINFO_FILENAME);
            if ($stem !== $oldStem && !str_starts_with($file, $oldStem . '.') && !str_starts_with($file, $oldStem . '-') && !str_starts_with($file, $oldStem . '_')) {
                if (!($movie && $file === 'movie.nfo')) {
                    continue;
                }
                $newSidecar = $newDir . DIRECTORY_SEPARATOR . 'movie.nfo';
                if ($newSidecar !== $oldSidecar) {
                    $sidecars[$oldSidecar] = $newSidecar;
                }
                continue;
            }

            $suffix = substr($file, strlen($oldStem));
            $newSidecar = $newDir . DIRECTORY_SEPARATOR . $newStem . $suffix;
            if ($newSidecar !== $oldSidecar) {
                $sidecars[$oldSidecar] = $newSidecar;
            }
        }

        return $sidecars;
    }

    private function moveFile(string $oldPath, string $newPath): void
    {
        if (@rename($oldPath, $newPath)) {
            return;
        }

        if (!@copy($oldPath, $newPath)) {
            throw new \RuntimeException('Could not move ' . basename($oldPath) . '.');
        }
        @touch($newPath, @filemtime($oldPath) ?: time());
        if (!@unlink($oldPath)) {
            throw new \RuntimeException('Copied but could not remove ' . basename($oldPath) . '.');
        }
    }

    private function skipItem(array $entry, string $reason, ?string $target = null, ?string $rootPath = null, string $status = 'skip'): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'target' => $target,
            'sidecars' => [],
            'show_folder' => null,
            'title' => $entry['title'] ?? null,
            'year' => $entry['year'] ?? null,
            'entry' => $entry,
            'root_path' => $rootPath ?? '',
        ];
    }

    private function sanitizeName(string $name, int $maxLength): string
    {
        $name = preg_replace('/[\/\\\\]+/u', ' - ', $name) ?? $name;
        $name = preg_replace('/[<>:"|?*\x00-\x1F]+/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = trim($name, " .\t\n\r\0\x0B");
        if (mb_strlen($name, 'UTF-8') > $maxLength) {
            $name = rtrim(mb_substr($name, 0, $maxLength, 'UTF-8'), " .");
        }

        return $name;
    }

    private function relativeToRoot(string $path, string $rootPath): string
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedRoot = rtrim(str_replace('\\', '/', $rootPath), '/') . '/';
        if (str_starts_with($normalizedPath, $normalizedRoot)) {
            return substr($normalizedPath, strlen($normalizedRoot));
        }

        return basename($normalizedPath);
    }

    private function acquireLock()
    {
        $lockFile = $this->store->getStorageDir() . DIRECTORY_SEPARATOR . 'automation_rename.lock';
        if (!is_dir(dirname($lockFile))) {
            mkdir(dirname($lockFile), 0777, true);
        }
        $handle = @fopen($lockFile, 'c+');
        if (!$handle) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
