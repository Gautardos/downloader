<?php

namespace App\Service\MusicAutomation;

interface PlaylistMetadataProviderInterface
{
    public function getPlaylist(string $playlistId): ?array;

    public function getLastError(): ?string;
}
