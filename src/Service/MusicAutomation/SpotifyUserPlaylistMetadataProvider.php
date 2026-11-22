<?php

namespace App\Service\MusicAutomation;

use App\Service\SpotifyUserService;

class SpotifyUserPlaylistMetadataProvider implements PlaylistMetadataProviderInterface
{
    private ?string $lastError = null;

    public function __construct(private SpotifyUserService $spotifyUser)
    {
    }

    public function getPlaylist(string $playlistId): ?array
    {
        $this->lastError = null;
        if (!PlaylistSearchService::isValidPlaylistId($playlistId)) {
            $this->lastError = 'Invalid Spotify playlist ID.';
            return null;
        }

        $metadata = $this->spotifyUser->getPlaylistMetadata($playlistId);
        if ($metadata === null) {
            $this->lastError = $this->spotifyUser->getLastError() ?: 'Unable to read the playlist with the Spotify user token.';
            return null;
        }

        return $metadata;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }
}
