<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class SessionIdleSubscriber implements EventSubscriberInterface
{
    private const READ_ONLY_ROUTES = [
        'notifications_poll',
        'queue_status',
        'queue_active_log',
        'progress',
        'debrid_recent',
        'torrent_db_categories',
        'torrent_db_search',
        'torrent_db_description',
        'torrent_db_preview',
        'music_stream',
        'media_search',
        'media_details',
        'media_season',
        'media_streams',
        'media_addons_validate',
        'media_addons_save',
        'media_settings',
        'media_debrid',
        'media_catalog_items',
        'media_collections',
        'media_collections_import',
        'media_collections_delete',
        'media_collections_folder_delete',
        'media_collections_clear',
        'media_resolve_item',
        'automation_status',
        'automation_lists_status',
        'automation_downloads_status',
        'automation_downloads_dry_run_status',
        'automation_library_title_refresh_status',
        'automation_lists_test',
        'check_files',
        'debug_storage',
        'debug_api',
        'debug_grouping'
    ];

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        if (in_array($route, self::READ_ONLY_ROUTES, true) || str_starts_with((string) $route, 'jellyfin_')) {
            if ($request->hasSession() && $request->getSession()->isStarted()) {
                $request->getSession()->save(); // Writes and closes the session lock
            }
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }
}
