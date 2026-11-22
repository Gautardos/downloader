<?php

namespace App\Service\Search\Provider;

use App\Service\Search\SearchProviderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ActionSearchProvider implements SearchProviderInterface
{
    private const ACTIONS = [
        [
            'key' => 'update_torrent_db',
            'title' => 'Update torrent DB',
            'subtitle' => 'Maintenance · refresh the local torrent index',
            'route' => 'maintenance',
            'keywords' => ['update', 'torrent', 'database', 'db', 'refresh', 'index', 'maintenance'],
        ],
        [
            'key' => 'check_incomplete_downloads',
            'title' => 'Check incomplete downloads',
            'subtitle' => 'Integrity · verify missing or broken video files',
            'route' => 'video_integrity',
            'keywords' => ['check', 'incomplete', 'download', 'downloads', 'integrity', 'missing', 'video', 'verify'],
        ],
        [
            'key' => 'open_statistics',
            'title' => 'Open statistics',
            'subtitle' => 'Storage, success rate and top sources',
            'route' => 'statistics',
            'keywords' => ['open', 'statistics', 'stats', 'storage', 'usage', 'report'],
        ],
        [
            'key' => 'run_automation',
            'title' => 'Run automation',
            'subtitle' => 'Library automation · scan, resolve and queue items',
            'route' => 'automation',
            'keywords' => ['run', 'automation', 'scan', 'resolve', 'library', 'queue'],
        ],
        [
            'key' => 'open_settings',
            'title' => 'Open settings',
            'subtitle' => 'Configuration · API keys, paths and integrations',
            'route' => 'config',
            'keywords' => ['open', 'settings', 'config', 'configuration', 'api', 'keys', 'paths'],
        ],
    ];

    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function key(): string
    {
        return 'actions';
    }

    public function label(): string
    {
        return 'Quick actions';
    }

    public function icon(): string
    {
        return 'action';
    }

    public function search(string $query, int $limit): array
    {
        $needle = mb_strtolower(trim($query), 'UTF-8');
        $results = [];

        foreach (self::ACTIONS as $action) {
            $haystack = mb_strtolower(implode(' ', array_merge(
                [$action['title'], $action['subtitle']],
                $action['keywords']
            )), 'UTF-8');

            if ($needle !== '' && !str_contains($haystack, $needle)) {
                continue;
            }

            $results[] = [
                'id' => 'action-' . $action['key'],
                'title' => $action['title'],
                'subtitle' => $action['subtitle'],
                'meta' => 'Action',
                'icon' => $this->icon(),
                'url' => $this->urls->generate($action['route']),
                'source' => $this->key(),
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }
}
