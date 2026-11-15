<?php

namespace App\Service\Jellyfin;

final class JellyfinIds
{
    private const PREFIXES = [
        'library' => 'lib',
        'movie' => 'mov',
        'series' => 'ser',
        'episode' => 'ep',
    ];

    public static function library(string $rootKey): string
    {
        return self::encode('library', ['root' => $rootKey]);
    }

    public static function movie(string $rootKey, string $key): string
    {
        return self::encode('movie', ['root' => $rootKey, 'key' => $key]);
    }

    public static function series(string $rootKey, string $key): string
    {
        return self::encode('series', ['root' => $rootKey, 'key' => $key]);
    }

    public static function episode(int $entryId): string
    {
        return self::encode('episode', ['id' => $entryId]);
    }

    public static function encode(string $kind, array $data): string
    {
        $prefix = self::PREFIXES[$kind] ?? 'itm';
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            $json = '{}';
        }

        return $prefix . '_' . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public static function decode(string $id): ?array
    {
        foreach (self::PREFIXES as $kind => $prefix) {
            if (!str_starts_with($id, $prefix . '_')) {
                continue;
            }

            $encoded = substr($id, strlen($prefix) + 1);
            $padding = strlen($encoded) % 4;
            if ($padding > 0) {
                $encoded .= str_repeat('=', 4 - $padding);
            }

            $json = base64_decode(strtr($encoded, '-_', '+/'), true);
            if ($json === false) {
                return null;
            }

            $data = json_decode($json, true);
            if (!is_array($data)) {
                return null;
            }

            return ['kind' => $kind] + $data;
        }

        return null;
    }
}
