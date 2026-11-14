<?php

namespace App\Service\Automation;

class ReleasePreferenceService
{
    private const WEIGHTS = [
        'resolution' => 30,
        'codec' => 18,
        'hdr' => 14,
        'audio' => 15,
        'seeders' => 10,
        'size' => 8,
        'addon' => 5,
    ];

    private const RESOLUTION_SCORE = [
        '2160p' => 1.0,
        '1080p' => 0.82,
        '720p' => 0.6,
        '480p' => 0.35,
    ];

    private const CODEC_SCORE = [
        'x265' => 1.0,
        'av1' => 0.95,
        'h264' => 0.75,
        'xvid' => 0.35,
        'divx' => 0.3,
        'mpeg2' => 0.25,
    ];

    private const HDR_SCORE = [
        'DV' => 1.0,
        'HDR10' => 1.0,
        'HLG' => 0.9,
        'HDR' => 0.8,
    ];

    private ReleaseMetadataParser $parser;
    private AutomationSettings $settings;

    public function __construct(ReleaseMetadataParser $parser, AutomationSettings $settings)
    {
        $this->parser = $parser;
        $this->settings = $settings;
    }

    public function getProfile(): array
    {
        $defaults = $this->settings->defaults()['quality_profile'];
        $stored = $this->settings->getQualityProfile();

        return $this->normalizeProfile(array_replace($defaults, $stored));
    }

    public function normalizeProfile(array $profile): array
    {
        $profile['resolution_order'] = $this->normalizeList($profile['resolution_order'] ?? ['2160p', '1080p', '720p']);
        $profile['resolution_order'] = array_values(array_filter(
            array_map(fn ($value) => strtolower(trim((string) $value)), $profile['resolution_order']),
            static fn ($value) => isset(self::RESOLUTION_SCORE[$value])
        ));
        if (empty($profile['resolution_order'])) {
            $profile['resolution_order'] = ['2160p', '1080p', '720p'];
        }

        $profile['max_resolution'] = $this->normalizeResolution($profile['max_resolution'] ?? '2160p') ?? '2160p';
        $profile['min_resolution'] = $this->normalizeResolution($profile['min_resolution'] ?? '720p');
        $profile['codecs'] = array_values(array_filter(array_map(
            fn ($value) => strtolower(trim((string) $value)),
            $this->normalizeList($profile['codecs'] ?? ['x265', 'h264', 'av1'])
        )));
        $profile['hdr'] = strtolower(trim((string) ($profile['hdr'] ?? 'prefer')));
        if (!in_array($profile['hdr'], ['ignore', 'off', 'prefer', 'require'], true)) {
            $profile['hdr'] = 'prefer';
        }
        $profile['audio_langs'] = array_values(array_filter(array_map(
            fn ($value) => strtoupper(trim((string) $value)),
            $this->normalizeList($profile['audio_langs'] ?? ['VF', 'VOSTFR', 'MULTI'])
        )));
        $profile['min_seeders'] = max(0, (int) ($profile['min_seeders'] ?? 5));
        $legacyMax = isset($profile['max_size_gb']) ? (float) $profile['max_size_gb'] : null;
        $profile['max_movie_size_gb'] = max(0, (float) ($profile['max_movie_size_gb'] ?? $legacyMax ?? 25));
        $profile['max_episode_size_gb'] = max(0, (float) ($profile['max_episode_size_gb'] ?? 5));
        $profile['max_size_gb'] = $profile['max_movie_size_gb'];
        $profile['exclude'] = array_values(array_filter(array_map(
            fn ($value) => strtoupper(trim((string) $value)),
            $this->normalizeList($profile['exclude'] ?? ['CAM', 'TS', '3D', 'SAMPLE'])
        )));
        $profile['addon_priority'] = (bool) ($profile['addon_priority'] ?? true);

        return $profile;
    }

