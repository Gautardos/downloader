<?php

namespace App\Controller;

use App\Service\MusicLibraryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MusicLibraryController extends AbstractController
{
    #[Route('/music-library', name: 'music_library', methods: ['GET'])]
    public function index(Request $request, MusicLibraryService $library): Response
    {
        $roots = $library->getRoots();
        if ($roots === []) {
            return $this->render('dashboard/locked.html.twig', [
                'title' => 'Music Configuration Required',
                'message' => 'The music library requires a valid and accessible library or download path.',
                'requirements' => [
                    'Library Target Path or Music Download Path (Settings > Music Settings)',
                ],
            ]);
        }

        $requestedRoot = $request->query->get('root');
        $defaultRoot = is_string($requestedRoot) && isset($roots[$requestedRoot])
            ? $requestedRoot
            : (isset($roots['library']) ? 'library' : 'downloads');

        return $this->render('music_library/index.html.twig', [
            'roots' => array_values(array_map(
                static fn (array $root): array => ['key' => $root['key'], 'label' => $root['label']],
                $roots
            )),
            'default_root' => $defaultRoot,
        ]);
    }

    #[Route('/music-library/folder', name: 'music_library_folder', methods: ['GET'])]
    public function folder(Request $request, MusicLibraryService $library): Response
    {
        $path = $request->query->get('path', '');
        $root = $request->query->get('root', '');
        if (!is_string($path) || !is_string($root)) {
            return $this->json(['success' => false, 'message' => 'Invalid folder path.'], 400);
        }

        $rootKey = $this->resolveRootKey($root, $library);
        if ($rootKey === null) {
            return $this->json(['success' => false, 'message' => 'Unknown library root.'], 400);
        }

        $result = $library->listFolder($rootKey, $path);
        $response = $this->json($result, ($result['success'] ?? false) ? 200 : 400);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }

    #[Route('/music-library/tags', name: 'music_library_tags', methods: ['POST'])]
    public function tags(Request $request, MusicLibraryService $library): Response
    {
        $data = $this->jsonPayload($request);
        $path = is_string($data['path'] ?? null) ? $data['path'] : '';
        $rootKey = $this->resolveRootKey(is_string($data['root'] ?? null) ? $data['root'] : '', $library);
        if ($rootKey === null) {
            return $this->json(['success' => false, 'message' => 'Unknown library root.'], 400);
        }

        $result = $library->getTags($rootKey, $path);

        return $this->json($result, ($result['success'] ?? false) ? 200 : 400);
    }

    #[Route('/music-library/update-tags', name: 'music_library_update_tags', methods: ['POST'])]
    public function updateTags(Request $request, MusicLibraryService $library): Response
    {
        $data = $this->jsonPayload($request);
        $paths = is_array($data['paths'] ?? null) ? $data['paths'] : [];
        $tags = is_array($data['tags'] ?? null) ? $data['tags'] : [];
        $rootKey = $this->resolveRootKey(is_string($data['root'] ?? null) ? $data['root'] : '', $library);
        if ($rootKey === null) {
            return $this->json(['success' => false, 'message' => 'Unknown library root.'], 400);
        }

        $result = $library->updateTags($rootKey, $paths, $tags);
        $updated = (int) ($result['updated'] ?? 0);
        $status = ($result['success'] ?? false) || $updated > 0 ? 200 : 400;

        return $this->json($result, $status);
    }

    #[Route('/music-library/fetch-lyrics', name: 'music_library_fetch_lyrics', methods: ['POST'])]
    public function fetchLyrics(Request $request, MusicLibraryService $library): Response
    {
        $data = $this->jsonPayload($request);
        $path = is_string($data['path'] ?? null) ? $data['path'] : '';
        $forceUnsynced = (bool) ($data['force_unsynced'] ?? false);
        $rootKey = $this->resolveRootKey(is_string($data['root'] ?? null) ? $data['root'] : '', $library);
        if ($rootKey === null) {
            return $this->json(['success' => false, 'message' => 'Unknown library root.'], 400);
        }

        $result = $library->fetchLyrics($rootKey, $path, $forceUnsynced);

        return $this->json($result, ($result['success'] ?? false) ? 200 : 400);
    }

    #[Route('/music-library/lyrics-audit', name: 'music_library_lyrics_audit', methods: ['POST'])]
    public function lyricsAudit(Request $request, MusicLibraryService $library): Response
    {
        $data = $this->jsonPayload($request);
        $path = is_string($data['path'] ?? null) ? $data['path'] : '';
        $recursive = (bool) ($data['recursive'] ?? false);
        $rootKey = $this->resolveRootKey(is_string($data['root'] ?? null) ? $data['root'] : '', $library);
        if ($rootKey === null) {
            return $this->json(['success' => false, 'message' => 'Unknown library root.'], 400);
        }

        $result = $library->auditLyrics($rootKey, $path, $recursive);

        return $this->json($result, ($result['success'] ?? false) ? 200 : 400);
    }

    private function resolveRootKey(string $requested, MusicLibraryService $library): ?string
    {
        $roots = $library->getRoots();
        if ($roots === []) {
            return null;
        }
        if ($requested !== '') {
            return isset($roots[$requested]) ? $requested : null;
        }

        return isset($roots['library']) ? 'library' : 'downloads';
    }

    private function jsonPayload(Request $request): array
    {
        $data = json_decode((string) $request->getContent(), true);

        return is_array($data) ? $data : [];
    }
}
