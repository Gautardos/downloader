<?php

namespace App\Service\Jellyfin;

use App\Service\JsonStorage;
use Symfony\Component\HttpFoundation\Request;

class JellyfinAuthService
{
    private const STATE_KEY = 'jellyfin_auth';
    private const TOKEN_TTL = 31536000;

    private JsonStorage $storage;

    public function __construct(JsonStorage $storage)
    {
        $this->storage = $storage;
    }

    public function isEnabled(): bool
    {
        $config = $this->config();

        return !empty($config['jellyfin_enabled']);
    }

    public function config(): array
    {
        return $this->storage->get('config', []);
    }

    public function serverName(): string
    {
        $config = $this->config();
        $name = trim((string) ($config['jellyfin_server_name'] ?? ''));

        return $name !== '' ? $name : 'Downloader';
    }

    public function credentials(): array
    {
        $config = $this->config();
        $username = trim((string) ($config['jellyfin_username'] ?? ''));

        return [
            'username' => $username !== '' ? $username : 'jellyfin',
            'password' => (string) ($config['jellyfin_password'] ?? ''),
        ];
    }

    public function publicInfo(): array
    {
        return [
            'ServerName' => $this->serverName(),
            'Version' => '10.9.0',
            'ProductName' => 'Jellyfin Server',
            'Id' => $this->serverId(),
            'StartupWizardCompleted' => true,
        ];
    }

    public function authenticate(string $username, string $password): ?array
    {
        $credentials = $this->credentials();
        if ($credentials['password'] === '') {
            return null;
        }

        if (!hash_equals($credentials['username'], $username) || !hash_equals($credentials['password'], $password)) {
            return null;
        }

        $state = $this->state();
        $token = bin2hex(random_bytes(32));
        $now = time();
        $state['tokens'][$this->tokenKey($token)] = [
            'user_id' => (string) $state['user_id'],
            'created_at' => $now,
            'last_used_at' => $now,
        ];

        $state['tokens'] = array_filter(
            $state['tokens'],
            static fn ($entry) => is_array($entry) && $now - (int) ($entry['created_at'] ?? 0) < self::TOKEN_TTL
        );
        if (count($state['tokens']) > 50) {
            uasort($state['tokens'], static fn ($a, $b) => (int) ($b['last_used_at'] ?? 0) <=> (int) ($a['last_used_at'] ?? 0));
            $state['tokens'] = array_slice($state['tokens'], 0, 50, true);
        }

        $this->saveState($state);

        return [
            'User' => [
                'Id' => (string) $state['user_id'],
                'Name' => $credentials['username'],
            ],
            'AccessToken' => $token,
            'ServerId' => (string) $state['server_id'],
        ];
    }

    public function validateToken(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }

        $state = $this->state();
        $key = $this->tokenKey($token);
        $entry = $state['tokens'][$key] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        if (time() - (int) ($entry['created_at'] ?? 0) >= self::TOKEN_TTL) {
            unset($state['tokens'][$key]);
            $this->saveState($state);
            return null;
        }

        return $entry;
    }

    public function tokenFromRequest(Request $request): ?string
    {
        foreach (['api_key', 'ApiKey', 'apiKey'] as $name) {
            $value = $request->query->get($name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        foreach (['X-Emby-Token', 'X-MediaBrowser-Token'] as $header) {
            $value = $request->headers->get($header);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $authorization = (string) ($request->headers->get('Authorization') ?: $request->headers->get('X-Emby-Authorization') ?: '');
        if ($authorization !== '') {
            if (preg_match('/Token="([^"]+)"/i', $authorization, $matches)) {
                return $matches[1];
            }
            if (preg_match('/Token=([^,\s]+)/i', $authorization, $matches)) {
                return $matches[1];
            }
            if (preg_match('/Bearer\s+(.+)$/i', $authorization, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    public function logout(?string $token): void
    {
        if ($token === null || $token === '') {
            return;
        }

        $state = $this->state();
        unset($state['tokens'][$this->tokenKey($token)]);
        $this->saveState($state);
    }

    public function userId(): string
    {
        return (string) $this->state()['user_id'];
    }

    public function userName(): string
    {
        return $this->credentials()['username'];
    }

    public function serverId(): string
    {
        return (string) $this->state()['server_id'];
    }

    private function state(): array
    {
        $state = $this->storage->get(self::STATE_KEY, []);
        if (!is_array($state)) {
            $state = [];
        }

        $changed = false;
        foreach (['server_id', 'user_id'] as $key) {
            if (empty($state[$key]) || !is_string($state[$key])) {
                $state[$key] = bin2hex(random_bytes(16));
                $changed = true;
            }
        }
        if (!isset($state['tokens']) || !is_array($state['tokens'])) {
            $state['tokens'] = [];
            $changed = true;
        }

        if ($changed) {
            $this->saveState($state);
        }

        return $state;
    }

    private function saveState(array $state): void
    {
        $this->storage->set(self::STATE_KEY, $state);
    }

    private function tokenKey(string $token): string
    {
        return hash('sha256', $token);
    }
}
