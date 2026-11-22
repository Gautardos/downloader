<?php

namespace App\Service\MusicAutomation;

class MusicTrackMatcher
{
    public function normalize(string $value): string
    {
        $value = trim(mb_strtolower($value));
        if ($value === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                $value = $converted;
            }
        }

        $value = str_replace([' / ', ' /', '/ ', '/'], ', ', $value);
        $value = str_replace(['; ', ';'], ', ', $value);
        $value = preg_replace('/\s*,\s*/', ', ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    public function trackId(array $track): ?string
    {
        $id = trim((string) ($track['id'] ?? ''));
        if ($id !== '') {
            return $id;
        }

        $url = (string) ($track['url'] ?? '');
        if (preg_match('#(?:spotify:track:|open\.spotify\.com/track/)([A-Za-z0-9]{22})#', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function titleKey(array $track): string
    {
        return implode('|', [
            $this->normalize((string) ($track['artist'] ?? '')),
            $this->normalize((string) ($track['album'] ?? '')),
            $this->normalize((string) ($track['song_name'] ?? '')),
        ]);
    }

    public function looseKey(array $track): string
    {
        return implode('|', [
            $this->normalize((string) ($track['artist'] ?? '')),
            $this->normalize((string) ($track['song_name'] ?? '')),
        ]);
    }

    public function deduplicate(array $tracks): array
    {
        $seen = [];
        $result = [];
        foreach ($tracks as $track) {
            if (!is_array($track)) {
                continue;
            }
            $id = $this->trackId($track);
            $key = $id !== null ? 'id:' . $id : 'name:' . $this->titleKey($track);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $track;
        }

        return $result;
    }

    public function parseArchiveLines(array $lines): array
    {
        $ids = [];
        $keys = [];
        $loose = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (preg_match_all('#(?:spotify:track:|open\.spotify\.com/track/)([A-Za-z0-9]{22})#', $line, $matches)) {
                foreach ($matches[1] as $id) {
                    $ids[$id] = true;
                }
            }

            if (str_contains($line, ' - ')) {
                [$artist, $song] = explode(' - ', $line, 2);
                $looseKey = $this->normalize($artist) . '|' . $this->normalize($song);
                if (trim($looseKey, '|') !== '') {
                    $loose['loose:' . $looseKey] = true;
                }
            } elseif (!preg_match('#^https?://#i', $line) && !str_starts_with($line, 'spotify:')) {
                $normalized = $this->normalize($line);
                if ($normalized !== '') {
                    $keys['name:' . $normalized] = true;
                    $loose['loose:' . $normalized] = true;
                }
            }
        }

        return [
            'ids' => array_keys($ids),
            'keys' => array_keys($keys),
            'loose' => array_keys($loose),
        ];
    }

    public function archiveFromFile(?string $path): array
    {
        $path = trim((string) $path);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return ['ids' => [], 'keys' => [], 'loose' => []];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return ['ids' => [], 'keys' => [], 'loose' => []];
        }

        return $this->parseArchiveLines($lines);
    }

    public function historyIndex(array $history): array
    {
        $tracks = [];
        foreach ($history as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach ([$entry['expected_tracks'] ?? [], $entry['downloaded_tracks'] ?? []] as $list) {
                if (!is_array($list)) {
                    continue;
                }
                foreach ($list as $track) {
                    if (is_array($track)) {
                        $tracks[] = $track;
                    }
                }
            }
        }

        $indexed = $this->indexTracks($tracks);

        return $indexed;
    }

    public function indexTracks(array $tracks): array
    {
        $ids = [];
        $keys = [];
        $loose = [];
        foreach ($tracks as $track) {
            if (!is_array($track)) {
                continue;
            }
            $id = $this->trackId($track);
            if ($id !== null) {
                $ids[$id] = true;
            }
            $titleKey = $this->titleKey($track);
            if ($titleKey !== '||') {
                $keys['name:' . $titleKey] = true;
            }
            $looseKey = $this->looseKey($track);
            if ($looseKey !== '|') {
                $loose['loose:' . $looseKey] = true;
            }
        }

        return [
            'ids' => array_keys($ids),
            'keys' => array_keys($keys),
            'loose' => array_keys($loose),
        ];
    }

    public function isKnown(array $track, array $archive, array $library, array $history): bool
    {
        $id = $this->trackId($track);
        $titleKey = 'name:' . $this->titleKey($track);
        $looseKey = 'loose:' . $this->looseKey($track);

        foreach ([$library, $history] as $source) {
            if (!is_array($source)) {
                continue;
            }
            if ($id !== null && in_array($id, $source['ids'] ?? [], true)) {
                return true;
            }
            if (in_array($titleKey, $source['keys'] ?? [], true)) {
                return true;
            }
        }

        if (!is_array($archive)) {
            return false;
        }
        if ($id !== null && in_array($id, $archive['ids'] ?? [], true)) {
            return true;
        }
        if (in_array($titleKey, $archive['keys'] ?? [], true)) {
            return true;
        }

        return in_array($looseKey, $archive['loose'] ?? [], true);
    }

    public function filterNew(array $tracks, array $archive, array $library, array $history): array
    {
        $new = [];
        foreach ($this->deduplicate($tracks) as $track) {
            if (!$this->isKnown($track, $archive, $library, $history)) {
                $new[] = $track;
            }
        }

        return $new;
    }
}
