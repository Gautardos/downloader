<?php

namespace App\Service\Pwa;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class MinishlinkPushTransport implements PushTransportInterface
{
    public function isAvailable(): bool
    {
        return class_exists(WebPush::class)
            && extension_loaded('openssl')
            && extension_loaded('curl')
            && extension_loaded('mbstring');
    }

    public function send(
        string $endpoint,
        string $p256dh,
        string $auth,
        string $payload,
        array $vapid,
        int $ttl
    ): array {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'status' => null,
                'expired' => false,
                'reason' => 'push transport unavailable',
            ];
        }

        try {
            $webPush = new WebPush(
                ['VAPID' => $vapid],
                ['TTL' => max(0, $ttl), 'urgency' => 'normal', 'batchSize' => 1],
                5
            );

            $subscription = Subscription::create([
                'endpoint' => $endpoint,
                'publicKey' => $p256dh,
                'authToken' => $auth,
                'contentEncoding' => 'aes128gcm',
            ]);

            $report = $webPush->sendOneNotification(
                $subscription,
                $payload,
                ['TTL' => max(0, $ttl), 'urgency' => 'normal']
            );

            $status = $report->getResponse()?->getStatusCode();

            return [
                'success' => $report->isSuccess(),
                'status' => $status,
                'expired' => $report->isSubscriptionExpired(),
                'reason' => $report->isSuccess() ? 'OK' : ($status !== null ? 'HTTP ' . $status : 'push failed'),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => null,
                'expired' => false,
                'reason' => 'push exception',
            ];
        }
    }
}
