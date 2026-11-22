<?php

namespace App\Service\Pwa;

use Minishlink\WebPush\VAPID;

class VapidKeyService
{
    private const DEFAULT_SUBJECT = 'mailto:admin@localhost';

    private string $storageDir;
    private string $path;

    public function __construct(private string $projectDir)
    {
        $this->storageDir = $projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'storage';
        $this->path = $this->storageDir . DIRECTORY_SEPARATOR . 'pwa_vapid.json';
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function isConfigured(): bool
    {
        return $this->readValid() !== null;
    }

    public function getPublicKey(): ?string
    {
        $data = $this->readValid();

        return $data['public_key'] ?? null;
    }

    /**
     * @return array{subject:string,publicKey:string,privateKey:string}|null
     */
    public function getVapidConfig(): ?array
    {
        $data = $this->readValid();
        if ($data === null) {
            return null;
        }

        return [
            'subject' => (string) ($data['subject'] ?? self::DEFAULT_SUBJECT),
            'publicKey' => (string) $data['public_key'],
            'privateKey' => (string) $data['private_key'],
        ];
    }

    /**
     * @return array{publicKey:string,created:bool,path:string}
     */
    public function generate(bool $force = false): array
    {
        if (!$force) {
            $existing = $this->readValid();
            if ($existing !== null) {
                return [
                    'publicKey' => (string) $existing['public_key'],
                    'created' => false,
                    'path' => $this->path,
                ];
            }
        }

        if (!class_exists(VAPID::class)) {
            throw new \RuntimeException('minishlink/web-push is not installed; run composer install.');
        }

        $keys = VAPID::createVapidKeys();
        $publicKey = (string) ($keys['publicKey'] ?? '');
        $privateKey = (string) ($keys['privateKey'] ?? '');

        if (!$this->keysAreValid($publicKey, $privateKey)) {
            throw new \RuntimeException('Generated VAPID keys are invalid.');
        }

        if (!is_dir($this->storageDir)) {
            if (!mkdir($this->storageDir, 0700, true) && !is_dir($this->storageDir)) {
                throw new \RuntimeException('Unable to create the storage directory for VAPID keys.');
            }
            @chmod($this->storageDir, 0700);
        }

        $payload = [
            'public_key' => $publicKey,
            'private_key' => $privateKey,
            'subject' => self::DEFAULT_SUBJECT,
            'created_at' => date('c'),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Unable to encode VAPID keys.');
        }

        $tmp = $this->path . '.tmp.' . bin2hex(random_bytes(4));
        $written = file_put_contents($tmp, $json, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException('Unable to write VAPID keys.');
        }
        @chmod($tmp, 0600);

        if (!rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to persist VAPID keys.');
        }
        @chmod($this->path, 0600);

        return [
            'publicKey' => $publicKey,
            'created' => true,
            'path' => $this->path,
        ];
    }

    private function readValid(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }

        $raw = @file_get_contents($this->path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $publicKey = isset($data['public_key']) && is_string($data['public_key']) ? $data['public_key'] : '';
        $privateKey = isset($data['private_key']) && is_string($data['private_key']) ? $data['private_key'] : '';
        if (!$this->keysAreValid($publicKey, $privateKey)) {
            return null;
        }
        @chmod($this->path, 0600);

        return $data;
    }

    private function keysAreValid(string $publicKey, string $privateKey): bool
    {
        return strlen($this->base64UrlDecode($publicKey)) === 65
            && strlen($this->base64UrlDecode($privateKey)) === 32;
    }

    private function base64UrlDecode(string $value): string
    {
        $value = strtr(trim($value), '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($value, true);

        return $decoded === false ? '' : $decoded;
    }
}
