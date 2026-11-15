<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\HostResolverInterface;
use App\Service\DownloadManager;
use App\Service\JsonStorage;
use App\Service\MediaAddonService;
use App\Service\MediaDownloadService;
use App\Service\QueueManager;
use App\Service\SafeUrlGuard;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

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

class SsrfFakeResolver implements HostResolverInterface
{
    public array $map;

    public function __construct(array $map)
    {
        $this->map = $map;
    }

    public function resolve(string $host): array
    {
        return $this->map[$host] ?? [];
    }
}

class SsrfFakeQueueManager extends QueueManager
{
    public array $items = [];

    public function __construct()
    {
    }

    public function enqueue(array $item, string $type = 'video'): void
    {
        $this->items[] = $item;
    }
}

$resolver = new SsrfFakeResolver([
    'cdn.example.com' => ['93.184.216.34'],
    'public.example.com' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
    'internal.example.com' => ['10.1.2.3'],
    'mixed.example.com' => ['93.184.216.34', '192.168.1.10'],
    'addon.example.com' => ['93.184.216.34'],
]);
$guard = new SafeUrlGuard($resolver);

echo "Blocked URLs\n";
$blocked = [
    'http://127.0.0.1/file.mkv',
    'http://localhost/file.mkv',
    'http://[::1]/file.mkv',
    'http://10.0.0.1/file.mkv',
    'http://172.16.0.1/file.mkv',
    'http://192.168.1.1/file.mkv',
    'http://169.254.169.254/latest/meta-data',
    'http://metadata.google.internal/file.mkv',
    'http://foo.local/file.mkv',
    'http://public@127.0.0.1/file.mkv',
    'http://cdn.example.com:6379/file.mkv',
    'http://internal.example.com/file.mkv',
    'http://mixed.example.com/file.mkv',
    'http://unresolved-single-label/file.mkv',
];
foreach ($blocked as $url) {
    $result = $guard->validate($url);
    check(empty($result['allowed']), 'blocks ' . preg_replace('#^https?://#', '', $url));
}

echo "Allowed URLs\n";
foreach ([
    'https://cdn.example.com/file.mkv',
    'http://cdn.example.com/file.mkv',
    'https://public.example.com/file.mkv',
    'https://93.184.216.34/file.mkv',
] as $url) {
    $result = $guard->validate($url);
    check(!empty($result['allowed']), 'allows ' . preg_replace('#^https?://#', '', $url));
}

echo "Redirect policy\n";
check(!empty($guard->validateRedirect('https://cdn.example.com/2', 1, ['https://cdn.example.com/1'])['allowed']), 'public to public redirect is allowed');
check(empty($guard->validateRedirect('https://internal.example.com/2', 1, [])['allowed']), 'public to private redirect is rejected');
check(empty($guard->validateRedirect('https://cdn.example.com/1', 1, ['https://cdn.example.com/1'])['allowed']), 'redirect loop is rejected');
check(empty($guard->validateRedirect('https://cdn.example.com/2', 6, [])['allowed']), 'more than five redirects is rejected');

echo "Media download direct stream\n";
$service = (new ReflectionClass(MediaDownloadService::class))->newInstanceWithoutConstructor();
$queue = new SsrfFakeQueueManager();
foreach (['urlGuard' => $guard, 'queueManager' => $queue] as $property => $value) {
    $ref = new ReflectionProperty(MediaDownloadService::class, $property);
    $ref->setAccessible(true);
    $ref->setValue($service, $value);
}
$queueDirect = new ReflectionMethod(MediaDownloadService::class, 'queueDirectStream');
$queueDirect->setAccessible(true);
$bad = $queueDirect->invoke($service, ['filename' => 'bad.mkv'], 'http://127.0.0.1/file.mkv', '/tmp');
check(empty($bad['success']) && $queue->items === [], 'invalid addon direct URL is rejected before enqueue');
$good = $queueDirect->invoke($service, ['filename' => 'good.mkv'], 'https://cdn.example.com/file.mkv', '/tmp');
check(!empty($good['success']) && count($queue->items) === 1, 'valid addon direct URL is queued');

echo "DownloadManager URL guard\n";
$dmDir = tempDir('ssrf-download-smoke');
$dmStorage = storageAt($dmDir);
$downloadManager = new DownloadManager($dmStorage, $guard);
$downloadResult = $downloadManager->downloadFile('http://127.0.0.1/file.mkv', 'x.mkv', $dmDir . '/out', 'qa');
check(empty($downloadResult['success']) && !is_file($dmDir . '/progress_qa.json'), 'DownloadManager rejects a private URL before side effects');
removeTree($dmDir);

echo "Addon stream filtering\n";
$manifest = json_encode([
    'id' => 'test-addon',
    'name' => 'Test Addon',
    'types' => ['movie'],
    'resources' => [['name' => 'stream', 'types' => ['movie']]],
]);
$badStreams = json_encode(['streams' => [['name' => 'bad', 'url' => 'http://127.0.0.1/file.mkv']]]);
$goodStreams = json_encode(['streams' => [['name' => 'good', 'url' => 'https://cdn.example.com/file.mkv']]]);
$dir = tempDir('ssrf-addon-smoke');
$storage = storageAt($dir);
$storage->set('media_addons', [['id' => 'test-addon', 'name' => 'Test Addon', 'manifest_url' => 'https://addon.example.com/manifest.json', 'enabled' => true]]);
$mock = new MockHandler([new Response(200, [], $manifest), new Response(200, [], $badStreams)]);
$client = new Client(['handler' => HandlerStack::create($mock)]);
$addons = new MediaAddonService($storage, new ArrayAdapter(), $guard);
$clientProperty = new ReflectionProperty(MediaAddonService::class, 'client');
$clientProperty->setAccessible(true);
$clientProperty->setValue($addons, $client);
$badResult = $addons->getStreams('movie', 'tt1234567');
check($badResult['streams'] === [] && !empty($badResult['errors']), 'invalid addon direct URL is not exposed as a stream');
removeTree($dir);

$dir = tempDir('ssrf-addon-good-smoke');
$storage = storageAt($dir);
$storage->set('media_addons', [['id' => 'test-addon', 'name' => 'Test Addon', 'manifest_url' => 'https://addon.example.com/manifest.json', 'enabled' => true]]);
$mock = new MockHandler([new Response(200, [], $manifest), new Response(200, [], $goodStreams)]);
$client = new Client(['handler' => HandlerStack::create($mock)]);
$addons = new MediaAddonService($storage, new ArrayAdapter(), $guard);
$clientProperty->setValue($addons, $client);
$goodResult = $addons->getStreams('movie', 'tt1234567');
check(count($goodResult['streams']) === 1 && $goodResult['streams'][0]['url'] === 'https://cdn.example.com/file.mkv', 'valid addon direct URL is exposed unchanged');
removeTree($dir);

echo "\n$passes passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
