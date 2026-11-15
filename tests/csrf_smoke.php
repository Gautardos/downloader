<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\EventSubscriber\CsrfSubscriber;
use App\Service\CsrfTokenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Process\Process;

$passes = 0;
$failures = 0;

function check(bool $condition, string $label): void
{
    global $passes, $failures;
    if ($condition) {
        $passes++;
        echo "  ok  $label\n";
        return;
    }
    $failures++;
    echo "  FAIL $label\n";
}

$session = new Session(new MockArraySessionStorage());
$session->start();
$request = new Request();
$request->setSession($session);
$csrf = new CsrfTokenService();
$token = $csrf->getToken($request);
$subscriber = new CsrfSubscriber($csrf);

function requestWithSession(Session $session, string $method, string $path, array $parameters = [], array $server = [], ?string $content = null): Request
{
    $request = Request::create($path, $method, $parameters, [], [], $server, $content);
    $request->setSession($session);

    return $request;
}

echo "CSRF enforcement scope\n";
check(!$subscriber->shouldEnforce(requestWithSession($session, 'GET', '/config')), 'GET is not enforced');
check($subscriber->shouldEnforce(requestWithSession($session, 'POST', '/config')), 'POST is enforced');
check($subscriber->shouldEnforce(requestWithSession($session, 'PUT', '/media/settings')), 'PUT is enforced');
check($subscriber->shouldEnforce(requestWithSession($session, 'PATCH', '/media/settings')), 'PATCH is enforced');
check($subscriber->shouldEnforce(requestWithSession($session, 'DELETE', '/queue-remove/0')), 'DELETE is enforced');
check(!$subscriber->shouldEnforce(requestWithSession($session, 'POST', '/jellyfin/Users/AuthenticateByName')), 'Jellyfin is exempt');
check(!$subscriber->shouldEnforce(requestWithSession($session, 'POST', '/jellyfin/Sessions/Logout')), 'Jellyfin logout is exempt');
check($subscriber->shouldEnforce(requestWithSession($session, 'POST', '/login')), 'login is enforced');

echo "CSRF token validation\n";
$missing = $subscriber->validateRequest(requestWithSession($session, 'POST', '/config', [], ['CONTENT_TYPE' => 'application/json']));
check($missing instanceof JsonResponse && $missing->getStatusCode() === 403, 'missing token is rejected in JSON');
$invalid = $subscriber->validateRequest(requestWithSession($session, 'POST', '/config', ['_token' => 'invalid']));
check($invalid !== null && $invalid->getStatusCode() === 403, 'invalid form token is rejected');
$validForm = $subscriber->validateRequest(requestWithSession($session, 'POST', '/config', ['_token' => $token]));
check($validForm === null, 'valid form token is accepted');
$validHeader = $subscriber->validateRequest(requestWithSession($session, 'POST', '/config', [], ['HTTP_X_CSRF_TOKEN' => $token]));
check($validHeader === null, 'valid header token is accepted');
$validJson = $subscriber->validateRequest(requestWithSession(
    $session,
    'POST',
    '/config',
    [],
    ['CONTENT_TYPE' => 'application/json'],
    json_encode(['csrf' => $token])
));
check($validJson === null, 'valid JSON token is accepted');
$validMultipart = $subscriber->validateRequest(requestWithSession(
    $session,
    'POST',
    '/dashboard/upload-torrent',
    [],
    ['CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'HTTP_X_CSRF_TOKEN' => $token]
));
check($validMultipart === null, 'valid multipart header token is accepted');
$textResponse = $subscriber->validateRequest(requestWithSession(
    $session,
    'POST',
    '/queue-purge',
    [],
    ['HTTP_ACCEPT' => 'text/html']
));
check($textResponse !== null && $textResponse->getStatusCode() === 403, 'missing token on HTML POST is rejected');

echo "Route inventory\n";
$process = new Process([PHP_BINARY, 'bin/console', 'debug:router', '--format=json'], dirname(__DIR__));
$process->setTimeout(30);
$process->run();
check($process->isSuccessful(), 'router inventory command succeeds');
$routes = json_decode($process->getOutput(), true);
check(is_array($routes) && $routes !== [], 'router inventory is readable');
$stateChanging = 0;
$covered = [];
$exempt = [];
$allowedExemptPrefixes = ['/jellyfin'];
foreach (is_array($routes) ? $routes : [] as $name => $route) {
    $method = strtoupper((string) ($route['method'] ?? ''));
    if (!preg_match('/\b(POST|PUT|PATCH|DELETE)\b/', $method)) {
        continue;
    }
    $stateChanging++;
    $path = (string) ($route['path'] ?? '');
    if (CsrfSubscriber::isExemptPath($path)) {
        $exempt[] = $name . ' ' . $path;
        $allowed = false;
        foreach ($allowedExemptPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $allowed = true;
                break;
            }
        }
        check($allowed, 'exemption is explicitly allowed: ' . $name . ' ' . $path);
        continue;
    }
    $covered[] = $name . ' ' . $path;
}
check($stateChanging > 0, 'at least one state-changing route is inventoried');
check(count($covered) + count($exempt) === $stateChanging, 'every state-changing route is covered or explicitly exempted');
check(count($exempt) === 7, 'only the seven Jellyfin routes are exempted');
$coveredJellyfin = array_values(array_filter($covered, static fn (string $route): bool => str_contains($route, ' /jellyfin/')));
$unexpectedExempt = array_values(array_filter($exempt, static fn (string $route): bool => !str_contains($route, ' /jellyfin/')));
check($coveredJellyfin === [], 'no covered state-changing route targets Jellyfin');
check($unexpectedExempt === [], 'no unexpected non-Jellyfin exemption is registered');
check(CsrfSubscriber::isExemptPath('/jellyfin/System/Info'), 'Jellyfin path is exempt');
check(!CsrfSubscriber::isExemptPath('/login'), 'login path is not exempt');
check(!CsrfSubscriber::isExemptPath('/config'), 'config path is not exempt');
check(!CsrfSubscriber::isExemptPath('/dashboard/upload-torrent'), 'upload path is not exempt');
check(!CsrfSubscriber::isExemptPath('/config/import'), 'config import path is not exempt');
echo '  info state-changing routes: ' . $stateChanging . '; exempt: ' . count($exempt) . "\n";

echo "\n$passes passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
