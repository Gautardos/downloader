<?php

namespace App\Service\Automation;

use App\Service\MediaAddonService;

class MediaStreamProviderAdapter implements ReleaseStreamProviderInterface
{
    private MediaAddonService $addonService;

    public function __construct(MediaAddonService $addonService)
    {
        $this->addonService = $addonService;
    }

    public function getStreams(string $type, string $videoId): array
    {
        return $this->addonService->getStreams($type, $videoId);
    }
}