    private function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value) ?: [];
        }

        return is_array($value) ? array_values($value) : [];
    }

    private function normalizeResolution(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '4k' || $value === 'uhd') {
            return '2160p';
        }

        return isset(self::RESOLUTION_SCORE[$value]) ? $value : null;
    }

    public function evaluate(array $stream, ?array $profile = null, string $mediaType = 'movie'): array
    {
        $profile = $this->normalizeProfile($profile ?? $this->getProfile());
        $metadata = $this->parser->parse($stream);
        $sizeLimitKey = in_array($mediaType, ['series', 'anime', 'episode'], true) ? 'max_episode_size_gb' : 'max_movie_size_gb';
        $failures = [];

        $resolution = $metadata['resolution'];
        $resolutionRank = (int) $metadata['resolution_rank'];
        $maxRank = ReleaseMetadataParser::resolutionRank($profile['max_resolution']);
        $minRank = ReleaseMetadataParser::resolutionRank($profile['min_resolution']);
        if ($resolution === null) {
            $failures[] = 'unknown_resolution';
        } else {
            if ($maxRank > 0 && $resolutionRank > $maxRank) {
                $failures[] = 'resolution_above_max';
            }
            if ($minRank > 0 && $resolutionRank < $minRank) {
                $failures[] = 'resolution_below_min';
            }
        }

        $codec = $metadata['codec'];
        if ($codec === null) {
            $failures[] = 'unknown_codec';
        } elseif (!empty($profile['codecs']) && !in_array($codec, $profile['codecs'], true)) {
            $failures[] = 'codec_not_allowed';
        }

        if (in_array($profile['hdr'], ['require'], true) && empty($metadata['hdr_tags'])) {
            $failures[] = 'hdr_required';
        }

        $audioMatch = $this->bestAudioMatch($metadata['audio_langs'], $profile['audio_langs']);
        if (!empty($profile['audio_langs']) && $audioMatch === null) {
            $failures[] = 'audio_language_not_allowed';
        }

        if (!empty($profile['min_seeders']) && empty($metadata['cached'])) {
            if ($metadata['seeders'] === null || $metadata['seeders'] < $profile['min_seeders']) {
                $failures[] = 'not_enough_seeders';
            }
        }

        $maxBytes = (int) round($profile[$sizeLimitKey] * 1024 ** 3);
        if ($maxBytes > 0) {
            if (empty($metadata['size_bytes'])) {
                $failures[] = 'unknown_size';
            } elseif ($metadata['size_bytes'] > $maxBytes) {
                $failures[] = 'size_above_max';
            }
        }

        foreach ($profile['exclude'] as $tag) {
            if ($tag === '') {
                continue;
            }
            if (preg_match('/\b' . preg_quote($tag, '/') . '\b/i', (string) $metadata['text'])) {
                $failures[] = 'excluded_tag:' . $tag;
            }
        }

        $failures = array_values(array_unique($failures));
        $scores = [
            'resolution' => $this->scoreResolution($metadata, $profile),
            'codec' => $this->scoreCodec($metadata, $profile),
            'hdr' => $this->scoreHdr($metadata, $profile),
            'audio' => $this->scoreAudio($metadata, $profile, $audioMatch),
            'seeders' => $this->scoreSeeders($metadata),
            'size' => $this->scoreSize($metadata, $profile[$sizeLimitKey]),
            'addon' => $this->scoreAddon($stream, $profile),
        ];

        $score = 0.0;
        foreach (self::WEIGHTS as $key => $weight) {
            $score += $scores[$key] * $weight;
        }
        $score = (int) round(max(0, min(100, $score)));

        $accepted = empty($failures);

        return [
            'accepted' => $accepted,
            'score' => $score,
            'failures' => $failures,
            'metadata' => $metadata,
            'scores' => $scores,
            'explanation' => $this->explain($stream, $metadata, $score, $accepted),
        ];
    }

    public function rank(array $streams, ?array $profile = null, string $mediaType = 'movie'): array
    {
        $profile = $this->normalizeProfile($profile ?? $this->getProfile());
        $accepted = [];
        $rejected = [];

        foreach ($streams as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $evaluation = $this->evaluate($stream, $profile, $mediaType);
            $candidate = array_merge($stream, [
                'evaluation' => $evaluation,
                'score' => $evaluation['score'],
                'explanation' => $evaluation['explanation'],
            ]);
            if ($evaluation['accepted']) {
                $accepted[] = $candidate;
            } else {
                $rejected[] = $candidate;
            }
        }

        usort($accepted, static function (array $a, array $b): int {
            $score = ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            if ($score !== 0) {
                return $score;
            }
            $seeders = ((int) ($b['evaluation']['metadata']['seeders'] ?? 0)) <=> ((int) ($a['evaluation']['metadata']['seeders'] ?? 0));
            if ($seeders !== 0) {
                return $seeders;
            }

            return ((int) ($a['priority'] ?? 100)) <=> ((int) ($b['priority'] ?? 100));
        });

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    private function bestAudioMatch(array $streamLangs, array $preferred): ?string
    {
        if (empty($preferred)) {
            return $streamLangs[0] ?? null;
        }
        $best = null;
        $bestIndex = PHP_INT_MAX;
        foreach ($preferred as $index => $lang) {
            if (in_array($lang, $streamLangs, true) && $index < $bestIndex) {
                $best = $lang;
                $bestIndex = $index;
            }
        }

        return $best;
    }

    private function scoreResolution(array $metadata, array $profile): float
    {
        return self::RESOLUTION_SCORE[$metadata['resolution']] ?? 0.2;
    }

    private function scoreCodec(array $metadata, array $profile): float
    {
        return self::CODEC_SCORE[$metadata['codec']] ?? 0.2;
    }

    private function scoreHdr(array $metadata, array $profile): float
    {
        if (in_array($profile['hdr'], ['ignore', 'off'], true)) {
            return 0.7;
        }
        $best = 0.35;
        foreach ($metadata['hdr_tags'] as $tag) {
            $best = max($best, self::HDR_SCORE[$tag] ?? 0.5);
        }

        return $best;
    }

    private function scoreAudio(array $metadata, array $profile, ?string $match): float
    {
        if (empty($profile['audio_langs'])) {
            return 0.7;
        }
        if ($match === null) {
            return 0.1;
        }
        $index = array_search($match, $profile['audio_langs'], true);
        $score = 1.0 - min(0.45, max(0, (int) $index) * 0.15);
        if (count(array_intersect($metadata['audio_langs'], $profile['audio_langs'])) > 1) {
            $score = min(1.0, $score + 0.05);
        }

        return $score;
    }

    private function scoreSeeders(array $metadata): float
    {
        if (!empty($metadata['cached'])) {
            return 1.0;
        }
        $seeders = (int) ($metadata['seeders'] ?? 0);
        if ($seeders >= 100) {
            return 1.0;
        }
        if ($seeders >= 50) {
            return 0.9;
        }
        if ($seeders >= 20) {
            return 0.75;
        }
        if ($seeders >= 5) {
            return 0.5;
        }

        return 0.2;
    }

    private function scoreSize(array $metadata, float $maxGb): float
    {
        $sizeGb = $metadata['size_gb'];
        if ($maxGb <= 0 || $sizeGb === null) {
            return 0.6;
        }
        $ratio = max(0.01, min(1.2, (float) $sizeGb / $maxGb));

        return max(0.2, 1 - abs($ratio - 0.65) / 0.65);
    }

    private function scoreAddon(array $stream, array $profile): float
    {
        if (empty($profile['addon_priority'])) {
            return 0.5;
        }
        $priority = (int) ($stream['priority'] ?? 100);

        return max(0.1, 1 - min(100, max(0, $priority)) / 120);
    }

    private function explain(array $stream, array $metadata, int $score, bool $accepted): string
    {
        $parts = [
            ($metadata['resolution'] ?? 'unknown') . ' ' . ($metadata['codec'] ?? 'unknown'),
        ];
        if (!empty($metadata['hdr_tags'])) {
            $parts[0] .= ' ' . implode('/', $metadata['hdr_tags']);
        }
        if (!empty($metadata['audio_langs'])) {
            $parts[0] .= ' ' . implode('/', $metadata['audio_langs']);
        }
        $parts[] = $metadata['size_gb'] !== null ? number_format((float) $metadata['size_gb'], 1, '.', '') . ' Go' : 'size unknown';
        $parts[] = $metadata['seeders'] !== null ? (int) $metadata['seeders'] . ' seeders' : 'seeders unknown';
        $addon = trim((string) ($stream['addon'] ?? $stream['selected_source'] ?? ''));
        if ($addon !== '') {
            $parts[] = $addon;
        }
        $parts[] = 'score ' . $score . ($accepted ? '' : ' (filtered)');

        return implode(' · ', $parts);
    }
}
