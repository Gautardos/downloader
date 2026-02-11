<?php

namespace App\Service;

use Symfony\Component\HttpKernel\KernelInterface;

class JsonStorage
{
    private string $storageDir;

    public function __construct(KernelInterface $kernel)
    {
        $this->storageDir = $kernel->getProjectDir() . '/var/storage';
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0777, true);
        }
    }

    public function get(string $key, $default = [])
    {
        $file = $this->storageDir . '/' . $key . '.json';
        if (!file_exists($file)) {
            return $default;
        }

        $fp = fopen($file, 'r');
        if (!$fp)
            return $default;

        flock($fp, LOCK_SH);
        $content = '';
        while (!feof($fp)) {
            $content .= fread($fp, 8192);
        }
        flock($fp, LOCK_UN);
        fclose($fp);

        return json_decode($content, true) ?? $default;
    }

    public function set(string $key, mixed $data): void
    {
        $file = $this->storageDir . '/' . $key . '.json';
        $fp = fopen($file, 'c+'); // Open for reading/writing, creating if doesn't exist
        if (!$fp)
            return;

        flock($fp, LOCK_EX);
        ftruncate($fp, 0); // Clear file
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    public function getStorageDir(): string
    {
        return $this->storageDir;
    }

    public function isConfigured(): bool
    {
        $config = $this->get('config', []);
        return !empty($config);
    }

    public function hasAlldebrid(): bool
    {
        $config = $this->get('config', []);
        return !empty($config['api_key']);
    }

    public function hasMusicPath(): bool
    {
        $config = $this->get('config', []);
        $path = $config['music_root_path'] ?? '';
        return !empty($path) && is_dir($path);
    }

    public function hasDefaultTorrentPath(): bool
    {
        $config = $this->get('config', []);
        return !empty($config['default_path']);
    }

    public function hasGrok(): bool
    {
        $config = $this->get('config', []);
        return !empty($config['grok_api_key']);
    }

    public function hasSpotify(): bool
    {
        $config = $this->get('config', []);
        $creds = $config['music_creds'] ?? '';
        return !empty($config['spotify_client_id']) &&
            !empty($config['spotify_client_secret']) &&
            !empty($creds) &&
            file_exists($creds);
    }
}
