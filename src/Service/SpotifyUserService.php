<?php

namespace App\Service;

use Symfony\Component\HttpKernel\KernelInterface;

class SpotifyUserService
{
    private const CACHE_TTL = 86400;
    private const PARTIAL_CACHE_TTL = 60;

    private ?string $lastError = null;

    public function __construct(
        private PythonExecutor $python,
        private KernelInterface $kernel
    ) {
    }

    public function listPlaylists(bool $force = false): array
    {
        $this->lastError = null;
        $cacheFile = $this->getCacheFile();
        $cache = $this->readCache($cacheFile);

        $cacheTtl = !empty($cache['partial']) ? self::PARTIAL_CACHE_TTL : self::CACHE_TTL;
        if (!$force && $cache !== null && (time() - (int) ($cache['updated_at'] ?? 0)) < $cacheTtl) {
            return [
                'success' => true,
                'playlists' => $cache['playlists'] ?? [],
                'total' => (int) ($cache['total'] ?? count($cache['playlists'] ?? [])),
                'cached' => true,
                'stale' => false,
                'partial' => !empty($cache['partial']),
                'warning' => $cache['warning'] ?? null,
                'updated_at' => (int) ($cache['updated_at'] ?? 0),
            ];
        }

        $result = $this->run(['--list-playlists'], 120);

        if (!empty($result['success'])) {
            $playlists = is_array($result['playlists'] ?? null) ? $result['playlists'] : [];
            $partial = !empty($result['partial']);
            $warning = $result['warning'] ?? null;
            $now = time();

            $this->writeCache($cacheFile, [
                'updated_at' => $now,
                'total' => (int) ($result['total'] ?? count($playlists)),
                'playlists' => $playlists,
                'partial' => $partial,
                'warning' => $warning,
            ]);

            return [
                'success' => true,
                'playlists' => $playlists,
                'total' => (int) ($result['total'] ?? count($playlists)),
                'cached' => false,
                'stale' => false,
                'partial' => $partial,
                'warning' => $warning,
                'updated_at' => $now,
            ];
        }

        if ($cache !== null) {
            return [
                'success' => true,
                'playlists' => $cache['playlists'] ?? [],
                'total' => (int) ($cache['total'] ?? count($cache['playlists'] ?? [])),
                'cached' => true,
                'stale' => true,
                'partial' => !empty($cache['partial']),
                'warning' => $cache['warning'] ?? null,
                'updated_at' => (int) ($cache['updated_at'] ?? 0),
                'message' => $this->lastError ?: 'Spotify is temporarily unavailable. Showing cached playlists.',
                'retry_after' => $result['retry_after'] ?? null,
            ];
        }

        return [
            'success' => false,
            'error' => $this->lastError ?: 'Unable to load Spotify playlists.',
            'status' => $result['status'] ?? null,
            'retry_after' => $result['retry_after'] ?? null,
        ];
    }

    public function getPlaylistMetadata(string $playlistId): ?array
    {
        $this->lastError = null;
        $playlistId = trim($playlistId);

        if (!preg_match('/^[A-Za-z0-9]{22}$/', $playlistId)) {
            $this->lastError = 'Invalid Spotify playlist ID.';
            return null;
        }

        $result = $this->run(['--playlist' => $playlistId], 120);

        if (empty($result['success']) || empty($result['playlist']) || !is_array($result['playlist'])) {
            return null;
        }

        $tracks = [];
        foreach (($result['playlist']['tracks'] ?? []) as $track) {
            if (!is_array($track) || empty($track['song_name'])) {
                continue;
            }
            $trackId = (string) ($track['id'] ?? '');
            $url = (string) ($track['url'] ?? '');
            if ($url === '' && $trackId !== '') {
                $url = 'https://open.spotify.com/track/' . $trackId;
            }
            $tracks[] = [
                'id' => $trackId,
                'url' => $url,
                'duration_ms' => (int) ($track['duration_ms'] ?? 0),
                'artist' => (string) ($track['artist'] ?? ''),
                'album' => (string) ($track['album'] ?? ''),
                'song_name' => (string) $track['song_name'],
            ];
        }

        return [
            'id' => (string) ($result['playlist']['id'] ?? $playlistId),
            'name' => (string) ($result['playlist']['name'] ?? 'Playlist'),
            'owner' => (string) ($result['playlist']['owner'] ?? ''),
            'url' => (string) ($result['playlist']['url'] ?? 'https://open.spotify.com/playlist/' . $playlistId),
            'snapshot_id' => isset($result['playlist']['snapshot_id']) && $result['playlist']['snapshot_id'] !== ''
                ? (string) $result['playlist']['snapshot_id']
                : null,
            'tracks' => $tracks,
        ];
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    private function run(array $args, int $timeout): array
    {
        try {
            $process = $this->python->execute('cli/spotify_user.py', $args, $timeout);
            $process->run();

            $output = trim($process->getOutput());
            $errorOutput = trim($process->getErrorOutput());

            if ($output === '') {
                $this->lastError = $errorOutput ?: 'Spotify helper returned no output.';
                return ['success' => false, 'error' => $this->lastError];
            }

            $data = json_decode($output, true);
            if (!is_array($data)) {
                $this->lastError = 'Spotify helper returned invalid JSON.';
                return ['success' => false, 'error' => $this->lastError];
            }

            if (empty($data['success'])) {
                $message = $data['error'] ?? null;
                if ($message === null || $message === '') {
                    $message = $errorOutput !== '' ? $errorOutput : 'Spotify helper failed.';
                }
                $this->lastError = (string) $message;
            }

            return $data;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function getCacheFile(): string
    {
        return $this->kernel->getProjectDir() . '/var/cache/spotify_playlists.json';
    }

    private function readCache(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !is_array($data['playlists'] ?? null)) {
            return null;
        }

        return $data;
    }

    private function writeCache(string $file, array $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $tmp = $file . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
        @rename($tmp, $file);
    }
}
