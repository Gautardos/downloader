<?php

namespace App\Service\Pwa;

use Psr\Log\LoggerInterface;

class PushNotifier implements PushNotifierInterface
{
    private const ALLOWED_URLS = [
        '/',
        '/queue',
        '/history',
        '/media',
        '/automation',
        '/video/integrity',
        '/statistics',
        '/maintenance',
        '/config',
    ];

    public function __construct(
        private WebPushServiceInterface $webPush,
        private PushSubscriptionStore $store,
        private ?LoggerInterface $logger = null
    ) {
    }

    public function notify(string $event, string $title, string $body, string $url = '/'): bool
    {
        if (!in_array($event, PushSubscriptionStore::PREFERENCE_KEYS, true)) {
            return false;
        }

        try {
            if (!$this->store->isEventEnabled($event)) {
                return false;
            }

            $payload = [
                'title' => $this->sanitizeTitle($title),
                'body' => $this->sanitizeBody($body),
                'icon' => '/pwa-icons/icon-192.png',
                'url' => $this->sanitizeUrl($url),
                'tag' => $event,
            ];

            $result = $this->webPush->sendToAll($payload, 3600);

            return (int) ($result['sent'] ?? 0) > 0;
        } catch (\Throwable $e) {
            if ($this->logger !== null) {
                $this->logger->warning('Web push notification was skipped after an error', [
                    'event' => $event,
                ]);
            }

            return false;
        }
    }

    private function sanitizeTitle(string $title): string
    {
        $title = $this->cleanText($title);

        return $title !== '' ? $title : 'Downloader';
    }

    private function sanitizeBody(string $body): string
    {
        $body = $this->cleanText($body);

        return $body !== '' ? $body : 'New activity in Downloader.';
    }

    private function cleanText(string $value): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/https?:\/\/\S+/i', '[link]', $value) ?? $value;
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_substr($value, 0, 300);
    }

    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//') || str_contains($url, '\\')) {
            return '/';
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || !in_array($path, self::ALLOWED_URLS, true)) {
            return '/';
        }

        return $path;
    }
}
