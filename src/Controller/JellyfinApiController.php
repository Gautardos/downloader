<?php

namespace App\Controller;

use App\Service\Jellyfin\JellyfinAuthService;
use App\Service\Jellyfin\JellyfinLibraryService;
use App\Service\Jellyfin\JellyfinMediaService;
use App\Service\Jellyfin\JellyfinStateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/jellyfin')]
class JellyfinApiController extends AbstractController
{
    #[Route('/System/Info/Public', name: 'jellyfin_info_public', methods: ['GET'])]
    public function infoPublic(JellyfinAuthService $auth): JsonResponse
    {
        return $this->json($auth->publicInfo());
    }

    #[Route('/System/Info', name: 'jellyfin_info', methods: ['GET'])]
    public function info(JellyfinAuthService $auth): JsonResponse
    {
        return $this->json($auth->publicInfo());
    }

    #[Route('/Users/AuthenticateByName', name: 'jellyfin_authenticate', methods: ['POST'])]
    public function authenticate(Request $request, JellyfinAuthService $auth): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            $data = [];
        }

        $username = (string) ($data['Username'] ?? $data['username'] ?? '');
        $password = (string) ($data['Pw'] ?? $data['Password'] ?? $data['password'] ?? '');
        $result = $auth->authenticate($username, $password);
        if ($result === null) {
            return $this->json(['error' => 'Invalid username or password.'], 401);
        }

        return $this->json($result);
    }

    #[Route('/Users/{userId}', name: 'jellyfin_user', methods: ['GET'])]
    public function user(string $userId, JellyfinAuthService $auth): JsonResponse
    {
        if ($userId !== $auth->userId()) {
            return $this->json(['error' => 'User not found.'], 404);
        }

        return $this->json([
            'Id' => $auth->userId(),
            'Name' => $auth->userName(),
            'ServerId' => $auth->serverId(),
            'HasPassword' => true,
            'HasConfiguredPassword' => true,
        ]);
    }

    #[Route('/Sessions/Logout', name: 'jellyfin_logout', methods: ['POST'])]
    public function logout(Request $request, JellyfinAuthService $auth): Response
    {
        $auth->logout($auth->tokenFromRequest($request));

        return new Response('', 204);
    }

    #[Route('/UserViews', name: 'jellyfin_user_views', methods: ['GET'])]
    public function userViews(JellyfinLibraryService $library): JsonResponse
    {
        return $this->json($library->views());
    }

    #[Route('/Items', name: 'jellyfin_items', methods: ['GET'])]
    public function items(Request $request, JellyfinLibraryService $library, JellyfinAuthService $auth): JsonResponse
    {
        return $this->json($library->items($request->query->all(), $auth->userId()));
    }

    #[Route('/Items/{itemId}', name: 'jellyfin_item', methods: ['GET'])]
    public function item(
        string $itemId,
        Request $request,
        JellyfinLibraryService $library,
        JellyfinAuthService $auth
    ): JsonResponse {
        $fields = (string) $request->query->get('fields', '');
        $includeImages = $this->includeImages($request);
        $includeMetadata = $this->includeMetadata($request, $includeImages);
        if (str_contains($fields, 'MediaSources') && !$this->fieldsWantMetadata($request)) {
            $includeImages = false;
            $includeMetadata = false;
        }
        $item = $library->item(
            $itemId,
            $auth->userId(),
            str_contains($fields, 'MediaSources'),
            $this->includeUserData($request),
            $includeImages,
            $includeMetadata
        );

        if ($item === null) {
            return $this->json(['error' => 'Item not found.'], 404);
        }

        return $this->json($item);
    }

    #[Route('/Shows/{seriesId}/Episodes', name: 'jellyfin_episodes', methods: ['GET'])]
    public function episodes(
        string $seriesId,
        Request $request,
        JellyfinLibraryService $library,
        JellyfinAuthService $auth
    ): JsonResponse {
        $season = $request->query->has('season') ? max(0, (int) $request->query->get('season')) : null;
        $includeImages = $this->includeImages($request);
        $result = $library->episodes(
            $seriesId,
            $auth->userId(),
            $season,
            $this->includeUserData($request),
            $includeImages,
            $this->includeMetadata($request, $includeImages)
        );

        if ($result === null) {
            return $this->json(['error' => 'Series not found.'], 404);
        }

        return $this->json($result);
    }

    #[Route('/UserItems/Resume', name: 'jellyfin_resume', methods: ['GET'])]
    public function resume(Request $request, JellyfinLibraryService $library, JellyfinAuthService $auth): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query->get('limit', 50)));

        return $this->json($library->resume($auth->userId(), $limit));
    }

    #[Route('/UserPlayedItems/{itemId}', name: 'jellyfin_played', methods: ['POST', 'DELETE'])]
    public function played(
        string $itemId,
        Request $request,
        JellyfinLibraryService $library,
        JellyfinAuthService $auth
    ): Response {
        if (!$library->setPlayed($auth->userId(), $itemId, $request->isMethod('POST'))) {
            return $this->json(['error' => 'Item not found.'], 404);
        }

        return new Response('', 204);
    }

    #[Route('/Items/{itemId}/PlaybackInfo', name: 'jellyfin_playback_info', methods: ['POST'])]
    public function playbackInfo(string $itemId, JellyfinLibraryService $library): JsonResponse
    {
        $info = $library->playbackInfo($itemId);
        if ($info === null) {
            return $this->json(['error' => 'Item not found.'], 404);
        }

        return $this->json($info);
    }

    #[Route('/Videos/{itemId}/stream', name: 'jellyfin_stream', methods: ['GET', 'HEAD'])]
    public function streamVideo(
        string $itemId,
        Request $request,
        JellyfinLibraryService $library,
        JellyfinMediaService $media
    ): Response {
        $data = $library->streamEntries($itemId, $request->query->get('mediaSourceId'));
        if ($data === null) {
            return $this->json(['error' => 'Media not found.'], 404);
        }

        $response = $media->streamResponse($data['entry']);

        return $response ?? $this->json(['error' => 'Media file not found.'], 404);
    }

    #[Route('/Videos/{itemId}/{mediaSourceId}/Subtitles/{index}/Stream.{format}', name: 'jellyfin_subtitle', methods: ['GET'])]
    public function subtitle(
        string $itemId,
        string $mediaSourceId,
        int $index,
        JellyfinLibraryService $library,
        JellyfinMediaService $media
    ): Response {
        $data = $library->streamEntries($itemId, $mediaSourceId);
        if ($data === null) {
            return $this->json(['error' => 'Media not found.'], 404);
        }

        $response = $media->subtitleResponse($data['entry'], $index);

        return $response ?? $this->json(['error' => 'Subtitle not found.'], 404);
    }

    #[Route('/Items/{itemId}/Images/{imageType}/{index?}', name: 'jellyfin_image', methods: ['GET'])]
    public function image(
        string $itemId,
        string $imageType,
        ?int $index,
        JellyfinLibraryService $library
    ): Response {
        $url = $library->imageUrl($itemId, $imageType, $index);
        if ($url === null) {
            return $this->json(['error' => 'Image not found.'], 404);
        }

        $response = new RedirectResponse($url, 302);
        $response->headers->set('Cache-Control', 'public, max-age=86400');

        return $response;
    }

    #[Route('/Genres', name: 'jellyfin_genres', methods: ['GET'])]
    public function genres(): JsonResponse
    {
        return $this->json(['Items' => [], 'TotalRecordCount' => 0]);
    }

    #[Route('/Sessions/Playing', name: 'jellyfin_playing_start', methods: ['POST'])]
    public function playingStart(Request $request, JellyfinStateService $state, JellyfinAuthService $auth): Response
    {
        $this->recordProgress($request, $state, $auth);

        return new Response('', 204);
    }

    #[Route('/Sessions/Playing/Progress', name: 'jellyfin_playing_progress', methods: ['POST'])]
    public function playingProgress(Request $request, JellyfinStateService $state, JellyfinAuthService $auth): Response
    {
        $this->recordProgress($request, $state, $auth);

        return new Response('', 204);
    }

    #[Route('/Sessions/Playing/Stopped', name: 'jellyfin_playing_stopped', methods: ['POST'])]
    public function playingStopped(Request $request, JellyfinStateService $state, JellyfinAuthService $auth): Response
    {
        $this->recordProgress($request, $state, $auth);

        return new Response('', 204);
    }

    private function recordProgress(Request $request, JellyfinStateService $state, JellyfinAuthService $auth): void
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return;
        }

        $itemId = trim((string) ($data['ItemId'] ?? ''));
        if ($itemId === '') {
            return;
        }

        $positionTicks = max(0, (int) ($data['PositionTicks'] ?? 0));
        $state->setProgress($auth->userId(), $itemId, $positionTicks);
    }

    private function includeUserData(Request $request): bool
    {
        $value = strtolower((string) $request->query->get('enableUserData', 'true'));

        return !in_array($value, ['false', '0', 'no'], true);
    }

    private function includeImages(Request $request): bool
    {
        $enabled = strtolower((string) $request->query->get('enableImages', 'true'));
        $limit = (int) $request->query->get('imageTypeLimit', 1);

        return !in_array($enabled, ['false', '0', 'no'], true) && $limit !== 0;
    }

    private function includeMetadata(Request $request, bool $includeImages): bool
    {
        return $includeImages || $this->fieldsWantMetadata($request);
    }

    private function fieldsWantMetadata(Request $request): bool
    {
        $fields = strtolower((string) $request->query->get('fields', ''));

        return str_contains($fields, 'overview')
            || str_contains($fields, 'genres')
            || str_contains($fields, 'people')
            || str_contains($fields, 'studios')
            || str_contains($fields, 'premieredate');
    }
}
