<?php

namespace App\Service\Pwa;

interface PushNotifierInterface
{
    public function notify(string $event, string $title, string $body, string $url = '/'): bool;
}
