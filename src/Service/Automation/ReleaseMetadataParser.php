<?php

namespace App\Service\Automation;

class ReleaseMetadataParser
{
    private const RESOLUTION_RANK = [
        '2160p' => 4,
        '1080p' => 3,
        '720p' => 2,
        '480p' => 1,
    ];

    public static function resolutionRank(?string $resolution): int
    {
        return self::RESOLUTION_RANK[strtolower((string) $resolution)] ?? 0;
    }

    public function parse(array $stream): array
    {
        $name = (string) ($stream['name'] ?? '');
        $title = (string) ($stream['title'] ?? '');
        $filename = (string) ($stream['filename'] ?? '');
        $text = trim($name . ' ' . $title . ' ' . $filename);

        $resolution = $this->parseResolution($text, $stream['quality'] ?? null);
        $codec = $this->parseCodec($text);
        $hdrTags = $this->parseHdr($text);
        $audioLangs = $this->parseAudio($text);
        $sourceType = $this->parseSourceType($text);
        $releaseGroup = $this->parseReleaseGroup($name, $filename);
        $sizeBytes = $this->parseSizeBytes($stream['size'] ?? null, $text);
        $seeders = $this->parseSeeders($stream['seeders'] ?? null, $text);
        $cached = $this->parseCached($text);

        return [
            'resolution' => $resolution,
            'resolution_rank' => self::resolutionRank($resolution),
            'codec' => $codec,
            'hdr' => $hdrTags[0] ?? null,
            'hdr_tags' => $hdrTags,
            'audio_langs' => $audioLangs,
            'source_type' => $sourceType,
            'release_group' => $releaseGroup,
            'size_bytes' => $sizeBytes,
            'size_gb' => $sizeBytes > 0 ? round($sizeBytes / 1024 / 1024 / 1024, 2) : null,
            'seeders' => $seeders,
            'cached' => $cached,
            'text' => $text,
        ];
    }

    public function parseResolution(string $text, mixed $fallback = null): ?string
    {
        if (preg_match('/\b(2160p|1080p|720p|480p)\b/i', $text, $matches)) {
            return strtolower($matches[1]);
        }
        if (preg_match('/\b(4k|uhd)\b/i', $text)) {
            return '2160p';
        }

        $fallback = strtolower(trim((string) $fallback));
        if ($fallback === '4k' || $fallback === 'uhd') {
            return '2160p';
        }

        return isset(self::RESOLUTION_RANK[$fallback]) ? $fallback : null;
    }

    public function parseCodec(string $text): ?string
    {
        if (preg_match('/\b(x265|h\.?265|hevc)\b/i', $text)) {
            return 'x265';
        }
        if (preg_match('/\b(av1)\b/i', $text)) {
            return 'av1';
        }
        if (preg_match('/\b(x264|h\.?264|avc)\b/i', $text)) {
            return 'h264';
        }
        if (preg_match('/\b(xvid)\b/i', $text)) {
            return 'xvid';
        }
        if (preg_match('/\b(divx)\b/i', $text)) {
            return 'divx';
        }
        if (preg_match('/\b(mpeg2|mpeg-2)\b/i', $text)) {
            return 'mpeg2';
        }

        return null;
    }

    public function parseHdr(string $text): array
    {
        $tags = [];
        if (preg_match('/\b(dv|dolby\s*vision)\b/i', $text)) {
            $tags[] = 'DV';
        }
        if (preg_match('/\b(hdr10\+|hdr10plus|hdr10)\b/i', $text)) {
            $tags[] = 'HDR10';
        }
        if (preg_match('/\b(hlg)\b/i', $text)) {
            $tags[] = 'HLG';
        }
        if (preg_match('/\bhdr\b/i', $text) && empty($tags)) {
            $tags[] = 'HDR';
        }

        return array_values(array_unique($tags));
    }

