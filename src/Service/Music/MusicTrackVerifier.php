<?php

namespace App\Service\Music;

class MusicTrackVerifier
{
    /**
     * @param array<int, array<string, mixed>> $expectedTracks
     * @param array<int, array<string, mixed>> $verifiedTracks
     *
     * @return array{matched: array<int, array<string, mixed>>, missing: array<int, array<string, mixed>>, validatedCount: int, status: string}
     */
    public function verify(array $expectedTracks, array $verifiedTracks): array
    {
        $matchedTracks = [];
        $missingTracks = [];

        foreach ($expectedTracks as $expected) {
            $found = false;
            foreach ($verifiedTracks as $verified) {
                if (
                    $this->normalizePart($expected['artist'] ?? '') === $this->normalizePart($verified['artist'] ?? '')
                    && $this->normalizePart($expected['album'] ?? '') === $this->normalizePart($verified['album'] ?? '')
                    && $this->normalizePart($expected['song_name'] ?? '') === $this->normalizePart($verified['song_name'] ?? '')
                ) {
                    $found = true;
                    $matchedTracks[] = $verified;
                    break;
                }
            }

            if (!$found) {
                $missingTracks[] = $expected;
            }
        }

        $validatedCount = count($matchedTracks);

        if ($expectedTracks === []) {
            $status = 'error';
        } elseif ($validatedCount === 0) {
            $status = 'error';
        } elseif ($validatedCount < count($expectedTracks)) {
            $status = 'warning';
        } else {
            $status = 'success';
        }

        return [
            'matched' => $matchedTracks,
            'missing' => $missingTracks,
            'validatedCount' => $validatedCount,
            'status' => $status,
        ];
    }

    public function normalizePart(?string $value): string
    {
        $value = trim((string) $value);
        $value = str_replace([' / ', ' /', '/ ', '/'], ', ', $value);
        $value = str_replace(['; ', ';'], ', ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return mb_strtolower($value);
    }
}
