<?php

namespace App\Service\Automation;

use App\Service\MediaDownloadService;

class MediaDownloaderAdapter implements ReleaseDownloaderInterface
{
    private MediaDownloadService $downloadService;

    public function __construct(MediaDownloadService $downloadService)
    {
        $this->downloadService = $downloadService;
    }

    public function addStream(array $payload, ?callable $heartbeat = null): array
    {
        return $this->downloadService->addStream($payload, $heartbeat);
    }
}
