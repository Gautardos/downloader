<?php

namespace App\Service;

use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

class MusicLibraryService
{
    private const AUDIO_EXTENSIONS = ['mp3', 'flac', 'm4a', 'opus', 'ogg', 'wav'];
    private const EDITABLE_TAGS = ['title', 'artist', 'album', 'albumartist', 'year', 'genre', 'tracknumber', 'discnumber', 'comment', 'lyrics'];
    private const AUDIT_STORAGE_KEY = 'music_library_audits';
    private const MAX_UPDATE_PATHS = 500;

    private JsonStorage $storage;
    private KernelInterface $kernel;

    public function __construct(JsonStorage $storage, KernelInterface $kernel)
    {
        $this->storage = $storage;
        $this->kernel = $kernel;
    }

    public function getRoots(): array
    {
        $config = $this->storage->get('config', []);
        $definitions = [
            'downloads' => ['label' => 'Downloads', 'path' => $config['music_root_path'] ?? ''],
            'library' => ['label' => 'Library', 'path' => $config['music_library_path'] ?? ''],
        ];

        $roots = [];
        foreach ($definitions as $key => $definition) {
            $path = trim((string) $definition['path']);
            if ($path === '') {
                continue;
            }

            $real = realpath($path);
            if ($real !== false && is_dir($real) && is_readable($real)) {
                $roots[$key] = ['key' => $key, 'label' => $definition['label'], 'path' => $real];
            }
        }

        return $roots;
    }

    public function getRoot(?string $rootKey = null): ?string
    {
        $roots = $this->getRoots();
        if ($rootKey !== null) {
            return $roots[$rootKey]['path'] ?? null;
        }

        return $roots['library']['path'] ?? $roots['downloads']['path'] ?? null;
    }

    public function isAvailable(): bool
    {
        return $this->getRoots() !== [];
    }

    public function resolveDirectory(string $rootKey, string $relative): array
    {
        return $this->resolvePath($rootKey, $relative, true);
    }

    public function resolveFile(string $rootKey, string $relative): array
    {
        return $this->resolvePath($rootKey, $relative, false);
    }

    public function listFolder(string $rootKey, string $relative): array
    {
        $resolved = $this->resolveDirectory($rootKey, $relative);
        if ($resolved['error'] !== null) {
            return ['success' => false, 'message' => $resolved['error']];
        }

        $directory = $resolved['path'];
        $clean = $resolved['relative'];
        $entries = @scandir($directory);
        if ($entries === false) {
            return ['success' => false, 'message' => 'Could not read this folder.'];
        }

        $roots = $this->getRoots();
        $root = $roots[$rootKey] ?? null;
        $audits = $this->readAudits();
        $subfolders = [];
        $fileNames = [];

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $subRelative = $clean === '' ? $name : $clean . '/' . $name;
                $subfolders[] = [
                    'label' => $name,
                    'path' => $subRelative,
                    'root' => $rootKey,
                    'track_count' => $this->countAudioFiles($path),
                    'has_children' => $this->hasSubdirectories($path),
                    'audit' => $audits['scopes'][$this->auditScopeKey($rootKey, $subRelative)] ?? null,
                ];
                continue;
            }

