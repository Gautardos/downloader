<?php

namespace App\Controller;

use App\Service\JsonStorage;
use App\Service\MediaAddonService;
use App\Service\MediaDownloadService;
use App\Service\NuvioCollectionService;
use App\Service\TmdbService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MediaController extends AbstractController
{
    #[Route('/media', name: 'media_index', methods: ['GET'])]
    public function index(JsonStorage $storage, TmdbService $tmdb, MediaAddonService $addons, NuvioCollectionService $nuvioCollections): Response
    {
        $config = $storage->get('config', []);
        $tmdbConfigured = $tmdb->isConfigured();

        return $this->render('media/index.html.twig', [
            'trending' => $tmdbConfigured ? $tmdb->trending() : [],
            'tmdb_configured' => $tmdbConfigured,
            'tmdb_api_key' => (string) ($config['tmdb_api_key'] ?? ''),
            'tmdb_language' => (string) ($config['tmdb_language'] ?? 'fr-FR'),
            'media_addons' => $addons->getAddons(),
            'default_path' => (string) ($config['default_path'] ?? '/nas/'),
            'recent_paths' => $storage->get('recent_paths', []),
            'nuvio_collections' => $nuvioCollections->getCollections(),
            'nuvio_meta' => $nuvioCollections->getMeta(),
        ]);
    }

    #[Route('/media/catalog/items', name: 'media_catalog_items', methods: ['POST'])]
    public function catalogItems(Request $request, MediaAddonService $addons): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'message' => 'Invalid request body.'], 400);
        }

        $addonId = trim((string) ($data['addon_id'] ?? ''));
        $type = trim((string) ($data['type'] ?? ''));
        $catalogId = trim((string) ($data['catalog_id'] ?? ''));
        $genre = isset($data['genre']) ? (string) $data['genre'] : null;
        $skip = max(0, (int) ($data['skip'] ?? 0));

        if ($addonId === '' || $type === '' || $catalogId === '') {
            return $this->json(['success' => false, 'message' => 'Missing addon, type or catalog ID.'], 400);
        }

        return $this->json($addons->getCatalogItems($addonId, $type, $catalogId, $genre, $skip));
    }

    #[Route('/media/collections', name: 'media_collections', methods: ['GET'])]
    public function collections(NuvioCollectionService $collections): Response
    {
        return $this->json([
            'success' => true,
            'collections' => $collections->getCollections(),
            'meta' => $collections->getMeta(),
        ]);
    }

    #[Route('/media/collections/import', name: 'media_collections_import', methods: ['POST'])]
    public function importCollections(Request $request, NuvioCollectionService $collections): Response
    {
        $json = null;
        $file = $request->files->get('file');
        if ($file) {
            $json = file_get_contents($file->getPathname());
        } else {
            $body = $request->getContent();
            $data = json_decode($body, true);
            if (is_array($data) && isset($data['json'])) {
                $json = (string) $data['json'];
            } elseif (is_array($data) && array_is_list($data)) {
                $json = $body;
            }
        }

        if (!is_string($json) || trim($json) === '') {
            return $this->json(['success' => false, 'message' => 'No Nuvio collections JSON provided.'], 400);
        }

        $result = $collections->importJson($json);
        return $this->json($result, !empty($result['success']) ? 200 : 422);
    }

    #[Route('/media/collections/delete', name: 'media_collections_delete', methods: ['POST'])]
    public function deleteCollection(Request $request, NuvioCollectionService $collections): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'message' => 'Invalid request body.'], 400);
        }

        $collectionId = trim((string) ($data['collection_id'] ?? ''));
        if ($collectionId === '') {
            return $this->json(['success' => false, 'message' => 'Missing collection ID.'], 400);
        }

        if (!$collections->deleteCollection($collectionId)) {
            return $this->json(['success' => false, 'message' => 'Collection not found.'], 404);
        }

        return $this->json(['success' => true, 'message' => 'Collection deleted.']);
    }

    #[Route('/media/collections/folder/delete', name: 'media_collections_folder_delete', methods: ['POST'])]
    public function deleteCollectionFolder(Request $request, NuvioCollectionService $collections): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'message' => 'Invalid request body.'], 400);
        }

        $collectionId = trim((string) ($data['collection_id'] ?? ''));
        $folderId = trim((string) ($data['folder_id'] ?? ''));
        if ($collectionId === '' || $folderId === '') {
            return $this->json(['success' => false, 'message' => 'Missing collection or folder ID.'], 400);
        }

        if (!$collections->deleteFolder($collectionId, $folderId)) {
            return $this->json(['success' => false, 'message' => 'Folder not found.'], 404);
        }

        return $this->json(['success' => true, 'message' => 'Folder deleted.']);
    }

    #[Route('/media/collections/clear', name: 'media_collections_clear', methods: ['POST'])]
    public function clearCollections(NuvioCollectionService $collections): Response
    {
        $collections->clear();
        return $this->json(['success' => true, 'message' => 'Nuvio collections cleared.']);
    }

    #[Route('/media/resolve-item', name: 'media_resolve_item', methods: ['POST'])]
    public function resolveItem(Request $request, TmdbService $tmdb): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'message' => 'Invalid request body.'], 400);
        }

        $tmdbId = (int) ($data['tmdb_id'] ?? 0);
        $type = (string) ($data['type'] ?? '');
        $imdbId = trim((string) ($data['imdb_id'] ?? ''));

        if ($tmdbId > 0) {
            $details = null;
            if (in_array($type, ['movie', 'series'], true)) {
                $details = $tmdb->details($type, $tmdbId);
            } else {
                $details = $tmdb->details('movie', $tmdbId) ?: $tmdb->details('series', $tmdbId);
            }
            if ($details) {
                return $this->json(['success' => true, 'details' => $details]);
            }
        }

        if ($imdbId !== '') {
            $details = $tmdb->findByImdb($imdbId);
            if ($details) {
                return $this->json(['success' => true, 'details' => $details]);
            }
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title !== '') {
            $typeFilter = in_array($type, ['movie', 'series'], true) ? $type : 'all';
            $results = $tmdb->search($title, $typeFilter);
            if (!empty($results[0]['id'])) {
                $first = $results[0];
                $details = $tmdb->details($first['type'], (int) $first['id']);
                if ($details) {
                    return $this->json(['success' => true, 'details' => $details]);
                }
            }
        }

        return $this->json(['success' => false, 'message' => 'Could not resolve this catalog item to TMDB.'], 422);
    }

    #[Route('/media/search', name: 'media_search', methods: ['GET'])]
    public function search(Request $request, TmdbService $tmdb): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $type = (string) $request->query->get('type', 'all');
        if (!in_array($type, ['all', 'movie', 'series'], true)) {
            $type = 'all';
        }

        return $this->json([
            'success' => true,
            'configured' => $tmdb->isConfigured(),
            'results' => $tmdb->search($query, $type),
        ]);
    }

    #[Route('/media/details/{type}/{id}', name: 'media_details', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function details(string $type, int $id, TmdbService $tmdb): Response
    {
        if (!in_array($type, ['movie', 'series'], true)) {
            return $this->json(['success' => false, 'message' => 'Unsupported media type.'], 400);
        }

        $details = $tmdb->details($type, $id);
        if (!$details) {
            return $this->json(['success' => false, 'message' => 'Could not load media details.'], 404);
        }

        return $this->json(['success' => true, 'details' => $details]);
    }

    #[Route('/media/season/{id}/{season}', name: 'media_season', methods: ['GET'], requirements: ['id' => '\d+', 'season' => '\d+'])]
    public function season(int $id, int $season, TmdbService $tmdb): Response
    {
        return $this->json([
            'success' => true,
            'episodes' => $tmdb->season($id, $season),
        ]);
    }

    #[Route('/media/streams', name: 'media_streams', methods: ['POST'])]
    public function streams(Request $request, TmdbService $tmdb, MediaAddonService $addons): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'message' => 'Invalid request body.'], 400);
        }

        $type = (string) ($data['type'] ?? '');
        $tmdbId = (int) ($data['tmdb_id'] ?? 0);
        if (!in_array($type, ['movie', 'series'], true) || $tmdbId <= 0) {
            return $this->json(['success' => false, 'message' => 'Missing media type or TMDB ID.'], 400);
        }

        $details = $tmdb->details($type, $tmdbId);
        if (!$details || empty($details['imdb_id'])) {
            return $this->json(['success' => false, 'message' => 'No IMDb ID available for this title.'], 422);
        }

        $videoId = (string) $details['imdb_id'];
        if ($type === 'series') {
            $season = (int) ($data['season'] ?? 0);
            $episode = (int) ($data['episode'] ?? 0);
            if ($season <= 0 || $episode <= 0) {
                return $this->json(['success' => false, 'message' => 'Season and episode are required.'], 400);
            }
            $videoId .= ':' . $season . ':' . $episode;
        }

        $result = $addons->getStreams($type, $videoId);

        return $this->json([
            'success' => (bool) ($result['success'] ?? false),
            'message' => $result['message'] ?? null,
            'imdb_id' => $details['imdb_id'],
            'streams' => $result['streams'] ?? [],
            'errors' => $result['errors'] ?? [],
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    #[Route('/media/debrid', name: 'media_debrid', methods: ['POST'])]
    public function debrid(Request $request, MediaDownloadService $downloads, JsonStorage $storage): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'message' => 'Invalid request body.'], 400);
        }

        @set_time_limit(120);
        $result = $downloads->addStream($data);

        if (!empty($result['success']) && !empty($data['path'])) {
            $path = (string) $data['path'];
            $recentPaths = $storage->get('recent_paths', []);
            $recentPaths = array_values(array_filter($recentPaths, static fn ($recent) => $recent !== $path));
            $recentPaths[] = $path;
            if (count($recentPaths) > 10) {
                array_shift($recentPaths);
            }
            $storage->set('recent_paths', $recentPaths);
        }

        return $this->json($result, !empty($result['success']) ? 200 : 422);
    }

    #[Route('/media/settings', name: 'media_settings', methods: ['POST'])]
    public function settings(Request $request, JsonStorage $storage, TmdbService $tmdb): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'message' => 'Invalid request body.'], 400);
        }

        $config = $storage->get('config', []);
        $previous = $config;
        $key = trim((string) ($data['tmdb_api_key'] ?? ''));
        $language = (string) ($data['tmdb_language'] ?? 'fr-FR');
        if (!in_array($language, ['fr-FR', 'en-US', 'en-GB', 'es-ES', 'de-DE', 'it-IT', 'pt-BR'], true)) {
            $language = 'fr-FR';
        }

        $config['tmdb_api_key'] = $key;
        $config['tmdb_language'] = $language;
        $storage->set('config', $config);

        if ($key !== '' && !$tmdb->testCredentials()) {
            $storage->set('config', $previous);
            return $this->json(['success' => false, 'message' => 'TMDB rejected this API key. Settings were not saved.'], 422);
        }

        return $this->json(['success' => true, 'message' => 'TMDB settings saved.']);
    }

    #[Route('/media/addons/validate', name: 'media_addons_validate', methods: ['POST'])]
    public function validateAddon(Request $request, MediaAddonService $addons): Response
    {
        $data = json_decode($request->getContent(), true);
        $url = trim((string) ($data['url'] ?? ''));
        if ($url === '') {
            return $this->json(['success' => false, 'message' => 'Manifest URL is required.'], 400);
        }

        $result = $addons->validateManifestUrl($url);
        if (empty($result['success'])) {
            return $this->json(['success' => false, 'message' => $result['error'] ?? 'Invalid manifest.'], 422);
        }

        $manifest = $result['manifest'];
        return $this->json([
            'success' => true,
            'manifest_url' => $result['manifest_url'],
            'base_url' => $result['base_url'],
            'manifest' => [
                'id' => $manifest['id'],
                'name' => $manifest['name'],
                'version' => $manifest['version'] ?? null,
                'description' => $manifest['description'] ?? null,
                'types' => $manifest['types'] ?? [],
                'resources' => $manifest['resources'] ?? [],
                'catalogs' => count($manifest['catalogs'] ?? []),
            ],
        ]);
    }

    #[Route('/media/addons', name: 'media_addons_save', methods: ['POST'])]
    public function saveAddons(Request $request, MediaAddonService $addons): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['addons']) || !is_array($data['addons'])) {
            return $this->json(['success' => false, 'message' => 'Invalid addon list.'], 400);
        }

        $saved = [];
        foreach ($data['addons'] as $index => $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $addon = $addons->normalizeAddon($raw);
            if ($addon['manifest_url'] === '') {
                return $this->json(['success' => false, 'message' => 'Addon #' . ($index + 1) . ' has no manifest URL.'], 422);
            }

            $validation = $addons->validateManifestUrl($addon['manifest_url']);
            if (empty($validation['success'])) {
                return $this->json([
                    'success' => false,
                    'message' => 'Addon #' . ($index + 1) . ' is invalid: ' . ($validation['error'] ?? 'unknown error'),
                ], 422);
            }

            $manifest = $validation['manifest'];
            $saved[] = [
                'id' => (string) ($raw['id'] ?? $manifest['id'] ?? hash('sha256', $validation['manifest_url'])),
                'name' => trim((string) ($raw['name'] ?? '')) ?: (string) ($manifest['name'] ?? 'Unnamed addon'),
                'manifest_url' => $validation['manifest_url'],
                'enabled' => (bool) ($raw['enabled'] ?? true),
                'priority' => (int) ($raw['priority'] ?? 100),
                'timeout' => max(3, min(20, (int) ($raw['timeout'] ?? 10))),
            ];
        }

        $addons->saveAddons($saved);

        return $this->json(['success' => true, 'addons' => $saved]);
    }
}
