<?php

namespace App\Controller;

use App\Service\JsonStorage;
use App\Service\SpotifyService;
use App\Service\SpotifyUserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SpotifyController extends AbstractController
{
    #[Route('/music/spotify/playlists', name: 'spotify_playlists', methods: ['GET'])]
    public function playlists(Request $request, JsonStorage $storage, SpotifyUserService $spotifyUser): Response
    {
        if (!$storage->hasSpotify()) {
            return $this->json([
                'success' => false,
                'error' => 'Spotify credentials are missing. Check your settings.',
            ], 400);
        }

        $force = (string) $request->query->get('refresh', '0') === '1';
        $result = $spotifyUser->listPlaylists($force);

        return $this->json($result, !empty($result['success']) ? 200 : 502);
    }

    #[Route('/music/spotify/search', name: 'spotify_search', methods: ['GET'])]
    public function search(Request $request, JsonStorage $storage, SpotifyService $spotify): Response
    {
        if (!$storage->hasSpotify()) {
            return $this->json([
                'success' => false,
                'error' => 'Spotify credentials are missing. Check your settings.',
            ], 400);
        }

        $query = trim((string) $request->query->get('q', ''));
        $type = (string) $request->query->get('type', 'all');
        if (!in_array($type, ['all', 'track', 'album', 'playlist'], true)) {
            $type = 'all';
        }

        if (mb_strlen($query) < 2) {
            return $this->json(['success' => true, 'results' => []]);
        }

        $results = $spotify->search($query, $type, 15);
        if ($results === null) {
            return $this->json([
                'success' => false,
                'error' => $spotify->getLastError() ?: 'Spotify search failed.',
            ], 502);
        }

        return $this->json([
            'success' => true,
            'query' => $query,
            'type' => $type,
            'results' => $results,
        ]);
    }
}
