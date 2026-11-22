<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Controller\PwaController;
use App\EventSubscriber\CsrfSubscriber;
use App\Service\JsonStorage;
use App\Service\NavigationService;
use App\Service\Pwa\PushNotifier;
use App\Service\Pwa\PushNotifierInterface;
use App\Service\Pwa\PushSubscriptionStore;
use App\Service\Pwa\PushTransportInterface;
use App\Service\Pwa\VapidKeyService;
use App\Service\Pwa\WebPushService;
use App\Service\Pwa\WebPushServiceInterface;
use App\Service\ServerNotificationService;
use Minishlink\WebPush\VAPID;
use Symfony\Component\Process\Process;

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

function routeMethodIncludes(string $method, string $verb): bool
{
    $method = strtoupper($method);

    return $method === 'ANY' || preg_match('/\b' . preg_quote($verb, '/') . '\b/', $method) === 1;
}

function base64UrlDecode(string $value): string
{
    $value = strtr($value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    return (string) base64_decode($value, true);
}

final class PwaFakeTransport implements PushTransportInterface
{
    public array $calls = [];
    public array $expiredEndpoints = [];
    public bool $throw = false;

    public function isAvailable(): bool
    {
        return true;
    }

    public function send(string $endpoint, string $p256dh, string $auth, string $payload, array $vapid, int $ttl): array
    {
        $this->calls[] = [
            'endpoint' => $endpoint,
            'p256dh' => $p256dh,
            'auth' => $auth,
            'payload' => $payload,
            'vapid' => $vapid,
            'ttl' => $ttl,
        ];

        if ($this->throw) {
            throw new RuntimeException('transport down');
        }

        return [
            'success' => true,
            'status' => 201,
            'expired' => in_array($endpoint, $this->expiredEndpoints, true),
            'reason' => 'OK',
        ];
    }
}

final class PwaFakeWebPush implements WebPushServiceInterface
{
    public array $payloads = [];
    public bool $throw = false;
    public int $sent = 1;

    public function isConfigured(): bool
    {
        return true;
    }

    public function sendToAll(array $payload, ?int $ttl = null): array
    {
        if ($this->throw) {
            throw new RuntimeException('push down');
        }
        $this->payloads[] = $payload;

        return [
            'sent' => $this->sent,
            'failed' => 0,
            'removed' => 0,
            'skipped' => false,
            'results' => [],
        ];
    }

    public function sendToSubscription(array $subscription, array $payload, ?int $ttl = null): array
    {
        if ($this->throw) {
            throw new RuntimeException('push down');
        }
        $this->payloads[] = $payload;

        return ['sent' => true, 'success' => true, 'status' => 201, 'expired' => false, 'removed' => false, 'reason' => 'OK'];
    }
}

final class PwaFakeNotifier implements PushNotifierInterface
{
    public array $events = [];
    public bool $throw = false;

    public function notify(string $event, string $title, string $body, string $url = '/'): bool
    {
        $this->events[] = ['event' => $event, 'title' => $title, 'body' => $body, 'url' => $url];
        if ($this->throw) {
            throw new RuntimeException('push down');
        }

        return true;
    }
}

$project = dirname(__DIR__);
$sandbox = tempDir('pwa-smoke');

try {
    echo "Manifest and icons\n";
    $manifestPath = $project . '/public/manifest.webmanifest';
    check(is_file($manifestPath), 'manifest.webmanifest exists');
    $manifest = json_decode((string) @file_get_contents($manifestPath), true);
    check(is_array($manifest), 'manifest is valid JSON');
    foreach (['name', 'short_name', 'description', 'id', 'start_url', 'scope', 'display', 'background_color', 'theme_color'] as $field) {
        check(isset($manifest[$field]) && is_string($manifest[$field]) && $manifest[$field] !== '', 'manifest field: ' . $field);
    }
    eq('/', $manifest['id'] ?? null, 'manifest id is /');
    eq('/', $manifest['start_url'] ?? null, 'manifest start_url is /');
    eq('/', $manifest['scope'] ?? null, 'manifest scope is /');
    eq('standalone', $manifest['display'] ?? null, 'manifest display is standalone');
    check(is_array($manifest['icons'] ?? null) && count($manifest['icons']) >= 3, 'manifest declares icons');
    $iconSizes = [];
    $hasMaskable = false;
    foreach (($manifest['icons'] ?? []) as $icon) {
        $src = (string) ($icon['src'] ?? '');
        $size = (string) ($icon['sizes'] ?? '');
        $purpose = (string) ($icon['purpose'] ?? 'any');
        $file = $project . '/public' . $src;
        check(is_file($file), 'manifest icon exists: ' . $src);
        $info = @getimagesize($file);
        check(is_array($info), 'manifest icon is a valid image: ' . $src);
        if (is_array($info)) {
            $expected = array_map('intval', explode('x', $size));
            check(count($expected) === 2 && $info[0] === $expected[0] && $info[1] === $expected[1], 'manifest icon size matches: ' . $src);
        }
        $iconSizes[] = $size;
        if (str_contains($purpose, 'maskable')) {
            $hasMaskable = true;
        }
    }
    check(in_array('192x192', $iconSizes, true), 'manifest exposes a 192x192 icon');
    check(in_array('512x512', $iconSizes, true), 'manifest exposes a 512x512 icon');
    check($hasMaskable, 'manifest exposes a maskable icon');
    check(is_array($manifest['shortcuts'] ?? null) && count($manifest['shortcuts']) >= 4, 'manifest declares shortcuts');
    $shortcutUrls = array_map(static fn (array $shortcut): string => (string) ($shortcut['url'] ?? ''), $manifest['shortcuts'] ?? []);
    foreach (['/queue', '/media', '/', '/torrent'] as $shortcut) {
        check(in_array($shortcut, $shortcutUrls, true), 'manifest shortcut: ' . $shortcut);
    }

    echo "Offline page\n";
    $offlinePath = $project . '/public/offline.html';
    check(is_file($offlinePath), 'offline.html exists');
    $offline = (string) @file_get_contents($offlinePath);
    check(strlen($offline) > 200, 'offline.html is not empty');
    check(stripos($offline, 'You are offline') !== false, 'offline.html contains the offline message');
    check(stripos($offline, 'Retry') !== false, 'offline.html contains a retry action');
    check(stripos($offline, '_token') === false, 'offline.html contains no CSRF token');
    check(stripos($offline, 'server_notifications') === false, 'offline.html contains no user data');

    echo "Service worker\n";
    $swPath = $project . '/public/service-worker.js';
    check(is_file($swPath), 'service-worker.js exists');
    $sw = (string) @file_get_contents($swPath);
    foreach (["self.addEventListener('install'", "self.addEventListener('activate'", "self.addEventListener('fetch'", "self.addEventListener('push'", "self.addEventListener('notificationclick'"] as $marker) {
        check(str_contains($sw, $marker), 'service worker marker: ' . $marker);
    }
    check(str_contains($sw, 'skipWaiting()'), 'service worker calls skipWaiting');
    check(str_contains($sw, 'clients.claim()'), 'service worker calls clients.claim');
    check(str_contains($sw, 'offline.html'), 'service worker references offline.html');
    check(str_contains($sw, "request.mode === 'navigate'"), 'service worker uses network-first navigation');
    check(str_contains($sw, "request.method !== 'GET'"), 'service worker bypasses cache for non-GET');
    check(str_contains($sw, 'showNotification'), 'service worker displays push notifications');
    check(str_contains($sw, 'openWindow'), 'service worker opens a window on notification click');
    foreach (['/jellyfin', '/login', '/logout', '/pwa/', '/search/global', '/queue-status', '/notifications-poll'] as $path) {
        check(str_contains($sw, "'" . $path . "'"), 'service worker network-only path: ' . $path);
    }
    $precacheStart = strpos($sw, 'const PRECACHE_URLS = [');
    $precacheEnd = $precacheStart !== false ? strpos($sw, '];', $precacheStart) : false;
    check($precacheStart !== false && $precacheEnd !== false, 'service worker has a precache allowlist');
    $precache = ($precacheStart !== false && $precacheEnd !== false) ? substr($sw, $precacheStart, $precacheEnd - $precacheStart) : '';
    foreach (['/jellyfin', '/login', '/logout', '/pwa/', '/search/global', '/queue-status', '/notifications-poll'] as $path) {
        check(!str_contains($precache, "'" . $path . "'"), 'service worker does not precache: ' . $path);
    }
    check(str_contains($sw, 'ignoreSearch'), 'service worker revalidates static assets with cache matching');
    check(str_contains((string) @file_get_contents($project . '/public/.htaccess'), 'application/manifest+json'), '.htaccess declares the manifest MIME type');

    echo "VAPID keys\n";
    $vapidProject = tempDir('pwa-vapid');
    $vapid = new VapidKeyService($vapidProject);
    check(!$vapid->isConfigured(), 'VAPID is not configured before generation');
    $generated = $vapid->generate(false);
    check($generated['created'] === true, 'VAPID generation reports a new key pair');
    check($vapid->isConfigured(), 'VAPID is configured after generation');
    check(is_file($vapid->getPath()), 'VAPID key file exists');
    $mode = fileperms($vapid->getPath()) & 0777;
    eq(0600, $mode, 'VAPID key file is mode 0600');
    $vapidConfig = $vapid->getVapidConfig();
    check(is_array($vapidConfig), 'VAPID config is readable');
    eq(65, strlen(base64UrlDecode((string) ($vapidConfig['publicKey'] ?? ''))), 'VAPID public key is a 65-byte EC point');
    eq(32, strlen(base64UrlDecode((string) ($vapidConfig['privateKey'] ?? ''))), 'VAPID private key is 32 bytes');
    $validatedVapid = VAPID::validate($vapidConfig);
    eq(65, strlen($validatedVapid['publicKey']), 'minishlink accepts the stored public key');
    eq(32, strlen($validatedVapid['privateKey']), 'minishlink accepts the stored private key');
    eq($generated['publicKey'], $vapid->getPublicKey(), 'public key getter exposes only the public key');
    $again = $vapid->generate(false);
    check($again['created'] === false, 'generation does not overwrite existing keys');
    eq($generated['publicKey'], $again['publicKey'], 'existing public key is preserved');
    $forced = $vapid->generate(true);
    check($forced['created'] === true, 'forced generation creates a new pair');
    check($forced['publicKey'] !== $generated['publicKey'], 'forced generation rotates the public key');
    $controllerSource = (string) @file_get_contents($project . '/src/Controller/PwaController.php');
    check(!str_contains($controllerSource, 'private_key') && !str_contains($controllerSource, "'privateKey'"), 'controller never exposes the VAPID private key');

    $controller = new PwaController();
    $controller->setContainer(new class implements \Psr\Container\ContainerInterface {
        public function get(string $id): mixed
        {
            throw new RuntimeException('No service available in this test container.');
        }

        public function has(string $id): bool
        {
            return false;
        }
    });
    $publicKeyResponse = $controller->vapidPublicKey($vapid);
    $publicKeyPayload = json_decode((string) $publicKeyResponse->getContent(), true);
    check(is_array($publicKeyPayload), 'VAPID public key endpoint returns JSON');
    eq(true, $publicKeyPayload['configured'] ?? null, 'VAPID endpoint reports configured keys');
    eq($vapid->getPublicKey(), $publicKeyPayload['publicKey'] ?? null, 'VAPID endpoint returns the public key');
    check(!str_contains((string) $publicKeyResponse->getContent(), 'private_key'), 'VAPID endpoint JSON contains no private key');

    echo "Subscription endpoint validation\n";
    $normalize = new ReflectionMethod(PwaController::class, 'normalizeSubscription');
    $normalize->setAccessible(true);
    $validSubscription = [
        'endpoint' => 'https://fcm.googleapis.com/wp/abc123',
        'keys' => [
            'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
            'auth' => 'tBHItJI5svbpez7KI4CCXg',
        ],
    ];
    $normalized = $normalize->invoke($controller, $validSubscription);
    eq('https://fcm.googleapis.com/wp/abc123', $normalized['endpoint'] ?? null, 'allowlisted FCM endpoint is accepted');

    $windowsSubscription = $validSubscription;
    $windowsSubscription['endpoint'] = 'https://wns2-par02p.notify.windows.com/w/?token=abc';
    $normalize->invoke($controller, $windowsSubscription);
    check(true, 'allowlisted Windows push endpoint is accepted');

    foreach ([
        ['scheme' => 'http://fcm.googleapis.com/wp/abc123', 'label' => 'HTTP endpoint is rejected'],
        ['scheme' => 'https://evil.example/wp/abc123', 'label' => 'unknown push host is rejected'],
        ['scheme' => 'https://fcm.googleapis.com:8443/wp/abc123', 'label' => 'non-443 port is rejected'],
        ['scheme' => 'https://user:pass@fcm.googleapis.com/wp/abc123', 'label' => 'endpoint credentials are rejected'],
    ] as $case) {
        $bad = $validSubscription;
        $bad['endpoint'] = $case['scheme'];
        $rejected = false;
        try {
            $normalize->invoke($controller, $bad);
        } catch (InvalidArgumentException $e) {
            $rejected = true;
        }
        check($rejected, $case['label']);
    }

    $shortKeys = $validSubscription;
    $shortKeys['keys']['auth'] = 'short';
    $rejected = false;
    try {
        $normalize->invoke($controller, $shortKeys);
    } catch (InvalidArgumentException $e) {
        $rejected = true;
    }
    check($rejected, 'short subscription auth key is rejected');

    echo "Subscription store\n";
    $storeProject = tempDir('pwa-store');
    $store = new PushSubscriptionStore($storeProject, 50);
    $store->initialize();
    check(is_file($store->getDbPath()), 'pwa.db is created');
    eq(0600, fileperms($store->getDbPath()) & 0777, 'pwa.db is mode 0600');
    eq('wal', strtolower((string) $store->getPdo()->query('PRAGMA journal_mode')->fetchColumn()), 'pwa.db uses WAL journal mode');
    eq(0, $store->countSubscriptions(), 'store starts empty');

    $endpoint = 'https://fcm.googleapis.com/wp/abc123';
    $store->upsertSubscription($endpoint, 'p256dh-value-1234567890', 'auth-value-1234567890', 'Smoke UA');
    eq(1, $store->countSubscriptions(), 'subscription is inserted');
    $store->upsertSubscription($endpoint, 'p256dh-value-0987654321', 'auth-value-0987654321', 'Smoke UA 2');
    eq(1, $store->countSubscriptions(false), 'endpoint uniqueness is enforced by upsert');
    $stored = $store->getSubscription($endpoint);
    eq('p256dh-value-0987654321', $stored['p256dh'] ?? null, 'upsert refreshes the p256dh key');

    $defaultPreferences = $store->getPreferences();
    eq(true, $defaultPreferences['downloads'], 'downloads notifications default to enabled');
    eq(false, $defaultPreferences['daily_summary'], 'daily summary defaults to disabled');
    $savedPreferences = $store->savePreferences([
        'downloads' => false,
        'errors' => true,
        'automation' => false,
        'integrity' => false,
        'daily_summary' => true,
    ]);
    eq(false, $savedPreferences['downloads'], 'preferences persist downloads');
    eq(false, $savedPreferences['automation'], 'preferences persist automation');
    eq(true, $savedPreferences['daily_summary'], 'preferences persist daily summary');
    check(!$store->isEventEnabled('downloads'), 'disabled event is reported as disabled');
    check($store->isEventEnabled('errors'), 'enabled event is reported as enabled');

    eq(1, $store->removeExpired([$endpoint]), 'expired subscriptions are removed');
    eq(0, $store->countSubscriptions(), 'store is empty after expiry removal');

    $retryMethod = new ReflectionMethod(PushSubscriptionStore::class, 'withRetry');
    $retryMethod->setAccessible(true);
    $attempts = 0;
    $retryResult = $retryMethod->invokeArgs($store, [function () use (&$attempts) {
        $attempts++;
        if ($attempts < 3) {
            throw new PDOException('database is locked');
        }

        return 'retried';
    }]);
    eq('retried', $retryResult, 'SQLITE_BUSY retry eventually succeeds');
    eq(3, $attempts, 'SQLITE_BUSY retry uses multiple attempts');

    echo "WebPush service with fake transport\n";
    $pushProject = tempDir('pwa-push');
    $pushVapid = new VapidKeyService($pushProject);
    $pushVapid->generate(false);
    $pushStore = new PushSubscriptionStore($pushProject, 1000);
    $pushStore->initialize();
    $firstEndpoint = 'https://fcm.googleapis.com/wp/first';
    $secondEndpoint = 'https://updates.push.services.mozilla.com/wpush/v2/second';
    $pushStore->upsertSubscription($firstEndpoint, 'p256dh-first-1234567890', 'auth-first-1234567890');
    $pushStore->upsertSubscription($secondEndpoint, 'p256dh-second-1234567890', 'auth-second-1234567890');

    $transport = new PwaFakeTransport();
    $webPush = new WebPushService($transport, $pushVapid, $pushStore, null);
    $result = $webPush->sendToAll([
        'title' => 'Hello',
        'body' => 'World',
        'icon' => '/pwa-icons/icon-192.png',
        'url' => '/queue',
    ], 120);
    eq(2, $result['sent'], 'fake transport receives every subscription');
    eq(2, count($transport->calls), 'fake transport records two calls');
    eq(120, $transport->calls[0]['ttl'] ?? null, 'TTL is forwarded to the transport');
    $decodedPayload = json_decode((string) ($transport->calls[0]['payload'] ?? ''), true);
    eq('Hello', $decodedPayload['title'] ?? null, 'payload title is encoded');
    eq('/queue', $decodedPayload['url'] ?? null, 'payload url is encoded');
    check(!isset($decodedPayload['privateKey']) && !isset($decodedPayload['endpoint']), 'payload contains no secrets or endpoint');

    $transport->expiredEndpoints = [$firstEndpoint];
    $expiredResult = $webPush->sendToAll(['title' => 'Expiry', 'body' => 'Check'], 60);
    eq(1, $expiredResult['removed'], '404/410 subscriptions are removed');
    eq(1, $pushStore->countSubscriptions(true), 'one live subscription remains');

    $transport->throw = true;
    $failedResult = $webPush->sendToAll(['title' => 'Failure', 'body' => 'Absorbed'], 60);
    eq(0, $failedResult['sent'], 'transport exceptions do not propagate');
    eq(1, $failedResult['failed'], 'transport exceptions are counted as failures');

    echo "Push notifier preferences and failures\n";
    $notifierProject = tempDir('pwa-notifier');
    $notifierStore = new PushSubscriptionStore($notifierProject, 1000);
    $notifierStore->initialize();
    $notifierStore->savePreferences(['downloads' => false, 'errors' => true, 'automation' => true, 'integrity' => true, 'daily_summary' => true]);
    $fakeWebPush = new PwaFakeWebPush();
    $notifier = new PushNotifier($fakeWebPush, $notifierStore, null);
    check(!$notifier->notify('downloads', 'Title', 'Body', '/queue'), 'disabled preference prevents a push');
    eq(0, count($fakeWebPush->payloads), 'fake push is not called for a disabled preference');

    $notifierStore->savePreferences(['downloads' => true]);
    check($notifier->notify('downloads', 'Title', 'Visit https://secret.example/token?x=1', '/queue'), 'enabled preference sends a push');
    eq(1, count($fakeWebPush->payloads), 'fake push is called once');
    $notifierPayload = $fakeWebPush->payloads[0] ?? [];
    eq('/queue', $notifierPayload['url'] ?? null, 'notifier keeps an internal URL');
    eq('/pwa-icons/icon-192.png', $notifierPayload['icon'] ?? null, 'notifier uses the local icon');
    check(!str_contains((string) ($notifierPayload['body'] ?? ''), 'secret.example'), 'notifier redacts external URLs');
    check(str_contains((string) ($notifierPayload['body'] ?? ''), '[link]'), 'notifier marks redacted URLs');
    check(!$notifier->notify('unknown_event', 'Title', 'Body'), 'unknown event is rejected');

    $fakeWebPush->throw = true;
    check(!$notifier->notify('errors', 'Title', 'Body', '/history'), 'push exception is absorbed by the notifier');

    echo "ServerNotificationService integration\n";
    $notificationStorage = storageAt($sandbox . '/notifications', ['api_key' => 'smoke']);
    $fakeNotifier = new PwaFakeNotifier();
    $notifications = new ServerNotificationService($fakeNotifier);
    $notifications->push($notificationStorage, [
        'type' => 'error',
        'message' => 'Download failed',
        'action' => 'Finished',
        'item' => 'Movie',
    ]);
    eq(1, count($notificationStorage->get('server_notifications', [])), 'notification is still stored with push enabled');
    eq(1, count($fakeNotifier->events), 'push notifier receives the notification');
    eq('errors', $fakeNotifier->events[0]['event'] ?? null, 'error notifications map to the errors preference');
    eq('/history', $fakeNotifier->events[0]['url'] ?? null, 'error notifications open the history page');

    $fakeNotifier->throw = true;
    $notifications->push($notificationStorage, ['type' => 'info', 'message' => 'Queued', 'action' => 'Started']);
    eq(2, count($notificationStorage->get('server_notifications', [])), 'notification persistence is not blocked by push errors');

    $plainNotifications = new ServerNotificationService();
    $plainNotifications->push($notificationStorage, ['type' => 'info', 'message' => 'Plain']);
    eq(3, count($notificationStorage->get('server_notifications', [])), 'ServerNotificationService still works without push configured');

    echo "Routes and security wiring\n";
    $routes = routerInventory();
    check($routes !== [], 'debug:router inventory is readable');
    $expectedRoutes = [
        'pwa_vapid_public_key' => ['/pwa/vapid-public-key', 'GET'],
        'pwa_status' => ['/pwa/status', 'GET'],
        'pwa_subscribe' => ['/pwa/subscribe', 'POST'],
        'pwa_unsubscribe' => ['/pwa/unsubscribe', 'POST'],
        'pwa_test' => ['/pwa/test', 'POST'],
        'pwa_preferences' => ['/pwa/preferences', 'POST'],
    ];
    foreach ($expectedRoutes as $name => [$path, $verb]) {
        check(isset($routes[$name]), 'route exists: ' . $name);
        if (!isset($routes[$name])) {
            continue;
        }
        eq($path, $routes[$name]['path'] ?? null, 'route path: ' . $name);
        check(routeMethodIncludes((string) ($routes[$name]['method'] ?? ''), $verb), 'route method ' . $verb . ': ' . $name);
        check(!CsrfSubscriber::isExemptPath($path), 'CSRF applies to ' . $name);
        check($name !== 'login' && !str_starts_with($name, 'jellyfin_') && !str_starts_with($path, '/jellyfin'), 'auth applies to ' . $name);
    }

    echo "Navigation non-regression\n";
    $navStorage = storageAt($sandbox . '/navigation', ['api_key' => 'smoke', 'default_path' => '/tmp']);
    $navigation = (new NavigationService($navStorage))->getNavigation('config');
    eq(5, count($navigation), 'navigation still exposes five top-level entries');
    eq(['Music', 'Video', 'Debrid', 'Downloads', 'Settings'], array_column($navigation, 'label'), 'navigation labels are unchanged');
    $pwaInNavigation = false;
    foreach ($navigation as $group) {
        if (str_contains(strtolower((string) ($group['label'] ?? '')), 'pwa')) {
            $pwaInNavigation = true;
        }
        foreach (($group['items'] ?? []) as $item) {
            if (str_contains(strtolower((string) ($item['label'] ?? '')), 'pwa') || str_contains((string) ($item['route'] ?? ''), 'pwa')) {
                $pwaInNavigation = true;
            }
        }
    }
    check(!$pwaInNavigation, 'PWA adds no navigation entry');
} finally {
    removeTree($sandbox);
    removeTree($vapidProject ?? '');
    removeTree($storeProject ?? '');
    removeTree($pushProject ?? '');
    removeTree($notifierProject ?? '');
}

echo "\n$passes passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
