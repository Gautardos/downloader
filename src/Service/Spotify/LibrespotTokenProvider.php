<?php

namespace App\Service\Spotify;

use App\Service\PythonExecutor;
use Symfony\Component\HttpKernel\KernelInterface;

class LibrespotTokenProvider
{
    private const TOKEN_CACHE_BUFFER = 60;
    private const DEFAULT_TOKEN_TTL = 3300;
    private const DEFAULT_COOLDOWN_SECONDS = 86400;

    public function __construct(
        private PythonExecutor $python,
        private KernelInterface $kernel
    ) {
    }

    private function tokenCacheFile(): string
    {
        return $this->kernel->getProjectDir() . '/var/cache/spotify_librespot_token.json';
    }

    private function cooldownFile(): string
    {
        return $this->kernel->getProjectDir() . '/var/cache/spotify_librespot_cooldown.json';
    }

    public function isCooldownActive(): bool
    {
        return $this->cooldownUntil() > time();
    }

    public function cooldownUntil(): int
    {
        $data = $this->readJson($this->cooldownFile());
        $until = (int) ($data['until'] ?? 0);

        return $until > time() ? $until : 0;
    }

    public function cooldownRemaining(): int
    {
        $until = $this->cooldownUntil();

        return $until > 0 ? max(0, $until - time()) : 0;
    }

    public function recordCooldown(?int $retryAfter, ?int $status = null, string $reason = ''): void
    {
        $seconds = ($retryAfter !== null && $retryAfter > 0) ? $retryAfter : self::DEFAULT_COOLDOWN_SECONDS;
        $seconds = max(60, $seconds);
        $now = time();

        $this->writeJson($this->cooldownFile(), [
            'until' => $now + $seconds,
            'retry_after' => $seconds,
            'status' => $status,
            'reason' => mb_substr($reason, 0, 200),
            'updated_at' => $now,
        ]);
    }

    public function getToken(): ?string
    {
        if ($this->isCooldownActive()) {
            return null;
        }

        $cached = $this->readJson($this->tokenCacheFile());
        $token = (string) ($cached['access_token'] ?? '');
        $expiresAt = (int) ($cached['expires_at'] ?? 0);

        if ($token !== '' && $expiresAt > time() + self::TOKEN_CACHE_BUFFER) {
            return $token;
        }

        return $this->requestToken();
    }

    public function invalidate(): void
    {
        @unlink($this->tokenCacheFile());
    }

    private function requestToken(): ?string
    {
        try {
            $process = $this->python->execute('cli/spotify_user.py', ['--token'], 60);
            $process->run();

            $output = trim($process->getOutput());
            $data = json_decode($output, true);
            if (!is_array($data)) {
                return null;
            }

            if (empty($data['success'])) {
                $status = (int) ($data['status'] ?? 0);
                $retryAfter = $data['retry_after'] ?? null;
                if (!empty($data['cooldown']) || $status === 429) {
                    $this->recordCooldown(
                        is_numeric($retryAfter) ? (int) $retryAfter : null,
                        $status ?: 429,
                        (string) ($data['error'] ?? 'librespot token')
                    );
                }
                return null;
            }

            $token = (string) ($data['access_token'] ?? '');
            if ($token === '') {
                return null;
            }

            $expiresIn = (int) ($data['expires_in'] ?? self::DEFAULT_TOKEN_TTL);
            $this->writeJson($this->tokenCacheFile(), [
                'access_token' => $token,
                'expires_at' => time() + max(60, $expiresIn - self::TOKEN_CACHE_BUFFER),
                'updated_at' => time(),
            ]);

            return $token;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function readJson(string $file): array
    {
        try {
            if (!is_file($file)) {
                return [];
            }
            $data = json_decode((string) file_get_contents($file), true);
            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function writeJson(string $file, array $data): void
    {
        try {
            $dir = dirname($file);
            if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
                return;
            }
            $tmp = $file . '.tmp';
            file_put_contents($tmp, json_encode($data), LOCK_EX);
            @rename($tmp, $file);
        } catch (\Throwable $e) {
        }
    }
}
