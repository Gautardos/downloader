<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\EventSubscriber\AuthSubscriber;
use App\EventSubscriber\JellyfinAuthSubscriber;
use App\Service\AuthService;
use App\Service\JsonStorage;
use App\Service\Jellyfin\JellyfinAuthService;
use App\Service\PasswordService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

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

function storageAt(string $dir): JsonStorage
{
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $storage = (new ReflectionClass(JsonStorage::class))->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(JsonStorage::class, 'storageDir');
    $property->setAccessible(true);
    $property->setValue($storage, $dir);

    return $storage;
}

final class AuthSmokeKernel implements HttpKernelInterface
{
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response();
    }
}

final class AuthSmokeRouter implements RouterInterface
{
    private RequestContext $context;

    public function __construct()
    {
        $this->context = new RequestContext();
    }

    public function setContext(RequestContext $context): void
    {
        $this->context = $context;
    }

    public function getContext(): RequestContext
    {
        return $this->context;
    }

    public function getRouteCollection(): RouteCollection
    {
        return new RouteCollection();
    }

    public function match(string $pathinfo): array
    {
        throw new \Symfony\Component\Routing\Exception\ResourceNotFoundException($pathinfo);
    }

    public function generate(string $name, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        return $name === 'login' ? '/login' : '';
    }
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

function routeMethodIncludesGet(string $method): bool
{
    $method = strtoupper($method);

    return $method === 'ANY' || preg_match('/\b(GET|HEAD)\b/', $method) === 1;
}

function routeIsAuthExempt(string $name, string $path): bool
{
    return $name === 'login'
        || str_starts_with($name, '_')
        || str_starts_with($name, 'jellyfin_')
        || str_starts_with($path, '/jellyfin');
}

function concretePath(string $path): string
{
    return preg_replace('/\{[^}]+\}/', '1', $path) ?: $path;
}

function requestForRoute(string $name, string $path, ?Session $session = null, ?string $method = 'GET'): Request
{
    $request = Request::create(concretePath($path), $method);
    $request->attributes->set('_route', $name);
    if ($session !== null) {
        $request->setSession($session);
    }

    return $request;
}

function authSubscriberFor(JsonStorage $storage, Request $request, ?AuthSmokeRouter $router = null): AuthSubscriber
{
    $stack = new RequestStack();
    $stack->push($request);

    return new AuthSubscriber(new AuthService($storage, $stack), $router ?? new AuthSmokeRouter());
}

function eventFor(Request $request, ?AuthSmokeKernel $kernel = null): RequestEvent
{
    return new RequestEvent($kernel ?? new AuthSmokeKernel(), $request, HttpKernelInterface::MAIN_REQUEST);
}

function jellyfinRouteIsPublic(string $name): bool
{
    return in_array($name, ['jellyfin_info_public', 'jellyfin_authenticate', 'jellyfin_image'], true);
}

$passwordService = new PasswordService();

echo "Route inventory\n";
$routes = routerInventory();
check($routes !== [], 'debug:router inventory is readable');
check(isset($routes['login']), 'login route is inventoried');
check(isset($routes['queue_status'], $routes['progress'], $routes['notifications_poll'], $routes['queue_active_log']), 'polling routes are inventoried');

$protectedGet = [];
$exemptGet = [];
$unexpectedExempt = [];
foreach ($routes as $name => $route) {
    $method = (string) ($route['method'] ?? '');
    if (!routeMethodIncludesGet($method)) {
        continue;
    }
    $path = (string) ($route['path'] ?? '');
    if (routeIsAuthExempt($name, $path)) {
        $exemptGet[] = $name . ' ' . $path;
        $expected = $name === 'login'
            || str_starts_with($name, '_')
            || str_starts_with($name, 'jellyfin_')
            || str_starts_with($path, '/jellyfin');
        if (!$expected) {
            $unexpectedExempt[] = $name . ' ' . $path;
        }
        continue;
    }
    $protectedGet[] = ['name' => $name, 'path' => $path];
}

check(count($protectedGet) > 10, 'a meaningful number of GET routes is protected', count($protectedGet));
eq([], $unexpectedExempt, 'only login, Jellyfin and framework routes are auth-exempt');
$exemptPaths = array_map(static fn (string $entry): string => explode(' ', $entry, 2)[1] ?? '', $exemptGet);
foreach ($exemptPaths as $path) {
    check($path === '/login' || str_starts_with($path, '/jellyfin') || str_starts_with($path, '/_') || $path === '/System/Info/Public', 'exempt path is login/Jellyfin/framework: ' . $path);
}

$authDir = tempDir('auth-routes');
$authStorage = storageAt($authDir);
$authStorage->set('config', ['admin_user' => 'admin', 'admin_password' => 'route-password']);

foreach ($protectedGet as $route) {
    $session = new Session(new MockArraySessionStorage());
    $session->start();
    $request = requestForRoute($route['name'], $route['path'], $session);
    $event = eventFor($request);
    authSubscriberFor($authStorage, $request)->onKernelRequest($event);
    $response = $event->getResponse();
    check($response instanceof RedirectResponse && $response->getTargetUrl() === '/login', 'unauthenticated GET is redirected: ' . $route['name']);
}

echo "Polling routes\n";
foreach (['queue_status', 'progress', 'notifications_poll', 'queue_active_log'] as $pollingRoute) {
    check(isset($routes[$pollingRoute]), 'polling route exists: ' . $pollingRoute);
    $session = new Session(new MockArraySessionStorage());
    $session->start();
    $request = requestForRoute($pollingRoute, (string) $routes[$pollingRoute]['path'], $session);
    $event = eventFor($request);
    authSubscriberFor($authStorage, $request)->onKernelRequest($event);
    check($event->getResponse() instanceof RedirectResponse, 'polling route is protected: ' . $pollingRoute);
}

$pollingSession = new Session(new MockArraySessionStorage());
$pollingSession->start();
$pollingSession->set('authenticated', true);
$pollingSession->set('username', 'admin');
$pollingSession->set('last_activity', time() - 120);
$lastActivity = (int) $pollingSession->get('last_activity');
$pollingRequest = requestForRoute('queue_status', (string) $routes['queue_status']['path'], $pollingSession);
$pollingEvent = eventFor($pollingRequest);
authSubscriberFor($authStorage, $pollingRequest)->onKernelRequest($pollingEvent);
check($pollingEvent->getResponse() === null, 'authenticated polling request is allowed');
eq($lastActivity, (int) $pollingSession->get('last_activity'), 'polling does not refresh last_activity');

$normalRequest = requestForRoute('config', '/config', $pollingSession);
$normalEvent = eventFor($normalRequest);
authSubscriberFor($authStorage, $normalRequest)->onKernelRequest($normalEvent);
check($normalEvent->getResponse() === null, 'authenticated normal request is allowed');
check((int) $pollingSession->get('last_activity') > $lastActivity, 'normal request refreshes last_activity');

echo "AuthSubscriber exemptions\n";
$loginSession = new Session(new MockArraySessionStorage());
$loginSession->start();
$loginRequest = requestForRoute('login', '/login', $loginSession, 'POST');
$loginEvent = eventFor($loginRequest);
authSubscriberFor($authStorage, $loginRequest)->onKernelRequest($loginEvent);
check($loginEvent->getResponse() === null, 'login route is not redirected by AuthSubscriber');

$jellyfinSession = new Session(new MockArraySessionStorage());
$jellyfinSession->start();
foreach ([
    ['jellyfin_user_views', '/jellyfin/UserViews'],
    ['jellyfin_logout', '/jellyfin/Sessions/Logout'],
    ['jellyfin_root_info_public', '/System/Info/Public'],
] as [$name, $path]) {
    $request = requestForRoute($name, $path, $jellyfinSession, $name === 'jellyfin_logout' ? 'POST' : 'GET');
    $event = eventFor($request);
    authSubscriberFor($authStorage, $request)->onKernelRequest($event);
    check($event->getResponse() === null, 'Jellyfin route is not redirected by AuthSubscriber: ' . $name);
}

$frameworkSession = new Session(new MockArraySessionStorage());
$frameworkSession->start();
$frameworkRequest = requestForRoute('_preview_error', '/_error/404', $frameworkSession);
$frameworkEvent = eventFor($frameworkRequest);
authSubscriberFor($authStorage, $frameworkRequest)->onKernelRequest($frameworkEvent);
check($frameworkEvent->getResponse() === null, 'framework _ routes are ignored');

echo "AuthService login and idle timeout\n";
$serviceDir = tempDir('auth-service');
$serviceStorage = storageAt($serviceDir);
$serviceStorage->set('config', ['admin_user' => 'admin', 'admin_password' => 'legacy-password']);
$session = new Session(new MockArraySessionStorage());
$session->start();
$request = new Request();
$request->setSession($session);
$stack = new RequestStack();
$stack->push($request);
$auth = new AuthService($serviceStorage, $stack);
$beforeId = $session->getId();
check($auth->authenticate('admin', 'legacy-password'), 'legacy plaintext login succeeds');
check($session->getId() !== $beforeId, 'session id is regenerated on login');
check($session->get('authenticated') === true, 'session is marked authenticated');
$config = $serviceStorage->get('config');
check($passwordService->isHash((string) $config['admin_password']), 'legacy password is hashed on login');
check($passwordService->verify('legacy-password', (string) $config['admin_password']), 'upgraded hash verifies');
$firstHash = (string) $config['admin_password'];
$auth->logout();
check(!$auth->isAuthenticated(false), 'logout invalidates the session');
check($session->get('authenticated', false) !== true, 'logout clears authenticated flag');
check($auth->authenticate('admin', 'legacy-password'), 'login with hashed password succeeds');
eq($firstHash, (string) $serviceStorage->get('config')['admin_password'], 'hash is not rehashed on later login');
$auth->logout();
check(!$auth->authenticate('admin', 'wrong-password'), 'wrong password is rejected');
check($session->get('authenticated', false) !== true, 'rejected login does not authenticate the session');

$session->set('authenticated', true);
$session->set('last_activity', time() - 100);
$oldActivity = (int) $session->get('last_activity');
check($auth->isAuthenticated(false), 'auth check within idle window succeeds');
eq($oldActivity, (int) $session->get('last_activity'), 'polling-style auth check does not refresh last_activity');
check($auth->isAuthenticated(true), 'normal auth check succeeds');
check((int) $session->get('last_activity') >= $oldActivity, 'normal auth check refreshes last_activity');

$session->set('authenticated', true);
$session->set('last_activity', time() - 7201);
check(!$auth->isAuthenticated(false), 'idle timeout above two hours rejects the session');
check($session->get('authenticated', false) !== true, 'idle timeout invalidates the session');

echo "JellyfinAuthService\n";
$jfDir = tempDir('auth-jellyfin');
$jfStorage = storageAt($jfDir);
$jfStorage->set('config', [
    'jellyfin_enabled' => true,
    'jellyfin_username' => 'nuvio',
    'jellyfin_password' => 'legacy-jf',
    'jellyfin_server_name' => 'Downloader Smoke',
]);
$jf = new JellyfinAuthService($jfStorage);
check($jf->isEnabled(), 'Jellyfin auth is enabled');
eq('Downloader Smoke', $jf->publicInfo()['ServerName'], 'Jellyfin public info is available');
check($jf->authenticate('nuvio', 'wrong') === null, 'Jellyfin wrong password is rejected');
$signIn = $jf->authenticate('nuvio', 'legacy-jf');
check($signIn !== null && !empty($signIn['AccessToken']), 'Jellyfin login returns a token');
eq('nuvio', $signIn['User']['Name'] ?? null, 'Jellyfin login returns the user name');
$jfConfig = $jfStorage->get('config');
check($passwordService->isHash((string) $jfConfig['jellyfin_password']), 'Jellyfin legacy password is hashed');
check($passwordService->verify('legacy-jf', (string) $jfConfig['jellyfin_password']), 'Jellyfin upgraded hash verifies');
$jfHash = (string) $jfConfig['jellyfin_password'];
$secondSignIn = $jf->authenticate('nuvio', 'legacy-jf');
check($secondSignIn !== null && $jfStorage->get('config')['jellyfin_password'] === $jfHash, 'Jellyfin hash is not double-hashed');
$token = (string) $signIn['AccessToken'];
$apiKeyRequest = Request::create('/jellyfin/UserViews', 'GET', ['api_key' => $token]);
eq($token, $jf->tokenFromRequest($apiKeyRequest), 'Jellyfin token is read from api_key');
$headerRequest = Request::create('/jellyfin/UserViews', 'GET');
$headerRequest->headers->set('X-Emby-Token', $token);
eq($token, $jf->tokenFromRequest($headerRequest), 'Jellyfin token is read from X-Emby-Token');
$authHeaderRequest = Request::create('/jellyfin/UserViews', 'GET');
$authHeaderRequest->headers->set('Authorization', 'MediaBrowser Client="Nuvio", Token="' . $token . '"');
eq($token, $jf->tokenFromRequest($authHeaderRequest), 'Jellyfin token is read from Authorization');
check($jf->validateToken($token) !== null, 'Jellyfin token validates');
check($jf->validateToken('invalid-token') === null, 'Jellyfin invalid token is rejected');

$jfSubscriber = new JellyfinAuthSubscriber($jf);
$jfSession = new Session(new MockArraySessionStorage());
$jfSession->start();
foreach (['jellyfin_info_public', 'jellyfin_authenticate'] as $publicRoute) {
    $path = $publicRoute === 'jellyfin_info_public' ? '/jellyfin/System/Info/Public' : '/jellyfin/Users/AuthenticateByName';
    $request = requestForRoute($publicRoute, $path, $jfSession, $publicRoute === 'jellyfin_authenticate' ? 'POST' : 'GET');
    $event = eventFor($request);
    $jfSubscriber->onKernelRequest($event);
    check($event->getResponse() === null, 'Jellyfin public route is accessible: ' . $publicRoute);
}
$protectedRequest = requestForRoute('jellyfin_user_views', '/jellyfin/UserViews', $jfSession);
$protectedEvent = eventFor($protectedRequest);
$jfSubscriber->onKernelRequest($protectedEvent);
$protectedResponse = $protectedEvent->getResponse();
check($protectedResponse instanceof JsonResponse && $protectedResponse->getStatusCode() === 401, 'Jellyfin protected route without token returns 401');
$authorizedRequest = Request::create('/jellyfin/UserViews', 'GET', ['api_key' => $token]);
$authorizedRequest->attributes->set('_route', 'jellyfin_user_views');
$authorizedRequest->setSession($jfSession);
$authorizedEvent = eventFor($authorizedRequest);
$jfSubscriber->onKernelRequest($authorizedEvent);
check($authorizedEvent->getResponse() === null, 'Jellyfin protected route with valid token is accessible');
$jf->logout($token);
check($jf->validateToken($token) === null, 'Jellyfin logout revokes the token');
$revokedEvent = eventFor(Request::create('/jellyfin/UserViews', 'GET', ['api_key' => $token]));
$revokedEvent->getRequest()->attributes->set('_route', 'jellyfin_user_views');
$revokedEvent->getRequest()->setSession($jfSession);
$jfSubscriber->onKernelRequest($revokedEvent);
$revokedResponse = $revokedEvent->getResponse();
check($revokedResponse instanceof JsonResponse && $revokedResponse->getStatusCode() === 401, 'revoked Jellyfin token is rejected');

removeTree($authDir);
removeTree($serviceDir);
removeTree($jfDir);

echo "\n$passes passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