    public function parseAudio(string $text): array
    {
        $langs = [];
        if (preg_match('/\b(vostfr|vost|subfrench|sub\s*french)\b/i', $text)) {
            $langs[] = 'VOSTFR';
        }
        if (preg_match('/\b(multi)\b/i', $text)) {
            $langs[] = 'MULTI';
        }
        if (preg_match('/\b(vf|truefrench|french|fr)\b/i', $text)) {
            $langs[] = 'VF';
        }
        if (preg_match('/\b(vo|original)\b/i', $text)) {
            $langs[] = 'VO';
        }
        if (preg_match('/\b(ita|italian)\b/i', $text)) {
            $langs[] = 'ITA';
        }
        if (preg_match('/\b(ger|german|deutsch)\b/i', $text)) {
            $langs[] = 'GER';
        }
        if (preg_match('/\b(spa|spanish)\b/i', $text)) {
            $langs[] = 'SPA';
        }

        return array_values(array_unique($langs));
    }

    public function parseSourceType(string $text): ?string
    {
        if (preg_match('/\b(remux)\b/i', $text)) {
            return 'Remux';
        }
        if (preg_match('/\b(blu-?ray|bdrip|brrip|bdremux)\b/i', $text)) {
            return 'BluRay';
        }
        if (preg_match('/\b(web-?dl)\b/i', $text)) {
            return 'WEB-DL';
        }
        if (preg_match('/\b(web-?rip|webrip|web)\b/i', $text)) {
            return 'WEBRip';
        }
        if (preg_match('/\b(hdtv)\b/i', $text)) {
            return 'HDTV';
        }
        if (preg_match('/\b(dvdrip|dvd)\b/i', $text)) {
            return 'DVD';
        }
        if (preg_match('/\b(cam|hdcam)\b/i', $text)) {
            return 'CAM';
        }
        if (preg_match('/\b(ts)\b/i', $text)) {
            return 'TS';
        }

        return null;
    }

    public function parseReleaseGroup(string $name, string $filename): ?string
    {
        if (preg_match('/^\s*\[([^\]]{2,40})\]/', $name, $matches)) {
            $candidate = trim($matches[1]);
            if ($this->isPlausibleGroup($candidate)) {
                return $candidate;
            }
        }
        if ($filename !== '' && preg_match('/-([a-z0-9][a-z0-9_-]{1,30})\.[a-z0-9]{2,4}$/i', $filename, $matches)) {
            $candidate = trim($matches[1]);
            if ($this->isPlausibleGroup($candidate)) {
                return $candidate;
            }
        }
        if (preg_match('/-([a-z0-9][a-z0-9._-]{1,30})$/i', trim($name), $matches)) {
            $candidate = trim($matches[1]);
            if ($this->isPlausibleGroup($candidate)) {
                return $candidate;
            }
        }
        if (preg_match('/\b(?:by|group)\s*[:=]\s*([a-z0-9._-]{2,40})/i', $name, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function isPlausibleGroup(string $candidate): bool
    {
        return preg_match('/\b(2160p|1080p|720p|480p|x265|x264|h264|h265|hevc|av1|web|bluray|cam|ts)\b/i', $candidate) !== 1;
    }

    public function parseSizeBytes(mixed $size, string $text): int
    {
        if (is_int($size) || is_float($size)) {
            return max(0, (int) $size);
        }
        if (is_string($size) && $size !== '') {
            $bytes = $this->sizeStringToBytes($size);
            if ($bytes > 0) {
                return $bytes;
            }
            if (is_numeric($size) && (float) $size > 0) {
                return (int) $size;
            }
        }

        return $this->sizeStringToBytes($text);
    }

    private function sizeStringToBytes(string $value): int
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(TB|TO|GB|GO|MB|MO)\b/iu', $value, $matches)) {
            $number = (float) str_replace(',', '.', $matches[1]);
            $unit = strtoupper($matches[2]);
            $factor = match ($unit) {
                'TB', 'TO' => 1024 ** 4,
                'GB', 'GO' => 1024 ** 3,
                'MB', 'MO' => 1024 ** 2,
                default => 0,
            };

            return (int) round($number * $factor);
        }

        return 0;
    }

    public function parseSeeders(mixed $seeders, string $text): ?int
    {
        if (is_numeric($seeders)) {
            return max(0, (int) $seeders);
        }
        if (preg_match('/(?:👤|👥|seeders?)\s*[:]?\s*(\d+)/iu', $text, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function parseCached(string $text): ?bool
    {
        if (preg_match('/\b(uncached|not\s+cached|no\s+cache)\b/iu', $text)) {
            return false;
        }
        if (preg_match('/\b(cached|cache|instant|⚡)\b/iu', $text)) {
            return true;
        }

        return null;
    }
}