            if (is_file($path) && $this->isAudioFile($path)) {
                $fileNames[] = $name;
            }
        }

        usort($subfolders, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        $tagMap = $this->readFolderTags($directory);
        $files = [];
        foreach ($fileNames as $name) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            $fileRelative = $clean === '' ? $name : $clean . '/' . $name;
            $real = realpath($path) ?: $path;
            $tag = $tagMap[$real] ?? [];
            $files[] = $this->buildFileRow($rootKey, $path, $fileRelative, $tag, $audits['files'][$this->auditFileKey($rootKey, $fileRelative)] ?? null);
        }

        usort($files, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [
            'success' => true,
            'folder' => [
                'root' => $rootKey,
                'root_label' => $root['label'] ?? $rootKey,
                'path' => $clean,
                'label' => $clean === '' ? ($root['label'] ?? $rootKey) : basename($clean),
                'track_count' => count($files),
                'subfolder_count' => count($subfolders),
                'audit' => $audits['scopes'][$this->auditScopeKey($rootKey, $clean)] ?? null,
            ],
            'breadcrumb' => $this->buildBreadcrumb($rootKey, $clean),
            'subfolders' => $subfolders,
            'files' => $files,
        ];
    }

    public function getTags(string $rootKey, string $relative): array
    {
        $resolved = $this->resolveFile($rootKey, $relative);
        if ($resolved['error'] !== null) {
            return ['success' => false, 'message' => $resolved['error']];
        }

        $process = $this->runPythonScript('music_downloader.py', ['--tags', $resolved['path']], 60);
        if (!$process['success']) {
            return ['success' => false, 'message' => $process['error'] ?: 'Could not read file tags.'];
        }

        $data = json_decode($process['output'], true);
        if (!is_array($data)) {
            return ['success' => false, 'message' => 'Invalid response while reading tags.'];
        }
        if (isset($data['error'])) {
            return ['success' => false, 'message' => (string) $data['error']];
        }

        return ['success' => true, 'tags' => $this->normalizeTags($data)];
    }

    public function updateTags(string $rootKey, array $paths, array $tags): array
    {
        $paths = array_values(array_filter(array_map(
            static fn ($path): string => is_string($path) ? trim($path) : '',
            $paths
        )));
        if ($paths === []) {
            return ['success' => false, 'message' => 'No track selected.'];
        }
        if (count($paths) > self::MAX_UPDATE_PATHS) {
            return ['success' => false, 'message' => 'Too many tracks selected. Update them in smaller batches.'];
        }

        $allowed = array_flip(self::EDITABLE_TAGS);
        $cleanTags = [];
        foreach ($tags as $name => $value) {
            if (!is_string($name) || !isset($allowed[$name]) || !is_scalar($value)) {
                continue;
            }
            $cleanTags[$name] = (string) $value;
        }
        if ($cleanTags === []) {
            return ['success' => false, 'message' => 'No metadata field to update.'];
        }
        if (count($paths) > 1 && array_key_exists('lyrics', $cleanTags)) {
            return ['success' => false, 'message' => 'Lyrics can only be edited for one track at a time.'];
        }

        $results = [];
        $updated = 0;
        $updatedPaths = [];
        foreach ($paths as $path) {
            $resolved = $this->resolveFile($rootKey, $path);
            if ($resolved['error'] !== null) {
                $results[] = ['path' => $path, 'success' => false, 'message' => $resolved['error']];
                continue;
            }

            $args = ['--update-tags', $resolved['path']];
            foreach ($cleanTags as $name => $value) {
                $args[] = '--' . $name;
                $args[] = $value;
            }

            $process = $this->runPythonScript('music_downloader.py', $args, 120);
            if (!$process['success']) {
                $results[] = ['path' => $path, 'success' => false, 'message' => $process['error'] ?: 'Could not update tags.'];
                continue;
            }

            $data = json_decode($process['output'], true);
            if (!is_array($data) || isset($data['error']) || (isset($data['success']) && !$data['success'])) {
                $message = is_array($data) ? (string) ($data['error'] ?? $data['message'] ?? 'Could not update tags.') : 'Invalid response while updating tags.';
                $results[] = ['path' => $path, 'success' => false, 'message' => $message];
                continue;
            }

            $updated++;
            $updatedPaths[] = $resolved['relative'];
            $results[] = ['path' => $path, 'success' => true, 'message' => 'Updated'];
        }

        if ($updatedPaths !== []) {
            $this->invalidateFileAudits($rootKey, $updatedPaths);
        }

        return [
            'success' => $updated === count($paths),
            'updated' => $updated,
            'failed' => count($paths) - $updated,
            'message' => $updated === 0 ? 'No track could be updated.' : null,
            'results' => $results,
        ];
    }

    public function fetchLyrics(string $rootKey, string $relative, bool $forceUnsynced = false): array
    {
        $resolved = $this->resolveFile($rootKey, $relative);
        if ($resolved['error'] !== null) {
            return ['success' => false, 'message' => $resolved['error']];
        }

        $args = [
            $resolved['path'],
            '--force-save',
            '--add-unsync',
            '--json',
        ];
        if ($forceUnsynced) {
            $args[] = '--force-dl-unsync';
        }

        $process = $this->runPythonScript('lyrics_fetcher.py', $args, 300);
        if (!$process['success']) {
            return ['success' => false, 'message' => $process['error'] ?: 'Lyrics fetch failed.'];
        }

        $data = json_decode($process['output'], true);
        if (!is_array($data)) {
            return ['success' => false, 'message' => 'Invalid response from the lyrics fetcher.'];
        }
        if (isset($data['error'])) {
            return ['success' => false, 'message' => (string) $data['error']];
        }

        $result = isset($data[0]) && is_array($data[0]) ? $data[0] : $data;
        $status = is_array($result) ? (string) ($result['status'] ?? '') : '';
        if (in_array($status, ['found', 'skipped'], true)) {
            $this->invalidateFileAudits($rootKey, [$resolved['relative']]);
        }

        return [
            'success' => true,
            'status' => $status !== '' ? $status : 'unknown',
            'result' => $result,
            'results' => $data,
        ];
    }

    public function auditLyrics(string $rootKey, string $relative, bool $recursive): array
    {
        $root = $this->getRoot($rootKey);
        if ($root === null) {
            return ['success' => false, 'message' => 'Music library path is not available.'];
        }

        $resolved = $this->resolveDirectory($rootKey, $relative);
        if ($resolved['error'] !== null) {
            return ['success' => false, 'message' => $resolved['error']];
        }

        $args = ['--root', $root, '--path', $resolved['path']];
        if ($recursive) {
            $args[] = '--recursive';
        }

        $process = $this->runPythonScript('lyrics_audit.py', $args, 600);
        if (!$process['success']) {
            return ['success' => false, 'message' => $process['error'] ?: 'Lyrics audit failed.'];
        }

        $data = json_decode($process['output'], true);
        if (!is_array($data) || ($data['success'] ?? false) !== true) {
            $message = is_array($data) ? (string) ($data['message'] ?? 'Lyrics audit failed.') : 'Invalid response from the lyrics audit.';

            return ['success' => false, 'message' => $message];
        }

        $roots = $this->getRoots();
        $data['root'] = $rootKey;
        $data['root_label'] = $roots[$rootKey]['label'] ?? ucfirst($rootKey);
        $this->storeAudit($rootKey, $resolved['relative'], $data);

        return $data;
    }

    public function getCachedAuditForFolder(string $rootKey, string $relative): ?array
    {
        $audits = $this->readAudits();

        return $audits['scopes'][$this->auditScopeKey($rootKey, $relative)] ?? null;
    }

    private function resolvePath(string $rootKey, string $relative, bool $directory): array
    {
        $roots = $this->getRoots();
        $root = $roots[$rootKey]['path'] ?? null;
        if ($root === null) {
            return ['path' => null, 'relative' => null, 'error' => 'This library root is not available.'];
        }

        $relative = str_replace('\\', '/', trim($relative));
        if (str_contains($relative, "\0")) {
            return ['path' => null, 'relative' => null, 'error' => 'Invalid path.'];
        }

        $segments = [];
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return ['path' => null, 'relative' => null, 'error' => 'Invalid path.'];
            }
            $segments[] = $segment;
        }

        $clean = implode('/', $segments);
        $candidate = $root . ($clean === '' ? '' : DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean));
        $real = realpath($candidate);
        if ($real === false) {
            return ['path' => null, 'relative' => null, 'error' => $directory ? 'Folder not found.' : 'File not found.'];
        }

        $rootPrefix = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        if ($real !== $root && !str_starts_with($real, $rootPrefix)) {
            return ['path' => null, 'relative' => null, 'error' => 'Invalid path.'];
        }

        if ($directory && !is_dir($real)) {
            return ['path' => null, 'relative' => null, 'error' => 'Folder not found.'];
        }
        if (!$directory && (!is_file($real) || !$this->isAudioFile($real))) {
            return ['path' => null, 'relative' => null, 'error' => 'Unsupported audio file.'];
        }

        if ($clean !== '') {
            $relativeReal = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean));
            if ($relativeReal !== $real) {
                $clean = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($real, strlen($root)), '/\\'));
            }
        }

        return ['path' => $real, 'relative' => $clean, 'error' => null];
    }

    private function isAudioFile(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::AUDIO_EXTENSIONS, true);
    }

    private function countAudioFiles(string $directory): int
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            return 0;
        }

        $count = 0;
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_link($path)) {
                continue;
            }
            if (is_file($path) && $this->isAudioFile($path)) {
                $count++;
            }
        }

        return $count;
    }

    private function hasSubdirectories(string $directory): bool
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                return true;
            }
        }

        return false;
    }

    private function readFolderTags(string $directory): array
    {
        $process = $this->runPythonScript('music_downloader.py', ['--verify', $directory], 180);
        if (!$process['success']) {
            return [];
        }

        $data = json_decode($process['output'], true);
        if (!is_array($data)) {
            return [];
        }

        $map = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $fullPath = (string) ($item['full_path'] ?? '');
            if ($fullPath === '') {
                continue;
            }
            $real = realpath($fullPath) ?: $fullPath;
            $map[$real] = $item;
        }

        return $map;
    }

    private function buildFileRow(string $rootKey, string $path, string $relative, array $tag, ?array $audit): array
    {
        return [
            'name' => basename($path),
            'root' => $rootKey,
            'path' => $relative,
            'absolute_path' => $path,
            'size' => is_file($path) ? (int) filesize($path) : 0,
            'mtime' => is_file($path) ? (int) filemtime($path) : 0,
            'format' => strtoupper(pathinfo($path, PATHINFO_EXTENSION)),
            'title' => $this->tagValue($tag, 'song_name'),
            'artist' => $this->tagValue($tag, 'artist'),
            'album' => $this->tagValue($tag, 'album'),
            'albumartist' => $this->tagValue($tag, 'albumartist'),
            'year' => $this->tagValue($tag, 'year'),
            'genre' => $this->tagValue($tag, 'genre'),
            'tracknumber' => $this->tagValue($tag, 'tracknumber'),
            'discnumber' => $this->tagValue($tag, 'discnumber'),
            'comment' => $this->tagValue($tag, 'comment'),
            'has_lyrics' => (bool) ($tag['lyrics'] ?? false),
            'lyrics_synced' => (bool) ($tag['lyrics_synced'] ?? false),
            'audit' => $audit,
        ];
    }

    private function tagValue(array $tag, string $key): string
    {
        if (!array_key_exists($key, $tag)) {
            return '';
        }

        $value = $tag[$key];
        if ($value === null) {
            return '';
        }
        $value = trim((string) $value);

        return in_array(strtolower($value), ['none', '[]', '[none]'], true) ? '' : $value;
    }

    private function normalizeTags(array $tags): array
    {
        $normalized = [];
        foreach (self::EDITABLE_TAGS as $key) {
            $value = $tags[$key] ?? '';
            if ($value === null) {
                $value = '';
            }
            $value = (string) $value;
            if (in_array(strtolower(trim($value)), ['none', '[]', '[none]'], true)) {
                $value = '';
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function buildBreadcrumb(string $rootKey, string $relative): array
    {
        $roots = $this->getRoots();
        $items = [[
            'root' => $rootKey,
            'label' => $roots[$rootKey]['label'] ?? ucfirst($rootKey),
            'path' => '',
        ]];
        if ($relative === '') {
            return $items;
        }

        $path = '';
        foreach (explode('/', $relative) as $segment) {
            $path = $path === '' ? $segment : $path . '/' . $segment;
            $items[] = ['root' => $rootKey, 'label' => $segment, 'path' => $path];
        }

        return $items;
    }

    private function normalizeRelative(string $relative): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', trim($relative))) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return '';
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function readAudits(): array
    {
        $audits = $this->storage->get(self::AUDIT_STORAGE_KEY, []);

        return is_array($audits) ? $audits : [];
    }

    private function auditScopeKey(string $rootKey, string $relative): string
    {
        return $rootKey . ':' . $this->normalizeRelative($relative);
    }

    private function auditFileKey(string $rootKey, string $relative): string
    {
        return $rootKey . ':' . $this->normalizeRelative($relative);
    }

    private function storeAudit(string $rootKey, string $relative, array $audit): void
    {
        $audits = $this->readAudits();
        $scopes = is_array($audits['scopes'] ?? null) ? $audits['scopes'] : [];
        $files = is_array($audits['files'] ?? null) ? $audits['files'] : [];
        $now = time();
        $scope = $this->normalizeRelative((string) ($audit['scope'] ?? $relative));
        $recursive = (bool) ($audit['recursive'] ?? false);

        foreach ($scopes as $key => $data) {
            if (!is_array($data) || (string) ($data['root'] ?? '') !== $rootKey) {
                continue;
            }
            $path = (string) ($data['path'] ?? '');
            if ($path === $scope) {
                unset($scopes[$key]);
                continue;
            }
            if ($recursive && ($scope === '' || str_starts_with($path, $scope . '/'))) {
                unset($scopes[$key]);
            }
        }

        foreach ($files as $key => $entry) {
            if (!is_array($entry) || (string) ($entry['root'] ?? '') !== $rootKey) {
                continue;
            }
            $path = (string) ($entry['path'] ?? '');
            $directory = dirname($path);
            $directory = $directory === '.' ? '' : $directory;
            $inScope = $recursive
                ? ($scope === '' || str_starts_with($path, $scope . '/'))
                : ($directory === $scope);
            if ($inScope) {
                unset($files[$key]);
            }
        }

        $scopes[$this->auditScopeKey($rootKey, $scope)] = [
            'root' => $rootKey,
            'path' => $scope,
            'scanned_at' => $now,
            'recursive' => $recursive,
            'counts' => is_array($audit['counts'] ?? null) ? $audit['counts'] : [],
        ];

        $groups = [];
        foreach (($audit['files'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $fileRelative = $this->normalizeRelative((string) ($entry['path'] ?? ''));
            if ($fileRelative === '') {
                continue;
            }

            $files[$this->auditFileKey($rootKey, $fileRelative)] = [
                'root' => $rootKey,
                'path' => $fileRelative,
                'severity' => (string) ($entry['severity'] ?? 'ok'),
                'issues' => is_array($entry['issues'] ?? null) ? $entry['issues'] : [],
                'lyrics_synced' => (bool) ($entry['lyrics_synced'] ?? false),
                'scanned_at' => $now,
            ];

            $directory = dirname($fileRelative);
            $directory = $directory === '.' ? '' : $directory;
            $groups[$directory][] = $entry;
        }

        foreach ($groups as $directory => $entries) {
            if ($directory === $scope) {
                continue;
            }
            $scopes[$this->auditScopeKey($rootKey, $directory)] = [
                'root' => $rootKey,
                'path' => $directory,
                'scanned_at' => $now,
                'recursive' => false,
                'counts' => $this->aggregateAuditFiles($entries),
            ];
        }

        $this->recalculateAuditScopes($scopes, $files);

        $audits['scopes'] = $scopes;
        $audits['files'] = $files;
        $audits['last_root'] = $rootKey;
        $audits['last_scope'] = $scope;
        $audits['last_scanned_at'] = $now;
        $this->storage->set(self::AUDIT_STORAGE_KEY, $audits);
    }

    private function invalidateFileAudits(string $rootKey, array $paths): void
    {
        $audits = $this->readAudits();
        $scopes = is_array($audits['scopes'] ?? null) ? $audits['scopes'] : [];
        $files = is_array($audits['files'] ?? null) ? $audits['files'] : [];
        $changed = false;

        foreach ($paths as $path) {
            $relative = $this->normalizeRelative((string) $path);
            $key = $this->auditFileKey($rootKey, $relative);
            if ($relative !== '' && array_key_exists($key, $files)) {
                unset($files[$key]);
                $changed = true;
            }
        }

        if (!$changed) {
            return;
        }

        $this->recalculateAuditScopes($scopes, $files);

        $audits['scopes'] = $scopes;
        $audits['files'] = $files;
        $this->storage->set(self::AUDIT_STORAGE_KEY, $audits);
    }

    private function recalculateAuditScopes(array &$scopes, array $files): void
    {
        foreach ($scopes as &$data) {
            if (!is_array($data)) {
                continue;
            }

            $rootKey = (string) ($data['root'] ?? '');
            if ($rootKey === '') {
                continue;
            }
            $scope = (string) ($data['path'] ?? '');
            $recursive = !empty($data['recursive']);
            $scopePrefix = $scope === '' ? '' : $scope . '/';
            $entries = [];

            foreach ($files as $entry) {
                if (!is_array($entry) || (string) ($entry['root'] ?? '') !== $rootKey) {
                    continue;
                }
                $path = (string) ($entry['path'] ?? '');
                $directory = dirname($path);
                $directory = $directory === '.' ? '' : $directory;
                $inScope = $recursive
                    ? ($scope === '' || str_starts_with($path, $scopePrefix))
                    : ($directory === $scope);
                if ($inScope) {
                    $entries[] = $entry;
                }
            }

            $data['counts'] = $this->aggregateAuditFiles($entries);
        }
        unset($data);
    }

    private function aggregateAuditFiles(array $entries): array
    {
        $counts = [
            'tracks' => count($entries),
            'ok' => 0,
            'warning' => 0,
            'error' => 0,
            'missing' => 0,
            'flagged' => 0,
            'issue_count' => 0,
            'by_type' => [],
        ];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $severity = (string) ($entry['severity'] ?? 'ok');
            if (array_key_exists($severity, $counts) && in_array($severity, ['ok', 'warning', 'error'], true)) {
                $counts[$severity]++;
            }
            $issues = is_array($entry['issues'] ?? null) ? $entry['issues'] : [];
            $hasMissing = false;
            $types = [];
            foreach ($issues as $issue) {
                $type = is_array($issue) ? (string) ($issue['type'] ?? '') : '';
                if ($type === 'missing') {
                    $hasMissing = true;
                    continue;
                }
                if ($type !== '') {
                    $types[$type] = true;
                }
                $counts['issue_count']++;
            }
            if ($hasMissing) {
                $counts['missing']++;
            }
            if (in_array($severity, ['warning', 'error'], true)) {
                $counts['flagged']++;
            }
            foreach (array_keys($types) as $type) {
                $counts['by_type'][$type] = ($counts['by_type'][$type] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function runPythonScript(string $scriptName, array $args, int $timeout): array
    {
        $command = $this->buildPythonCommand($scriptName, $args);
        if ($command === null) {
            return ['success' => false, 'output' => '', 'error' => 'Python virtual environment not found.'];
        }

        try {
            $process = Process::fromShellCommandline($command);
            $process->setWorkingDirectory($this->kernel->getProjectDir());
            $process->setTimeout($timeout);
            $process->run();
        } catch (\Throwable $error) {
            return ['success' => false, 'output' => '', 'error' => $error->getMessage()];
        }

        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput());
            if ($error === '') {
                $error = trim($process->getOutput());
            }

            return ['success' => false, 'output' => $process->getOutput(), 'error' => $error];
        }

        return ['success' => true, 'output' => $process->getOutput(), 'error' => ''];
    }

    private function buildPythonCommand(string $scriptName, array $args): ?string
    {
        $config = $this->storage->get('config', []);
        $venvPath = (string) ($config['music_venv_path'] ?? '/opt/venv');
        if (!file_exists($venvPath)) {
            $venvPath = $this->kernel->getProjectDir() . DIRECTORY_SEPARATOR . $venvPath;
        }
        if (!file_exists($venvPath)) {
            return null;
        }

        $script = $this->kernel->getProjectDir() . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . $scriptName;
        if (!file_exists($script)) {
            return null;
        }

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $activate = $isWindows
            ? 'call ' . escapeshellarg($venvPath . '\\Scripts\\activate')
            : '. ' . escapeshellarg($venvPath . '/bin/activate');

        $parts = [$activate, '&&', 'python3', escapeshellarg($script)];
        foreach ($args as $arg) {
            $parts[] = escapeshellarg((string) $arg);
        }

        return '(' . implode(' ', $parts) . ')';
    }
}
