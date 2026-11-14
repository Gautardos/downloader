<?php

namespace App\Service\Automation;

interface ReleaseDownloaderInterface
{
    public function addStream(array $payload, ?callable $heartbeat = null): array;
}
