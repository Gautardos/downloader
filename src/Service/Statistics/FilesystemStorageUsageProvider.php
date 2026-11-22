<?php

namespace App\Service\Statistics;

class FilesystemStorageUsageProvider implements StorageUsageProviderInterface
{
    public function getUsage(string $path): ?array
    {
        $path = trim($path);
        if ($path === '' || !is_dir($path) || !is_readable($path)) {
            return null;
        }

        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if ($total === false || $free === false) {
            return null;
        }

        $total = max(0, (int) $total);
        $free = max(0, (int) $free);

        return [
            'total' => $total,
            'free' => $free,
            'used' => max(0, $total - $free),
        ];
    }
}
