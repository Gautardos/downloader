<?php

namespace App\Service;

final class LogRotator
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const BACKUPS = 5;

    public static function append(string $path, string $data): void
    {
        $directory = dirname($path);
        $previousUmask = umask(0027);

        try {
            if (!is_dir($directory)) {
                @mkdir($directory, 0777, true);
            }

            self::rotateIfOversized($path);

            @file_put_contents($path, $data, FILE_APPEND | LOCK_EX);
        } finally {
            umask($previousUmask);
        }
    }

    public static function rotateIfOversized(string $path): bool
    {
        clearstatcache(true, $path);
        if (!is_file($path) || filesize($path) < self::MAX_BYTES) {
            return false;
        }

        self::rotate($path);

        return true;
    }

    private static function rotate(string $path): void
    {
        for ($index = self::BACKUPS - 1; $index >= 1; $index--) {
            $source = $path . '.' . $index;
            if (is_file($source)) {
                @rename($source, $path . '.' . ($index + 1));
            }
        }

        @rename($path, $path . '.1');
    }
}
