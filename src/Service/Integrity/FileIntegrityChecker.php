<?php

namespace App\Service\Integrity;

use App\Service\MediaTypeHelper;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class FileIntegrityChecker
{
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_CORRUPTED = 'corrupted';
    public const STATUS_MISSING = 'missing';
    public const STATUS_OUTSIDE_ROOT = 'outside_root';
    public const STATUS_UNSUPPORTED = 'unsupported';

    private MediaTypeHelper $mediaTypeHelper;
    private string $ffprobeBinary;

    public function __construct(?MediaTypeHelper $mediaTypeHelper = null, ?string $ffprobeBinary = null)
    {
        $this->mediaTypeHelper = $mediaTypeHelper ?? new MediaTypeHelper();
        $this->ffprobeBinary = $ffprobeBinary ?? 'ffprobe';
    }

    public function check(
        string $filePath,
        array $allowedRoots,
        int $expectedSize = 0,
        bool $deep = false,
        int $smallFileThreshold = 1048576
    ): array {
        $result = [
            'status' => self::STATUS_HEALTHY,
            'reason' => '',
            'actual_size' => 0,
            'expected_size' => max(0, $expectedSize),
            'extra' => [],
        ];

        $filename = basename($filePath);
        if ($this->mediaTypeHelper->getType($filename) !== MediaTypeHelper::TYPE_VIDEO) {
            return $this->withStatus($result, self::STATUS_UNSUPPORTED, 'File type is not a supported video format.');
        }

        if (trim($filePath) === '') {
            return $this->withStatus($result, self::STATUS_MISSING, 'File path is empty.');
        }

        $resolved = $this->resolveWithinRoots($filePath, $allowedRoots);
        if (($resolved['error'] ?? null) === self::STATUS_OUTSIDE_ROOT) {
            return $this->withStatus($result, self::STATUS_OUTSIDE_ROOT, 'File path is outside the allowed roots.');
        }
        if (($resolved['error'] ?? null) === self::STATUS_MISSING) {
            return $this->withStatus($result, self::STATUS_MISSING, 'File does not exist.');
        }

        $realPath = (string) ($resolved['path'] ?? '');
        clearstatcache(true, $realPath);
        if (!is_file($realPath)) {
            return $this->withStatus($result, self::STATUS_MISSING, 'File does not exist.');
        }

        $size = @filesize($realPath);
        if ($size === false) {
            return $this->withStatus($result, self::STATUS_CORRUPTED, 'File size could not be read.');
        }
        $actual = (int) $size;
        $result['actual_size'] = $actual;

        if ($actual <= 0) {
            return $this->withStatus($result, self::STATUS_CORRUPTED, 'File is empty.');
        }

        $signature = $this->detectBadSignature($realPath);
        if ($signature !== null) {
            return $this->withStatus($result, self::STATUS_CORRUPTED, $signature);
        }

        if ($expectedSize > 0 && $actual < (int) round($expectedSize * 0.9)) {
            $result['extra']['expected_ratio'] = round($actual / $expectedSize, 4);

            return $this->withStatus(
                $result,
                self::STATUS_INCOMPLETE,
                'File is truncated (' . $actual . ' bytes for an expected ' . $expectedSize . ' bytes).'
            );
        }

        if ($smallFileThreshold > 0 && $actual < $smallFileThreshold) {
            return $this->withStatus(
                $result,
                self::STATUS_INCOMPLETE,
                'File is smaller than the minimum size (' . $actual . ' bytes).'
            );
        }

        if ($deep) {
            $deepResult = $this->deepCheck($realPath);
            $result['extra']['deep'] = $deepResult['status'];
            if ($deepResult['status'] === 'unavailable') {
                $result['reason'] = (string) ($deepResult['reason'] ?? 'Deep check unavailable.');

                return $result;
            }
            if ($deepResult['status'] !== 'ok') {
                $result['extra']['duration'] = $deepResult['duration'] ?? 0;
                $result['extra']['video_streams'] = $deepResult['video_streams'] ?? 0;

                return $this->withStatus($result, self::STATUS_CORRUPTED, (string) ($deepResult['reason'] ?? 'Deep check failed.'));
            }
            $result['extra']['duration'] = $deepResult['duration'];
            $result['extra']['video_streams'] = $deepResult['video_streams'];
        }

        return $result;
    }

    public function deepCheck(string $filePath): array
    {
        $binary = $this->resolveBinary();
        if ($binary === null) {
            return [
                'status' => 'unavailable',
                'reason' => 'Deep check unavailable (ffprobe not found).',
                'duration' => 0,
                'video_streams' => 0,
            ];
        }

        try {
            $process = new Process([
                $binary,
                '-v', 'error',
                '-print_format', 'json',
                '-show_format',
                '-show_streams',
                $filePath,
            ]);
            $process->setTimeout(15);
            $process->run();
        } catch (\Throwable) {
            return [
                'status' => 'unavailable',
                'reason' => 'Deep check unavailable (ffprobe not found).',
                'duration' => 0,
                'video_streams' => 0,
            ];
        }

        if (!$process->isSuccessful()) {
            return [
                'status' => 'failed',
                'reason' => 'ffprobe could not read the file.',
                'duration' => 0,
                'video_streams' => 0,
            ];
        }

        $data = json_decode($process->getOutput(), true);
        if (!is_array($data)) {
            return [
                'status' => 'failed',
                'reason' => 'ffprobe returned an invalid payload.',
                'duration' => 0,
                'video_streams' => 0,
            ];
        }

        $duration = (float) ($data['format']['duration'] ?? 0);
        $videoStreams = 0;
        foreach ((array) ($data['streams'] ?? []) as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? '') === 'video') {
                $videoStreams++;
            }
        }

        if ($duration <= 0) {
            return [
                'status' => 'failed',
                'reason' => 'Video duration is missing or zero.',
                'duration' => $duration,
                'video_streams' => $videoStreams,
            ];
        }

        if ($videoStreams < 1) {
            return [
                'status' => 'failed',
                'reason' => 'No video stream was found.',
                'duration' => $duration,
                'video_streams' => 0,
            ];
        }

        return [
            'status' => 'ok',
            'reason' => '',
            'duration' => $duration,
            'video_streams' => $videoStreams,
        ];
    }


    private function resolveBinary(): ?string
    {
        $binary = trim($this->ffprobeBinary);
        if ($binary === '') {
            return null;
        }

        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            return is_file($binary) && is_executable($binary) ? $binary : null;
        }

        $found = (new ExecutableFinder())->find($binary);

        return is_string($found) && $found !== '' ? $found : null;
    }

    private function resolveWithinRoots(string $filePath, array $allowedRoots): array
    {
        $roots = array_values(array_filter(array_map(
            static fn ($root): string => is_string($root) ? trim($root) : '',
            $allowedRoots
        ), static fn (string $root): bool => $root !== ''));

        if ($roots === []) {
            return ['error' => self::STATUS_OUTSIDE_ROOT];
        }

        $realPath = realpath($filePath);
        if ($realPath === false) {
            if (!$this->lexicallyWithinRoots($filePath, $roots)) {
                return ['error' => self::STATUS_OUTSIDE_ROOT];
            }

            return ['error' => self::STATUS_MISSING];
        }

        foreach ($roots as $root) {
            $rootReal = realpath($root);
            if ($rootReal === false) {
                continue;
            }
            if ($this->isWithin($realPath, $rootReal)) {
                return ['path' => $realPath];
            }
        }

        return ['error' => self::STATUS_OUTSIDE_ROOT];
    }

    private function lexicallyWithinRoots(string $filePath, array $roots): bool
    {
        if (!$this->isAbsolutePath($filePath)) {
            return false;
        }

        $normalized = $this->normalizePath($filePath);
        foreach ($roots as $root) {
            if (!$this->isAbsolutePath($root)) {
                continue;
            }
            if ($this->isWithin($normalized, $this->normalizePath($root))) {
                return true;
            }
        }

        return false;
    }

    private function isWithin(string $path, string $root): bool
    {
        if ($root === DIRECTORY_SEPARATOR) {
            return str_starts_with($path, DIRECTORY_SEPARATOR);
        }

        return $path === $root || str_starts_with($path, rtrim($root, '/\\') . DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = str_starts_with($path, '/') ? '/' : '';
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return $prefix . implode('/', $parts);
    }

    private function detectBadSignature(string $filePath): ?string
    {
        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            return 'File could not be opened.';
        }
        $head = (string) fread($handle, 512);
        fclose($handle);

        if ($head === '') {
            return null;
        }

        $lower = strtolower($head);
        if (str_contains($lower, '<html') || str_contains($lower, '<!doctype') || str_contains($lower, '<body')) {
            return 'File looks like an HTML error page.';
        }
        if (str_starts_with(ltrim($lower), '<?xml')) {
            return 'File looks like an XML document.';
        }

        $trimmed = ltrim($head);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            return 'File looks like a JSON payload.';
        }

        $printable = preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $head) !== 1;
        if ($printable && preg_match('/\b(error|forbidden|expired|unauthorized|not found)\b/i', $head) === 1) {
            return 'File looks like a plain-text error response.';
        }

        return null;
    }

    private function withStatus(array $result, string $status, string $reason): array
    {
        $result['status'] = $status;
        $result['reason'] = $reason;

        return $result;
    }
}
