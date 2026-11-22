<?php

namespace App\Service\Music;

class MusicDownloadCommandBuilder
{
    private const QUALITIES = ['auto', 'normal', 'high', 'very_high'];

    /**
     * @return array<string, string|int>
     */
    public function build(array $config): array
    {
        $quality = $config['music_quality'] ?? 'auto';
        if (!in_array($quality, self::QUALITIES, true)) {
            $quality = 'auto';
        }

        return [
            '--output' => $config['music_output'] ?? '{artist} - {album} - {song_name}.{ext}',
            '--download-format' => $config['music_format'] ?? 'mp3',
            '--root-path' => $config['music_root_path'] ?? '',
            '--credentials-location' => $config['music_creds'] ?? '',
            '--song-archive' => $config['music_archive'] ?? '',
            '--download-quality' => $quality,
            '--retry-attempts' => $config['music_retries'] ?? 3,
            '--skip-previously-downloaded' => ($config['music_skip_existing'] ?? false) ? 'True' : 'False',
            '--download-lyrics' => ($config['music_lyrics'] ?? true) ? 'True' : 'False',
            '--print-download-progress' => ($config['music_progress'] ?? false) ? 'True' : 'False',
            '--print-downloads' => ($config['music_print_downloads'] ?? true) ? 'True' : 'False',
            '--print-progress-info' => ($config['music_progress_info'] ?? false) ? 'True' : 'False',
            '--client-id' => $config['spotify_client_id'] ?? '',
            '--client-secret' => $config['spotify_client_secret'] ?? '',
        ];
    }
}
