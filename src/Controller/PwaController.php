<?php

namespace App\Controller;

use App\Service\Pwa\PushSubscriptionStore;
use App\Service\Pwa\VapidKeyService;
use App\Service\Pwa\WebPushServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class PwaController extends AbstractController
{
    private const PUSH_HOSTS = [
        'fcm.googleapis.com',
        'android.googleapis.com',
        'updates.push.services.mozilla.com',
        'push.services.mozilla.com',
        'notify.windows.com',
        'wns.windows.com',
        'web.push.apple.com',
        'push.apple.com',
    ];

    #[Route('/pwa/vapid-public-key', name: 'pwa_vapid_public_key', methods: ['GET'])]
    public function vapidPublicKey(VapidKeyService $vapid): JsonResponse
    {
        $publicKey = $vapid->getPublicKey();

        return $this->json([
            'success' => true,
            'configured' => $publicKey !== null,
            'publicKey' => $publicKey,
        ]);
    }

    #[Route('/pwa/status', name: 'pwa_status', methods: ['GET'])]
    public function status(VapidKeyService $vapid, PushSubscriptionStore $store): JsonResponse
    {
        $preferences = $store->getPreferences();

        return $this->json([
            'success' => true,
            'vapidConfigured' => $vapid->isConfigured(),
            'subscriptionCount' => $store->countSubscriptions(false),
            'enabledSubscriptionCount' => $store->countSubscriptions(true),
            'subscribed' => $store->countSubscriptions(true) > 0,
            'preferences' => $preferences,
            'serviceWorker' => '/service-worker.js',
            'offlineUrl' => '/offline.html',
        ]);
    }

    #[Route('/pwa/subscribe', name: 'pwa_subscribe', methods: ['POST'])]
    public function subscribe(Request $request, PushSubscriptionStore $store): JsonResponse
    {
        $limited = $this->rateLimit($request, 'pwa_subscribe', 20, 60);
        if ($limited !== null) {
            return $limited;
        }

        $payload = $this->payload($request);
        $subscription = isset($payload['subscription']) && is_array($payload['subscription'])
            ? $payload['subscription']
            : $payload;

        try {
            $data = $this->normalizeSubscription($subscription);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $store->upsertSubscription(
            $data['endpoint'],
            $data['p256dh'],
            $data['auth'],
            (string) $request->headers->get('User-Agent', '')
        );

        return $this->json([
            'success' => true,
            'message' => 'Push subscription saved.',
            'subscribed' => true,
            'subscriptionCount' => $store->countSubscriptions(true),
        ]);
    }

    #[Route('/pwa/unsubscribe', name: 'pwa_unsubscribe', methods: ['POST'])]
    public function unsubscribe(Request $request, PushSubscriptionStore $store): JsonResponse
    {
        $limited = $this->rateLimit($request, 'pwa_unsubscribe', 20, 60);
        if ($limited !== null) {
            return $limited;
        }

        $payload = $this->payload($request);
        $endpoint = isset($payload['endpoint']) && is_string($payload['endpoint']) ? trim($payload['endpoint']) : '';
        if ($endpoint === '' || strlen($endpoint) > 2048) {
            return $this->json(['success' => false, 'message' => 'A valid endpoint is required.'], 422);
        }

        $removed = $store->removeSubscription($endpoint);

        return $this->json([
            'success' => true,
            'message' => $removed ? 'Push subscription removed.' : 'Push subscription was not found.',
            'removed' => $removed,
            'subscribed' => $store->countSubscriptions(true) > 0,
            'subscriptionCount' => $store->countSubscriptions(true),
        ]);
    }

    #[Route('/pwa/test', name: 'pwa_test', methods: ['POST'])]
    public function test(Request $request, VapidKeyService $vapid, PushSubscriptionStore $store, WebPushServiceInterface $webPush): JsonResponse
    {
        $limited = $this->rateLimit($request, 'pwa_test', 3, 30);
        if ($limited !== null) {
            return $limited;
        }

        if (!$vapid->isConfigured()) {
            return $this->json([
                'success' => false,
                'message' => 'VAPID keys are missing. Run php bin/console app:pwa:vapid.',
            ], 409);
        }

        if ($store->countSubscriptions(true) === 0) {
            return $this->json([
                'success' => false,
                'message' => 'Subscribe this browser before sending a test notification.',
            ], 409);
        }

        $result = $webPush->sendToAll([
            'title' => 'Downloader test notification',
            'body' => 'Push notifications are working.',
            'icon' => '/pwa-icons/icon-192.png',
            'url' => '/config',
            'tag' => 'pwa-test',
        ], 60);

        $sent = (int) ($result['sent'] ?? 0);

        return $this->json([
            'success' => $sent > 0,
            'message' => $sent > 0
                ? 'Test notification sent.'
                : 'No notification could be delivered.',
            'sent' => $sent,
            'failed' => (int) ($result['failed'] ?? 0),
            'removed' => (int) ($result['removed'] ?? 0),
        ], $sent > 0 ? 200 : 422);
    }

    #[Route('/pwa/preferences', name: 'pwa_preferences', methods: ['POST'])]
    public function preferences(Request $request, PushSubscriptionStore $store): JsonResponse
    {
        $payload = $this->payload($request);
        $input = isset($payload['preferences']) && is_array($payload['preferences'])
            ? $payload['preferences']
            : $payload;

        $preferences = [];
        foreach (PushSubscriptionStore::PREFERENCE_KEYS as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            $preferences[$key] = is_string($value)
                ? filter_var($value, FILTER_VALIDATE_BOOLEAN)
                : (bool) $value;
        }

        if ($preferences === []) {
            return $this->json(['success' => false, 'message' => 'No valid preference supplied.'], 422);
        }

        $saved = $store->savePreferences($preferences);

        return $this->json([
            'success' => true,
            'message' => 'Preferences saved.',
            'preferences' => $saved,
        ]);
    }

    /**
     * @param array<string,mixed> $subscription
     * @return array{endpoint:string,p256dh:string,auth:string}
     */
    private function normalizeSubscription(array $subscription): array
    {
        $endpoint = isset($subscription['endpoint']) && is_string($subscription['endpoint'])
            ? trim($subscription['endpoint'])
            : '';
        $keys = isset($subscription['keys']) && is_array($subscription['keys']) ? $subscription['keys'] : [];
        $p256dh = isset($keys['p256dh']) && is_string($keys['p256dh']) ? trim($keys['p256dh']) : '';
        $auth = isset($keys['auth']) && is_string($keys['auth']) ? trim($keys['auth']) : '';

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            throw new \InvalidArgumentException('A complete push subscription is required.');
        }

        if (strlen($endpoint) < 20 || strlen($endpoint) > 2048) {
            throw new \InvalidArgumentException('Push endpoint length is invalid.');
        }

        $parts = parse_url($endpoint);
        if (!is_array($parts)) {
            throw new \InvalidArgumentException('Push endpoint is not a valid URL.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $port = $parts['port'] ?? null;

        if ($scheme !== 'https') {
            throw new \InvalidArgumentException('Push endpoint must use HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Push endpoint must not contain credentials.');
        }
        if ($host === '' || $path === '' || $path === '/') {
            throw new \InvalidArgumentException('Push endpoint host or path is invalid.');
        }
        if ($port !== null && (int) $port !== 443) {
            throw new \InvalidArgumentException('Push endpoint must use port 443.');
        }
        if (!$this->isAllowedPushHost($host)) {
            throw new \InvalidArgumentException('Push endpoint host is not supported.');
        }

        if (strlen($p256dh) < 20 || strlen($p256dh) > 256 || !preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $p256dh)) {
            throw new \InvalidArgumentException('Push p256dh key is invalid.');
        }
        if (strlen($auth) < 8 || strlen($auth) > 128 || !preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $auth)) {
            throw new \InvalidArgumentException('Push auth key is invalid.');
        }

        return [
            'endpoint' => $endpoint,
            'p256dh' => $p256dh,
            'auth' => $auth,
        ];
    }

    private function isAllowedPushHost(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));

        foreach (self::PUSH_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(Request $request): array
    {
        $content = trim((string) $request->getContent());
        if ($content !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $all = $request->request->all();

        return is_array($all) ? $all : [];
    }

    private function rateLimit(Request $request, string $key, int $max, int $windowSeconds): ?JsonResponse
    {
        if (!$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();
        $now = time();
        $bucket = $session->get('pwa_rate_' . $key, []);
        if (!is_array($bucket)) {
            $bucket = [];
        }

        $bucket = array_values(array_filter(
            $bucket,
            static fn ($timestamp): bool => is_numeric($timestamp) && (int) $timestamp > ($now - $windowSeconds)
        ));
        $bucket = array_map('intval', $bucket);

        if (count($bucket) >= $max) {
            $response = $this->json([
                'success' => false,
                'message' => 'Too many requests. Please try again shortly.',
            ], 429);
            $response->headers->set('Retry-After', (string) $windowSeconds);

            return $response;
        }

        $bucket[] = $now;
        $session->set('pwa_rate_' . $key, $bucket);

        return null;
    }
}
