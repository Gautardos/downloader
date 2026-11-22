<?php

namespace App\Service\MusicAutomation;

use App\Service\JsonStorage;
use App\Service\LogRotator;
use App\Service\QueueManager;

class MusicQueueService
{
    public function __construct(
        private QueueManager $queueManager,
        private JsonStorage $storage
    ) {
    }

    public function queuedUrls(): array
    {
        $queue = $this->storage->get('server_queue', []);
        if (!is_array($queue)) {
            return [];
        }

        $urls = [];
        foreach ($queue as $item) {
            if (is_array($item) && !empty($item['url'])) {
                $urls[(string) $item['url']] = true;
            }
        }

        return array_keys($urls);
    }

    public function queueCount(): int
    {
        $queue = $this->storage->get('server_queue', []);

        return is_array($queue) ? count($queue) : 0;
    }

    public function trackUrl(array $track): ?string
    {
        $url = trim((string) ($track['url'] ?? ''));
        if ($url !== '') {
            $parts = parse_url($url);
            $host = strtolower((string) ($parts['host'] ?? ''));
            $path = (string) ($parts['path'] ?? '');
            if (!is_array($parts) || !in_array($host, ['open.spotify.com', 'www.open.spotify.com'], true)
                || !preg_match('#/track/[A-Za-z0-9]{22}/?$#', $path)) {
                $url = '';
            }
        }

        if ($url !== '') {
            return $url;
        }

        $id = trim((string) ($track['id'] ?? ''));
        if (preg_match('/^[A-Za-z0-9]{22}$/', $id)) {
            return 'https://open.spotify.com/track/' . $id;
        }

        return null;
    }

    public function enqueueTrack(array $track, array $rule, array $config, int $runId): ?array
    {
        $url = $this->trackUrl($track);
        if ($url === null || empty($config['music_root_path'])) {
            return null;
        }

        $downloadId = bin2hex(random_bytes(8));
        $artist = trim((string) ($track['artist'] ?? ''));
        $songName = trim((string) ($track['song_name'] ?? ''));
        $album = trim((string) ($track['album'] ?? ''));
        $display = $artist !== '' && $songName !== ''
            ? $artist . ' - ' . $songName
            : ($songName !== '' ? $songName : 'Spotify track');

        $item = [
            'url' => $url,
            'filename' => 'Spotify: ' . $display,
            'path' => (string) $config['music_root_path'],
            'download_id' => $downloadId,
            'date_added' => date('Y-m-d H:i:s'),
            'type' => 'music',
            'expected_tracks' => [[
                'artist' => $artist,
                'album' => $album,
                'song_name' => $songName,
            ]],
            'music_automation_rule_id' => (int) ($rule['id'] ?? 0),
            'music_automation_playlist_id' => (string) ($rule['playlist_id'] ?? ''),
            'music_automation_run_id' => $runId,
            'music_automation_track_id' => (string) ($track['id'] ?? ''),
        ];

        $this->queueManager->enqueue($item, 'music');
        $this->writeHistoryLog($downloadId, $track, $rule, $runId);

        return ['download_id' => $downloadId, 'item' => $item];
    }

    private function writeHistoryLog(string $downloadId, array $track, array $rule, int $runId): void
    {
        $logsDir = $this->storage->getStorageDir() . DIRECTORY_SEPARATOR . 'logs';
        $path = $logsDir . DIRECTORY_SEPARATOR . 'history_' . $downloadId . '.log';
        $lines = [
            '--- Music automation enqueue ---',
            'Rule: ' . (string) ($rule['name'] ?? 'Playlist') . ' (#' . (int) ($rule['id'] ?? 0) . ')',
            'Playlist: ' . (string) ($rule['playlist_url'] ?? '') . ' [' . (string) ($rule['playlist_id'] ?? '') . ']',
            'Run: #' . $runId,
            'Track: ' . (string) ($track['artist'] ?? '') . ' - ' . (string) ($track['song_name'] ?? ''),
            'Album: ' . (string) ($track['album'] ?? ''),
            'URL: ' . (string) ($this->trackUrl($track) ?? ''),
            str_repeat('-', 40),
            '',
        ];
        LogRotator::append($path, implode("\n", $lines));
    }
}
