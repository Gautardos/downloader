<?php

namespace App\Service\Pwa;

use Psr\Log\LoggerInterface;

class WebPushService implements WebPushServiceInterface
{
    private const DEFAULT_TTL = 3600;

    public function __construct(
        private PushTransportInterface $transport,
        private VapidKeyService $vapid,
        private PushSubscriptionStore $store,
        private ?LoggerInterface $logger = null
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->transport->isAvailable() && $this->vapid->isConfigured();
    }

    public function sendToAll(array $payload, ?int $ttl = null): array
    {
        $result = [
            'sent' => 0,
            'failed' => 0,
            'removed' => 0,
            'skipped' => false,
            'results' => [],
        ];

        if (!$this->isConfigured()) {
            $result['skipped'] = true;

            return $result;
        }

        $subscriptions = $this->store->getSubscriptions(true);
        if ($subscriptions === []) {
            $result['skipped'] = true;

            return $result;
        }

        foreach ($subscriptions as $subscription) {
            $delivery = $this->sendToSubscription($subscription, $payload, $ttl);
            if ($delivery['success']) {
                $result['sent']++;
            } else {
                $result['failed']++;
            }
            if ($delivery['removed']) {
                $result['removed']++;
            }
            $result['results'][] = [
                'success' => $delivery['success'],
                'status' => $delivery['status'],
                'expired' => $delivery['expired'],
                'removed' => $delivery['removed'],
            ];
        }

        return $result;
    }

    public function sendToSubscription(array $subscription, array $payload, ?int $ttl = null): array
    {
        $result = [
            'sent' => false,
            'success' => false,
            'status' => null,
            'expired' => false,
            'removed' => false,
            'reason' => 'not configured',
        ];

        if (!$this->isConfigured()) {
            return $result;
        }

        $endpoint = isset($subscription['endpoint']) && is_string($subscription['endpoint']) ? $subscription['endpoint'] : '';
        $p256dh = isset($subscription['p256dh']) && is_string($subscription['p256dh']) ? $subscription['p256dh'] : '';
        $auth = isset($subscription['auth']) && is_string($subscription['auth']) ? $subscription['auth'] : '';
        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            $result['reason'] = 'invalid subscription';

            return $result;
        }

        $vapid = $this->vapid->getVapidConfig();
        if ($vapid === null) {
            return $result;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $result['reason'] = 'invalid payload';

            return $result;
        }

        $ttl = $ttl ?? self::DEFAULT_TTL;

        try {
            $transportResult = $this->transport->send($endpoint, $p256dh, $auth, $json, $vapid, max(0, $ttl));
        } catch (\Throwable $e) {
            $transportResult = [
                'success' => false,
                'status' => null,
                'expired' => false,
                'reason' => 'push exception',
            ];
            $this->logFailure($transportResult);
        }

        $result['success'] = (bool) ($transportResult['success'] ?? false);
        $result['sent'] = $result['success'];
        $result['status'] = isset($transportResult['status']) ? (int) $transportResult['status'] : null;
        $result['expired'] = (bool) ($transportResult['expired'] ?? false);
        $result['reason'] = (string) ($transportResult['reason'] ?? ($result['success'] ? 'OK' : 'push failed'));

        try {
            if ($result['expired']) {
                $result['removed'] = $this->store->removeExpired([$endpoint]) > 0;
            } elseif ($result['success']) {
                $this->store->touchSubscription($endpoint);
            }
        } catch (\Throwable $e) {
            $result['reason'] = $result['reason'] === 'OK' ? 'subscription store error' : $result['reason'];
        }

        if (!$result['success']) {
            $this->logFailure($result);
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $result
     */
    private function logFailure(array $result): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->warning('Web push delivery failed', [
            'status' => $result['status'] ?? null,
            'expired' => (bool) ($result['expired'] ?? false),
            'reason' => (string) ($result['reason'] ?? 'unknown'),
        ]);
    }
}
