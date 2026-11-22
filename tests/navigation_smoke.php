<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\JsonStorage;
use App\Service\MediaTypeHelper;
use App\Service\NavigationService;
use Symfony\Component\Process\Process;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

$passes = 0;
$failures = 0;

function check(bool $condition, string $label, mixed $actual = null): void
{
    global $passes, $failures;
    if ($condition) {
        $passes++;
        echo "  ok  $label\n";
        return;
    }
    $failures++;
    echo "  FAIL $label" . ($actual !== null ? ' (actual: ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ')' : '') . "\n";
}

function eq(mixed $expected, mixed $actual, string $label): void
{
    check($expected === $actual, $label . ' => ' . json_encode($expected, JSON_UNESCAPED_UNICODE), $actual);
}

function tempDir(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    return $dir;
}

function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dir);
}

function storageAt(string $dir, array $config = []): JsonStorage
{
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    if ($config !== []) {
        file_put_contents($dir . '/config.json', json_encode($config));
    }

    $storage = (new ReflectionClass(JsonStorage::class))->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(JsonStorage::class, 'storageDir');
    $property->setAccessible(true);
    $property->setValue($storage, $dir);

    return $storage;
}

function routerInventory(): array
{
    $process = new Process([PHP_BINARY, 'bin/console', 'debug:router', '--format=json'], dirname(__DIR__));
    $process->setTimeout(60);
    $process->run();
    if (!$process->isSuccessful()) {
        return [];
    }
    $routes = json_decode($process->getOutput(), true);

    return is_array($routes) ? $routes : [];
}

function findGroup(array $groups, string $key): ?array
{
    foreach ($groups as $group) {
        if ($group['key'] === $key) {
            return $group;
        }
    }

    return null;
}

function findItem(array $groups, string $key): ?array
{
    foreach ($groups as $group) {
        foreach ($group['items'] as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }
    }

    return null;
}

function activeEntries(array $groups): array
{
    $active = [];
    foreach ($groups as $group) {
        if ($group['type'] === 'link' && $group['active']) {
            $active[] = $group['key'];
        }
        foreach ($group['items'] as $item) {
            if ($item['active']) {
                $active[] = $item['key'];
            }
        }
    }

    return $active;
}

function routeMethodIncludesGet(string $method): bool
{
    $method = strtoupper($method);

    return $method === 'ANY' || preg_match('/\b(GET|HEAD)\b/', $method) === 1;
}

$project = dirname(__DIR__);
$sandbox = tempDir('navigation-smoke');

