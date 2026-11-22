<?php

namespace App\Service\Pwa;

interface WebPushServiceInterface
{
    public function isConfigured(): bool;

    /**
     * @param array<string,mixed> $payload
     * @return array{sent:int,failed:int,removed:int,skipped:bool,results:array<int,array<string,mixed>>}
     */
    public function sendToAll(array $payload, ?int $ttl = null): array;

    /**
     * @param array<string,mixed> $subscription
     * @param array<string,mixed> $payload
     * @return array{sent:bool,success:bool,status:?int,expired:bool,removed:bool,reason:string}
     */
    public function sendToSubscription(array $subscription, array $payload, ?int $ttl = null): array;
}
