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
}
