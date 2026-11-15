<?php

$baseUrl = rtrim((string) (getenv('BASE_URL') ?: 'http://127.0.0.1:8000'), '/');
$passes = 0;
$failures = 0;
$skipped = 0;

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

function skip(string $label): void
{
    global $skipped;
    $skipped++;
    echo "  skip $label\n";
}

function urlFor(string $baseUrl, string $path): string
{
    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}

function httpRequest(string $method, string $url, ?string $body = null, array $headers = []): array
{
    if (extension_loaded('curl')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        if ($raw === false || $errno !== 0) {
            return ['status' => 0, 'location' => null, 'body' => '', 'error' => $error];
        }

        $rawHeaders = substr((string) $raw, 0, $headerSize);
        $responseBody = substr((string) $raw, $headerSize);
        $location = null;
        foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
            if (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
            }
        }

        return ['status' => $status, 'location' => $location, 'body' => $responseBody, 'error' => null];
    }

    $context = stream_context_create([
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => 8,
        ],
    ]);
    $responseBody = @file_get_contents($url, false, $context);
    $responseHeaders = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?: [])
        : ($http_response_header ?? []);
    $status = 0;
    $location = null;
    foreach ($responseHeaders as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches)) {
            $status = (int) $matches[1];
        }
        if (stripos($line, 'Location:') === 0) {
            $location = trim(substr($line, 9));
        }
    }
    if ($responseBody === false && $status === 0) {
        return ['status' => 0, 'location' => null, 'body' => '', 'error' => 'connection failed'];
    }

    return ['status' => $status, 'location' => $location, 'body' => (string) $responseBody, 'error' => null];
}

function redirectsToLogin(array $response): bool
{
    if ($response['status'] !== 302 || !is_string($response['location'])) {
        return false;
    }
    $path = parse_url($response['location'], PHP_URL_PATH);

    return is_string($path) && rtrim($path, '/') === '/login';
}

echo "HTTP auth smoke against $baseUrl\n";
$probe = httpRequest('GET', urlFor($baseUrl, '/login'));
if ($probe['status'] === 0) {
    skip('BASE_URL is not reachable; live HTTP smoke skipped');
    echo "\n$passes passed, $failures failed, $skipped skipped.\n";
    exit(0);
}
check($probe['status'] === 200, 'login page is reachable', $probe['status']);

echo "Protected GET pages\n";
$protectedPages = ['/', '/config', '/automation', '/automation/downloads', '/media', '/history', '/queue', '/logs', '/debug-storage'];
foreach ($protectedPages as $path) {
    $response = httpRequest('GET', urlFor($baseUrl, $path));
    check(redirectsToLogin($response), 'protected GET redirects to login: ' . $path, [$response['status'], $response['location']]);
}

echo "Protected GET polling\n";
foreach (['/queue-status', '/queue-active-log', '/notifications-poll', '/progress/smoke-read-only'] as $path) {
    $response = httpRequest('GET', urlFor($baseUrl, $path));
    check(redirectsToLogin($response), 'polling GET redirects to login: ' . $path, [$response['status'], $response['location']]);
}

echo "Protected POST without session\n";
$protectedPosts = [
    ['/config', 'application/x-www-form-urlencoded', 'admin_user=smoke&admin_password=not-a-real-password'],
    ['/automation/scan', 'application/x-www-form-urlencoded', ''],
    ['/queue-purge', 'application/x-www-form-urlencoded', ''],
    ['/media/settings', 'application/json', '{}'],
];
foreach ($protectedPosts as [$path, $contentType, $body]) {
    $response = httpRequest('POST', urlFor($baseUrl, $path), $body, ['Content-Type: ' . $contentType]);
    check(redirectsToLogin($response), 'protected POST redirects to login without side effect: ' . $path, [$response['status'], $response['location']]);
}

echo "Login CSRF\n";
$loginPost = httpRequest('POST', urlFor($baseUrl, '/login'), 'username=smoke&password=not-a-real-password', ['Content-Type: application/x-www-form-urlencoded']);
check($loginPost['status'] === 403, 'POST /login without CSRF token returns 403', $loginPost['status']);

echo "Jellyfin auth\n";
foreach (['/jellyfin/UserViews', '/jellyfin/Items', '/jellyfin/System/Info'] as $path) {
    $response = httpRequest('GET', urlFor($baseUrl, $path));
    check($response['status'] === 401, 'Jellyfin protected route without token returns 401: ' . $path, $response['status']);
}
$public = httpRequest('GET', urlFor($baseUrl, '/jellyfin/System/Info/Public'));
check($public['status'] === 200, 'Jellyfin public route is accessible', $public['status']);
$publicRoot = httpRequest('GET', urlFor($baseUrl, '/System/Info/Public'));
check(in_array($publicRoot['status'], [200, 302], true), 'Jellyfin root public route is accessible or redirects publicly', $publicRoot['status']);

echo "\n$passes passed, $failures failed, $skipped skipped.\n";
exit($failures === 0 ? 0 : 1);
