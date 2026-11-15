<?php

namespace App\Service\Automation;

class ListSourceRegistry
{
    /** @var array<string, ListSourceClientInterface> */
    private array $clients = [];

    public function __construct(ScrobListClient $scrob, MdbListClient $mdblist)
    {
        $this->register($scrob);
        $this->register($mdblist);
    }

    public function register(ListSourceClientInterface $client): void
    {
        $this->clients[$client->provider()] = $client;
    }

    public function get(string $provider): ?ListSourceClientInterface
    {
        return $this->clients[$provider] ?? null;
    }

    public function providers(): array
    {
        return array_keys($this->clients);
    }
}
