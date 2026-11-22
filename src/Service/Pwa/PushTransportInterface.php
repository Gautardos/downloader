<?php

namespace App\Service\Pwa;

interface PushTransportInterface
{
    public function isAvailable(): bool;

    /**
     * @param array{subject:string,publicKey:string,privateKey:string} $vapid
     * @return array{success:bool,status:?int,expired:bool,reason:string}
     */
    public function send(
        string $endpoint,
        string $p256dh,
        string $auth,
        string $payload,
        array $vapid,
        int $ttl
    ): array;
}
