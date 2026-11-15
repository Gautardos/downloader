<?php

namespace App\Service\Jellyfin;

use App\Service\Automation\AutomationSettings;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class JellyfinMediaService
{
    private const SUBTITLE_INDEX_OFFSET = 1000;
    private const PROBE_TTL = 604800;
    private const PROBE_FAILURE_TTL = 300;

    private AutomationSettings $settings;
    private CacheInterface $cache;

    public function __construct(AutomationSettings $settings, CacheInterface $cache)
    {
        $this->settings = $settings;
        $this->cache = $cache;
    }

    public function mediaSources(array $entries, string $itemId): array
    {
        $sources = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !$this->streamPath($entry)) {
                continue;
            }

            $path = (string) $entry['path'];
            $sourceId = 'ms_' . (int) ($entry['id'] ?? 0);
            $probe = $this->probe($path);
            $streams = $probe['streams'] ?? [];
            $streams = array_merge($streams, $this->externalSubtitleStreams($path, $itemId, $sourceId));

            $defaultAudio = null;
            foreach ($streams as $stream) {
                if (($stream['Type'] ?? '') === 'Audio') {
                    $defaultAudio = $stream['Index'];
                    break;
                }
            }

            $sources[] = [
                'Id' => $sourceId,
                'Type' => 'Default',
                'Name' => $this->sourceName($entry),
                'Path' => $path,
                'Container' => strtolower((string) ($entry['extension'] ?? '')) ?: (string) ($probe['container'] ?? ''),
                'Size' => (int) ($entry['size'] ?? 0),
                'ETag' => $this->etag($entry),
                'SupportsDirectPlay' => true,
                'SupportsDirectStream' => true,
                'SupportsTranscoding' => false,
                'TranscodingUrl' => null,
                'DefaultAudioStreamIndex' => $defaultAudio,
                'MediaStreams' => array_values($streams),
            ];
        }

        return $sources;
    }

    public function durationTicksForEntries(array $entries): ?int
    {
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $path = $this->streamPath($entry);
            if ($path === null) {
                continue;
            }
            $ticks = (int) ($this->probe($path)['duration_ticks'] ?? 0);
            if ($ticks > 0) {
                return $ticks;
            }
        }

        return null;
    }

    public function streamEntry(array $entries, ?string $mediaSourceId): ?array
    {
        if ($mediaSourceId !== null && $mediaSourceId !== '') {
            foreach ($entries as $entry) {
                if ('ms_' . (int) ($entry['id'] ?? 0) === $mediaSourceId) {
                    return $entry;
                }
            }

            return null;
        }

        return $entries[0] ?? null;
    }

    public function streamResponse(array $entry): ?BinaryFileResponse
    {
        $path = $this->streamPath($entry);
        if ($path === null) {
            return null;
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $this->contentType((string) ($entry['extension'] ?? '')));
        $response->headers->set('Content-Disposition', 'inline; filename="' . addcslashes((string) ($entry['filename'] ?? basename($path)), '"\\') . '"');
        $response->headers->set('Accept-Ranges', 'bytes');
        $response->setAutoLastModified();
        $response->setPrivate();

        return $response;
    }

    public function subtitleResponse(array $entry, int $index): ?Response
    {
        $path = $this->streamPath($entry);
        if ($path === null) {
            return null;
        }

        $file = $this->subtitleFile($path, $index);
        if ($file === null) {
            return null;
        }

        $response = new Response((string) file_get_contents($file['path']));
        $response->headers->set('Content-Type', $file['content_type']);
        $response->headers->set('Cache-Control', 'private, max-age=86400');

        return $response;
    }

    public function streamPath(array $entry): ?string
    {
        $path = (string) ($entry['path'] ?? '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $rootKey = (string) ($entry['root_key'] ?? '');
        $roots = $this->settings->getEnabledRoots();
        $root = $roots[$rootKey] ?? null;
        $rootPath = is_array($root) ? (string) ($root['path'] ?? '') : '';
        if ($rootPath === '') {
            return null;
        }

        $realFile = realpath($path);
        $realRoot = realpath($rootPath);
        if ($realFile === false || $realRoot === false) {
            return null;
        }

        $realRoot = rtrim(str_replace('\\', '/', $realRoot), '/');
        $realFile = str_replace('\\', '/', $realFile);

        if (!str_starts_with($realFile, $realRoot . '/')) {
            return null;
        }

        return $realFile;
    }

    public function probe(string $path): array
    {
        $key = 'jellyfin_probe_' . sha1($path . '|' . @filesize($path) . '|' . @filemtime($path));

        return $this->cache->get($key, function (ItemInterface $item) use ($path): array {
            $item->expiresAfter(self::PROBE_TTL);

            try {
                $process = new Process([
                    'ffprobe',
                    '-v', 'quiet',
                    '-print_format', 'json',
                    '-show_format',
                    '-show_streams',
                    $path,
                ]);
                $process->setTimeout(30);
                $process->run();
                if (!$process->isSuccessful()) {
                    $item->expiresAfter(self::PROBE_FAILURE_TTL);
                    return [];
                }

                $data = json_decode($process->getOutput(), true);
                if (!is_array($data)) {
                    $item->expiresAfter(self::PROBE_FAILURE_TTL);
                    return [];
                }

                return $this->normalizeProbe($data);
            } catch (\Throwable $e) {
                $item->expiresAfter(self::PROBE_FAILURE_TTL);
                return [];
            }
        });
    }

    private function normalizeProbe(array $data): array
    {
        $format = is_array($data['format'] ?? null) ? $data['format'] : [];
        $duration = (float) ($format['duration'] ?? 0);
        $streams = [];

        foreach (($data['streams'] ?? []) as $stream) {
            if (!is_array($stream)) {
                continue;
            }

            $type = strtolower((string) ($stream['codec_type'] ?? ''));
            $index = isset($stream['index']) ? (int) $stream['index'] : null;
            if ($index === null) {
                continue;
            }

            $language = null;
            $tags = is_array($stream['tags'] ?? null) ? $stream['tags'] : [];
            if (!empty($tags['language'])) {
                $language = strtolower((string) $tags['language']);
            }

            if ($type === 'video') {
                $width = isset($stream['width']) ? (int) $stream['width'] : null;
                $height = isset($stream['height']) ? (int) $stream['height'] : null;
                $codec = (string) ($stream['codec_name'] ?? '');
                $streams[] = [
                    'Type' => 'Video',
                    'Index' => $index,
                    'Codec' => $codec ?: null,
                    'Width' => $width,
                    'Height' => $height,
                    'DisplayTitle' => $this->videoLabel($width, $height, $codec),
                    'IsExternal' => false,
                ];
                continue;
            }

            if ($type === 'audio') {
                $codec = (string) ($stream['codec_name'] ?? '');
                $streams[] = [
                    'Type' => 'Audio',
                    'Index' => $index,
                    'Codec' => $codec ?: null,
                    'Language' => $language,
                    'DisplayTitle' => $this->trackLabel($language, $codec, 'Audio'),
                    'IsExternal' => false,
                ];
                continue;
            }

            if ($type === 'subtitle') {
                $codec = (string) ($stream['codec_name'] ?? '');
                $streams[] = [
                    'Type' => 'Subtitle',
                    'Index' => $index,
                    'Codec' => $codec ?: null,
                    'Language' => $language,
                    'DisplayTitle' => $this->trackLabel($language, $codec, 'Subtitle'),
                    'IsExternal' => false,
                    'DeliveryMethod' => 'Embed',
                ];
            }
        }

        $container = (string) ($format['format_name'] ?? '');
        if (str_contains($container, ',')) {
            $container = explode(',', $container)[0];
        }

        return [
            'duration_ticks' => $duration > 0 ? (int) round($duration * 10_000_000) : 0,
            'container' => $container,
            'streams' => $streams,
        ];
    }

    private function externalSubtitleStreams(string $path, string $itemId, string $sourceId): array
    {
        $streams = [];
        foreach ($this->externalSubtitles($path) as $offset => $subtitle) {
            $index = self::SUBTITLE_INDEX_OFFSET + $offset;
            $streams[] = [
                'Type' => 'Subtitle',
                'Index' => $index,
                'Codec' => $subtitle['codec'],
                'Language' => null,
                'DisplayTitle' => $subtitle['label'],
                'IsExternal' => true,
                'DeliveryMethod' => 'External',
                'DeliveryUrl' => '/Videos/' . rawurlencode($itemId)
                    . '/' . rawurlencode($sourceId)
                    . '/Subtitles/' . $index
                    . '/Stream.' . $subtitle['extension'],
            ];
        }

        return $streams;
    }

    private function externalSubtitles(string $path): array
    {
        $directory = dirname($path);
        $stem = pathinfo($path, PATHINFO_FILENAME);
        $result = [];

        foreach (['srt', 'ass', 'ssa', 'vtt', 'sub'] as $extension) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $stem . '.' . $extension;
            if (!is_file($candidate) || !is_readable($candidate)) {
                continue;
            }
            $result[] = [
                'path' => $candidate,
                'extension' => $extension,
                'codec' => $this->subtitleCodec($extension),
                'label' => strtoupper($extension),
            ];
        }

        return $result;
    }

    private function subtitleFile(string $path, int $index): ?array
    {
        $offset = $index - self::SUBTITLE_INDEX_OFFSET;
        if ($offset < 0) {
            return null;
        }

        $subtitles = $this->externalSubtitles($path);
        $subtitle = $subtitles[$offset] ?? null;
        if ($subtitle === null) {
            return null;
        }

        return [
            'path' => $subtitle['path'],
            'content_type' => match ($subtitle['extension']) {
                'vtt' => 'text/vtt; charset=utf-8',
                'ass', 'ssa' => 'text/x-ssa; charset=utf-8',
                'sub' => 'text/plain; charset=utf-8',
                default => 'application/x-subrip; charset=utf-8',
            },
        ];
    }

    private function sourceName(array $entry): string
    {
        $quality = trim((string) ($entry['quality'] ?? ''));
        if ($quality !== '') {
            return $quality;
        }

        return (string) ($entry['filename'] ?? '');
    }

    private function etag(array $entry): string
    {
        return sha1(
            (string) ($entry['path'] ?? '')
            . '|' . (int) ($entry['size'] ?? 0)
            . '|' . (int) ($entry['mtime'] ?? 0)
        );
    }

    private function videoLabel(?int $width, ?int $height, string $codec): string
    {
        $resolution = match (true) {
            ($width ?? 0) >= 3200 || ($height ?? 0) >= 2000 => '4K',
            ($width ?? 0) >= 1800 || ($height ?? 0) >= 1000 => '1080p',
            ($width ?? 0) >= 1200 || ($height ?? 0) >= 700 => '720p',
            ($height ?? 0) > 0 => $height . 'p',
            default => '',
        };

        return trim($resolution . ' ' . strtoupper($codec));
    }

    private function trackLabel(?string $language, string $codec, string $fallback): string
    {
        $label = trim(ucfirst((string) $language) . ' ' . strtoupper($codec));

        return $label !== '' ? $label : $fallback;
    }

    private function subtitleCodec(string $extension): string
    {
        return match ($extension) {
            'srt' => 'subrip',
            'ass' => 'ass',
            'ssa' => 'ssa',
            'vtt' => 'webvtt',
            default => 'subrip',
        };
    }

    private function contentType(string $extension): string
    {
        return match (strtolower($extension)) {
            'mp4', 'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
            'ts', 'm2ts' => 'video/mp2t',
            'mpg', 'mpeg' => 'video/mpeg',
            default => 'application/octet-stream',
        };
    }
}
