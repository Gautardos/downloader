<?php

namespace App\Service\Automation;

interface ReleaseStreamProviderInterface
{
    public function getStreams(string $type, string $videoId): array;
}
