<?php

namespace App\Service\Statistics;

interface StorageUsageProviderInterface
{
    public function getUsage(string $path): ?array;
}
