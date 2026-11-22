<?php

namespace App\Service\MusicAutomation;

use App\Service\JsonStorage;
use App\Service\PythonExecutor;

class TaggedMusicLibraryIndex implements MusicLibraryIndexInterface
{
    public const CACHE_KEY = 'music_automation_index';
    public const CACHE_TTL = 21600;

    public function __construct(
        private JsonStorage $storage,
        private PythonExecutor $python,
        private MusicTrackMatcher $matcher
    ) {
    }

    public function getIndex(bool $refresh = false): array
    {
        $cache = $this->storage->get(self::CACHE_KEY, []);
        $cache = is_array($cache) ? $cache : [];

        if (!$refresh && $this->isFresh($cache)) {
            $cache['cached'] = true;
            return $cache;
        }

        $config = $this->storage->get('config', []);
        $roots = [];
        foreach ([$config['music_library_path'] ?? '', $config['music_root_path'] ?? ''] as $path) {
            $path = trim((string) $path);
            if ($path !== '' && is_dir($path) && is_readable($path)) {
                $roots[$path] = true;
            }
        }

        $tracks = [];
        $errors = [];
        foreach (array_keys($roots) as $root) {
            try {
                $process = $this->python->execute('cli/music_downloader.py', [
                    '--verify' => $root,
                    '--recursive' => '',
                ], 1800);
                $process->run();
                if (!$process->isSuccessful()) {
                    $errors[] = trim($process->getErrorOutput()) ?: 'verify failed for ' . $root;
                    continue;
                }
                $decoded = json_decode(trim($process->getOutput()), true);
                if (is_array($decoded)) {
                    foreach ($decoded as $entry) {
                        if (is_array($entry)) {
                            $tracks[] = $entry;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        $index = $this->matcher->indexTracks($tracks);

        $ids = $index['ids'];
        foreach ($tracks as $track) {
            $haystack = (string) ($track['rel_path'] ?? '') . ' ' . (string) ($track['filename'] ?? '');
            if (preg_match_all('#(?:spotify:track:|open\.spotify\.com/track/)([A-Za-z0-9]{22})#', $haystack, $matches)) {
                foreach ($matches[1] as $id) {
                    if (!in_array($id, $ids, true)) {
                        $ids[] = $id;
                    }
                }
            }
        }

        $errors = array_values(array_filter($errors));
        if ($tracks === [] && $errors !== [] && $cache !== []) {
            $cache['cached'] = true;
            $cache['errors'] = $errors;
            return $cache;
        }

        $payload = [
            'ids' => $ids,
            'keys' => $index['keys'],
            'loose' => $index['loose'],
            'roots' => array_keys($roots),
            'generated_at' => time(),
            'errors' => $errors,
        ];

        if (($index['keys'] !== [] || $index['ids'] !== []) || $cache === []) {
            $this->storage->set(self::CACHE_KEY, $payload);
        }

        $payload['cached'] = false;
        return $payload;
    }

    private function isFresh(array $cache): bool
    {
        $generatedAt = (int) ($cache['generated_at'] ?? 0);

        return $generatedAt > 0 && (time() - $generatedAt) < self::CACHE_TTL;
    }
}