try {
    $storage = storageAt($sandbox . '/configured', [
        'api_key' => 'smoke-key',
        'default_path' => '/tmp',
    ]);
    $navigation = new NavigationService($storage);
    $groups = $navigation->getNavigation('music');

    echo "Navigation structure\n";
    eq(5, count($groups), 'five top-level entries are exposed');
    eq(['Music', 'Video', 'Debrid', 'Downloads', 'Settings'], array_column($groups, 'label'), 'top-level labels and order');
    eq([true, true, true, true, true], array_map(static fn (array $group): bool => $group['topLevel'], $groups), 'all top-level entries are flagged topLevel');

    $debrid = findGroup($groups, 'debrid');
    check($debrid !== null, 'Debrid entry exists');
    if ($debrid !== null) {
        eq('torrent', $debrid['route'], 'Debrid points to the torrent route');
        check($debrid['topLevel'], 'Debrid is top-level');
        check($debrid['enabled'], 'Debrid is enabled');
        eq('link', $debrid['type'], 'Debrid is a direct link, not a group');
        eq([], $debrid['items'], 'Debrid has no nested items');
    }

    $video = findGroup($groups, 'video');
    check($video !== null, 'Video group exists');
    if ($video !== null) {
        check(!in_array('debrid', array_column($video['items'], 'key'), true), 'Debrid is not nested in Video');
    }

    foreach ($groups as $group) {
        foreach ($group['items'] as $item) {
            check($item['topLevel'] === false, 'item ' . $item['key'] . ' is not top-level');
        }
    }

    echo "Navigation icons\n";
    $missingIcons = [];
    foreach ($groups as $group) {
        foreach ($group['items'] as $item) {
            $icon = (string) ($item['icon'] ?? '');
            if ($icon === '' || !str_contains($icon, '<svg') || !str_contains($icon, 'aria-hidden="true"')) {
                $missingIcons[] = $item['key'];
            }
        }
    }
    check($missingIcons === [], 'every item has a non-empty decorative SVG icon', $missingIcons);
    check(($debrid['icon'] ?? '') !== '' && str_contains($debrid['icon'], '<svg'), 'Debrid entry has a decorative SVG icon');

    echo "Disabled entries\n";
    $disabledKeys = [];
    foreach ($disabledKeys as $key) {
        $item = findItem($groups, $key);
        check($item !== null, 'disabled entry ' . $key . ' exists');
        if ($item === null) {
            continue;
        }
        eq(false, $item['enabled'], $key . ' is disabled');
        eq(null, $item['route'], $key . ' has no route/href');
    }
    $musicAutomations = findItem($groups, 'music_automations');
    check($musicAutomations !== null, 'music automations entry exists');
    if ($musicAutomations !== null) {
        check($musicAutomations['enabled'], 'music automations entry is enabled');
        eq('music_automations', $musicAutomations['route'], 'music automations entry points to its route');
    }

    echo "Active state mapping\n";
    $activeCases = [
        'music' => 'music_download',
        'music_add' => 'music_download',
        'spotify_search' => 'music_download',
        'music_automations' => 'music_automations',
        'music_automations_search' => 'music_automations',
        'music_automations_save' => 'music_automations',
        'music_automations_run' => 'music_automations',
        'music_library' => 'music_library',
        'music_files' => 'music_library',
        'music_stream' => 'music_library',
        'music_update_genres' => 'music_library',
        'fetch_lyrics' => 'music_library',
        'move_to_library' => 'music_library',
        'file_tags' => 'music_library',
        'update_tags' => 'music_library',
        'media_index' => 'video_discover',
        'media_search' => 'video_discover',
        'automation' => 'video_automations',
        'automation_library' => 'video_automations',
        'video_integrity' => 'video_integrity',
        'video_integrity_check' => 'video_integrity',
        'video_integrity_requeue' => 'video_integrity',
        'video_integrity_status' => 'video_integrity',
        'video_integrity_settings' => 'video_integrity',
        'torrent' => 'debrid',
        'debrid' => 'debrid',
        'debrid_recent' => 'debrid',
        'torrent_db_search' => 'debrid',
        'queue' => 'queue',
        'queue_status' => 'queue',
        'queue_active_log' => 'queue',
        'progress' => 'queue',
        'history' => 'history',
        'history_log' => 'history',
        'statistics' => 'statistics',
        'statistics_export' => 'statistics',
        'config' => 'configuration',
        'config_export' => 'configuration',
        'maintenance' => 'maintenance',
        'maintenance_settings' => 'maintenance',
        'maintenance_run' => 'maintenance',
        'maintenance_status' => 'maintenance',
    ];

    foreach ($activeCases as $route => $expected) {
        $routeGroups = $navigation->getNavigation($route);
        $active = activeEntries($routeGroups);
        eq([$expected], $active, 'route ' . $route . ' activates ' . $expected);
    }

    eq([], activeEntries($navigation->getNavigation('logs')), 'unknown route keeps every entry inactive');

    echo "Route inventory\n";
    $routes = routerInventory();
    check($routes !== [], 'debug:router inventory is readable');

    $missingRoutes = [];
    foreach ($groups as $group) {
        if ($group['enabled'] && $group['route'] !== null && !isset($routes[$group['route']])) {
            $missingRoutes[] = $group['route'];
        }
        foreach ($group['items'] as $item) {
            if ($item['enabled'] && $item['route'] !== null && !isset($routes[$item['route']])) {
                $missingRoutes[] = $item['route'];
            }
        }
    }
    check($missingRoutes === [], 'every enabled navigation route exists', $missingRoutes);

    foreach (['music', 'music_library', 'music_files', 'torrent', 'media_index', 'automation', 'video_integrity', 'queue', 'history', 'config'] as $routeName) {
        check(isset($routes[$routeName]), 'existing route ' . $routeName . ' was not removed', array_keys($routes));
    }
    check(isset($routes['search_global']), 'global search route is registered');
    if (isset($routes['search_global'])) {
        eq('/search/global', $routes['search_global']['path'] ?? null, 'global search route path is /search/global');
        check(routeMethodIncludesGet((string) ($routes['search_global']['method'] ?? '')), 'global search route accepts GET');
    }

    check(isset($routes['debrid']), 'debrid redirect route is registered');
    if (isset($routes['debrid'])) {
        eq('/debrid', $routes['debrid']['path'] ?? null, 'debrid redirect path is /debrid');
        check(routeMethodIncludesGet((string) ($routes['debrid']['method'] ?? '')), 'debrid redirect accepts GET');
        check(str_contains((string) ($routes['debrid']['defaults']['_controller'] ?? ''), '::debrid'), 'debrid route maps to the controller method');
    }
    check(isset($routes['torrent']), 'torrent route still exists');

    echo "Navigation partial\n";
    $partialPath = $project . '/templates/_partials/navigation.html.twig';
    check(is_file($partialPath), 'navigation partial exists');
    $template = (string) file_get_contents($partialPath);

    $twig = new Environment(new ArrayLoader(['navigation.html.twig' => $template]), ['strict_variables' => false]);
    $twig->addFunction(new TwigFunction('path', static function (string $name, array $parameters = []): string {
        $paths = [
            'music' => '/',
            'music_automations' => '/music/automations',
            'music_library' => '/music-library',
            'media_index' => '/media',
            'automation' => '/automation',
            'video_integrity' => '/video/integrity',
            'torrent' => '/torrent',
            'queue' => '/queue',
            'history' => '/history',
            'statistics' => '/statistics',
            'config' => '/config',
            'logout' => '/logout',
        ];

        return $paths[$name] ?? '/' . $name;
    }));

    $html = $twig->render('navigation.html.twig', [
        'navigation' => $navigation->getNavigation('media_index'),
        'csrf_token' => 'smoke-token',
    ]);

    eq(5, substr_count($html, 'data-nav-key='), 'partial renders five top-level entries');
    eq(4, substr_count($html, 'aria-haspopup="true"'), 'four group buttons expose aria-haspopup');
    check(substr_count($html, 'aria-expanded="false"') >= 4, 'group buttons expose aria-expanded');
    eq(1, substr_count($html, 'id="queue-nav-dot"'), 'queue activity dot is present exactly once');
    check(str_contains($html, 'name="_token"') && str_contains($html, 'value="smoke-token"'), 'logout form keeps the CSRF token');
    check(str_contains($html, '>Logout<'), 'logout button is kept');
    eq(0, substr_count($html, 'aria-disabled="true"'), 'no navigation entry is disabled');

    eq(1, substr_count($html, 'data-global-search-open'), 'the global search trigger is present exactly once');
    check(str_contains($html, 'aria-label="Open global search"'), 'the global search trigger has an accessible label');
    check(str_contains($html, 'aria-haspopup="dialog"'), 'the global search trigger advertises a dialog');
    check(str_contains($html, 'aria-keyshortcuts="Control+K Meta+K"'), 'the global search trigger exposes keyboard shortcuts');
    check(str_contains($html, '⌘K'), 'the global search trigger shows the shortcut hint');
    check(str_contains($html, '>Search<'), 'the global search trigger keeps its visible label');
    eq(5, substr_count($html, 'data-nav-key='), 'the search trigger does not alter the five navigation entries');

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $topLevelLabels = [];
    $topLevelNodes = $xpath->query('//*[@id="nav-links"]/*[@data-nav-key]');
    if ($topLevelNodes !== false) {
        foreach ($topLevelNodes as $node) {
            if (strtolower($node->nodeName) === 'a') {
                $topLevelLabels[] = trim($node->textContent);
                continue;
            }
            $button = $xpath->query('.//button[@data-nav-toggle]', $node)->item(0);
            $topLevelLabels[] = $button !== null ? trim(preg_replace('/\s+/', ' ', $button->textContent) ?? '') : '';
        }
    }
    eq(['Music', 'Video', 'Debrid', 'Downloads', 'Settings'], $topLevelLabels, 'partial renders the five labels in order');

    $expectedIconBoxes = 1;
    foreach ($groups as $group) {
        $expectedIconBoxes += count($group['items']);
    }
    $iconNodes = $xpath->query('//span[@class="mi"]');
    eq($expectedIconBoxes, $iconNodes === false ? 0 : $iconNodes->length, 'every menu item and Debrid render an icon box');
    $iconProblems = [];
    if ($iconNodes !== false) {
        foreach ($iconNodes as $iconNode) {
            if ($iconNode->getAttribute('aria-hidden') !== 'true') {
                $iconProblems[] = 'span-not-hidden';
            }
            $svg = $xpath->query('.//svg', $iconNode)->item(0);
            if ($svg === null) {
                $iconProblems[] = 'svg-missing';
            } elseif ($svg->getAttribute('aria-hidden') !== 'true') {
                $iconProblems[] = 'svg-not-hidden';
            }
        }
    }
    check($iconProblems === [], 'icons are decorative SVGs with aria-hidden', $iconProblems);

    $labelProblems = [];
    foreach ($groups as $group) {
        foreach ($group['items'] as $item) {
            $node = $xpath->query('//*[@data-nav-item="' . $item['key'] . '"]')->item(0);
            if ($node === null || !str_contains($node->textContent, $item['label'])) {
                $labelProblems[] = $item['key'];
            }
        }
    }
    check($labelProblems === [], 'item labels remain accessible', $labelProblems);

    $disabledNodes = $xpath->query('//*[@data-nav-item and @aria-disabled="true"]');
    check($disabledNodes !== false && $disabledNodes->length === 0, 'no disabled menu item is rendered');
    $disabledWithoutHref = [];
    $disabledLabels = [];
    if ($disabledNodes !== false) {
        foreach ($disabledNodes as $node) {
            $disabledLabels[] = $node->getAttribute('data-nav-item');
            if (strtolower($node->nodeName) === 'a' || $node->hasAttribute('href')) {
                $disabledWithoutHref[] = $node->getAttribute('data-nav-item');
            }
            check(str_contains($node->textContent, 'Soon'), 'disabled item ' . $node->getAttribute('data-nav-item') . ' shows a Soon badge');
        }
    }
    sort($disabledLabels);
    $expectedDisabled = $disabledKeys;
    sort($expectedDisabled);
    eq($expectedDisabled, $disabledLabels, 'disabled menu items are the expected ones');
    eq([], $disabledWithoutHref, 'disabled items have no href');

    $debridNode = $xpath->query('//*[@data-nav-key="debrid"]')->item(0);
    check($debridNode !== null, 'Debrid is rendered in the partial');
    if ($debridNode !== null) {
        eq('a', strtolower($debridNode->nodeName), 'Debrid is rendered as an anchor');
        eq('/torrent', $debridNode->getAttribute('href'), 'Debrid anchor points to torrent');
        check(!$debridNode->hasAttribute('aria-haspopup'), 'Debrid is not a dropdown');
        check(str_contains($debridNode->textContent, 'Debrid'), 'Debrid label remains accessible');
    }

    $videoNode = $xpath->query('//*[@data-nav-key="video"]')->item(0);
    check($videoNode !== null, 'Video group is rendered');
    if ($videoNode !== null && $debridNode !== null) {
        $nested = $xpath->query('.//*[@data-nav-key="debrid"]', $videoNode);
        eq(0, $nested === false ? 0 : $nested->length, 'Debrid is not nested inside the Video group');
    }

    $discoverNode = $xpath->query('//*[@data-nav-item="video_discover"]')->item(0);
    check($discoverNode !== null, 'Discover item is rendered');
    if ($discoverNode !== null) {
        check(str_contains($discoverNode->getAttribute('class'), 'active'), 'Discover item is marked active on media_index');
    }

    echo "Unconfigured navigation\n";
    $emptyStorage = storageAt($sandbox . '/empty');
    $emptyNavigation = new NavigationService($emptyStorage);
    $setup = $emptyNavigation->getNavigation('config');
    eq(1, count($setup), 'only Setup is visible when the app is not configured');
    eq('Setup', $setup[0]['label'] ?? null, 'the unconfigured entry is labelled Setup');
    eq('config', $setup[0]['route'] ?? null, 'Setup points to the config route');
    check(($setup[0]['icon'] ?? '') !== '' && str_contains((string) $setup[0]['icon'], '<svg'), 'Setup entry has a decorative SVG icon');
    check($setup[0]['active'] ?? false, 'Setup is active on the config route');
    check(($emptyNavigation->getNavigation('music')[0]['active'] ?? true) === false, 'Setup is inactive on other routes');

    echo "Media type coverage\n";
    $mediaTypes = new MediaTypeHelper();
    eq(MediaTypeHelper::TYPE_AUDIO, $mediaTypes->getType('track.mp3'), 'mp3 is detected as audio');
    eq(MediaTypeHelper::TYPE_EBOOK, $mediaTypes->getType('book.epub'), 'epub is detected as ebook');
    eq(MediaTypeHelper::TYPE_ARCHIVE, $mediaTypes->getType('archive.zip'), 'zip is detected as archive');
    eq(MediaTypeHelper::TYPE_VIDEO, $mediaTypes->getType('movie.mkv'), 'mkv is detected as video');
    eq(MediaTypeHelper::TYPE_OTHER, $mediaTypes->getType('payload.unknown'), 'unknown extensions fall back to other');
} finally {
    removeTree($sandbox);
}

echo "\n$passes passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
