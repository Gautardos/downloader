<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Controller\ConfigController;
use App\Service\AuthService;
use App\Service\Automation\AutomationStore;
use App\Service\JsonStorage;
use App\Service\Jellyfin\JellyfinAuthService;
use App\Service\LogRedactor;
use App\Service\PasswordService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

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

function eq(mixed $expected, mixed $actual, string $label): void
{
    check($expected === $actual, $label . ' => ' . var_export($actual, true));
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

class SessionSecretsTestConfigController extends ConfigController
{
    public array $flashes = [];

    protected function addFlash(string $type, mixed $message): void
    {
        $this->flashes[] = $message;
    }

    protected function redirectToRoute(string $route, array $parameters = [], int $status = 302): RedirectResponse
    {
        return new RedirectResponse('/' . $route);
    }
}

$passwordService = new PasswordService();

echo "Password hashing\n";
$hash = $passwordService->hash('secret');
check($passwordService->isHash($hash), 'generated hash is detected');
check($passwordService->verify('secret', $hash), 'hash verifies');
check(!$passwordService->verify('wrong', $hash), 'wrong password is rejected');
check(!$passwordService->isHash('plaintext'), 'plaintext is not a hash');
check($passwordService->hashIfPlain($hash) === $hash, 'existing hash is not rehashed');
check($passwordService->verify('plaintext', 'plaintext'), 'legacy plaintext verifies');

echo "Auth service\n";
$authDir = tempDir('auth-smoke');
$authStorage = storageAt($authDir);
$authStorage->set('config', [
    'admin_user' => 'admin',
    'admin_password' => 'legacy-password',
]);
$session = new Session(new MockArraySessionStorage());
$session->start();
$request = new Request();
$request->setSession($session);
$stack = new RequestStack();
$stack->push($request);
$auth = new AuthService($authStorage, $stack);
$beforeId = $session->getId();
check($auth->authenticate('admin', 'legacy-password'), 'legacy plaintext login succeeds');
check($session->getId() !== $beforeId, 'session id is regenerated after login');
check($session->get('last_activity') !== null, 'last activity is recorded');
$config = $authStorage->get('config');
check($passwordService->isHash((string) $config['admin_password']), 'legacy admin password is rehashed');
check($passwordService->verify('legacy-password', (string) $config['admin_password']), 'rehashed admin password verifies');
$firstHash = (string) $config['admin_password'];
$auth->logout();
check(!$auth->isAuthenticated(false), 'logout clears authentication');
check($auth->authenticate('admin', 'legacy-password'), 'login with hashed password succeeds');
$secondHash = (string) $authStorage->get('config')['admin_password'];
check($firstHash === $secondHash, 'hash is not rehashed on subsequent login');
check(!$auth->authenticate('admin', 'wrong'), 'wrong admin password is rejected');

$session->set('authenticated', true);
$session->set('last_activity', time() - 100);
$oldActivity = (int) $session->get('last_activity');
check($auth->isAuthenticated(false), 'polling-style auth check succeeds within idle window');
eq($oldActivity, (int) $session->get('last_activity'), 'polling does not refresh last activity');
check($auth->isAuthenticated(true), 'normal request auth check succeeds');
check((int) $session->get('last_activity') >= $oldActivity, 'normal request refreshes last activity');

$session->set('authenticated', true);
$session->set('last_activity', time() - 7201);
check(!$auth->isAuthenticated(false), 'idle session is rejected');
check($session->get('authenticated', false) !== true, 'idle session is invalidated');
removeTree($authDir);

echo "Jellyfin legacy auth\n";
$jfDir = tempDir('jellyfin-auth-smoke');
$jfStorage = storageAt($jfDir);
$jfStorage->set('config', [
    'jellyfin_enabled' => true,
    'jellyfin_username' => 'nuvio',
    'jellyfin_password' => 'legacy-jf',
]);
$jf = new JellyfinAuthService($jfStorage);
check($jf->authenticate('nuvio', 'wrong') === null, 'jellyfin wrong password is rejected');
$signIn = $jf->authenticate('nuvio', 'legacy-jf');
check($signIn !== null && !empty($signIn['AccessToken']), 'jellyfin legacy login succeeds');
$jfConfig = $jfStorage->get('config');
check($passwordService->isHash((string) $jfConfig['jellyfin_password']), 'jellyfin legacy password is rehashed');
check($passwordService->verify('legacy-jf', (string) $jfConfig['jellyfin_password']), 'jellyfin hash verifies');
$jfHash = (string) $jfConfig['jellyfin_password'];
check($jf->authenticate('nuvio', 'legacy-jf') !== null, 'jellyfin login with hash succeeds');
check((string) $jfStorage->get('config')['jellyfin_password'] === $jfHash, 'jellyfin hash is not rehashed');
removeTree($jfDir);

echo "Config save preserves secrets\n";
$configDir = tempDir('config-smoke');
$configStorage = storageAt($configDir);
$configStorage->set('config', [
    'api_key' => 'saved-api',
    'openrouter_api_key' => 'saved-or',
    'grok_api_key' => 'saved-grok',
    'spotify_client_secret' => 'saved-spotify',
    'genius_api_token' => 'saved-genius',
    'lrclib_token' => 'saved-lrclib',
    'admin_user' => 'admin',
    'admin_password' => 'legacy-admin',
    'jellyfin_password' => 'legacy-jf',
    'jellyfin_username' => 'jellyfin',
]);
$controller = new SessionSecretsTestConfigController();
$save = static function (array $params) use ($controller, $configStorage, $passwordService): void {
    $controller->index(Request::create('/config', 'POST', $params), $configStorage, $passwordService);
};
$save([]);
$saved = $configStorage->get('config');
check($saved['api_key'] === 'saved-api', 'blank api key keeps existing value');
check($saved['openrouter_api_key'] === 'saved-or', 'blank openrouter key keeps existing value');
check($saved['admin_password'] === 'legacy-admin', 'blank admin password keeps existing value');
check($saved['jellyfin_password'] === 'legacy-jf', 'blank jellyfin password keeps existing value');
$save(['admin_password' => 'new-admin', 'jellyfin_password' => 'new-jf']);
$updated = $configStorage->get('config');
check($passwordService->isHash((string) $updated['admin_password']), 'new admin password is hashed');
check($passwordService->verify('new-admin', (string) $updated['admin_password']), 'new admin password verifies');
check($passwordService->isHash((string) $updated['jellyfin_password']), 'new jellyfin password is hashed');
$adminHash = (string) $updated['admin_password'];
$save([]);
check($adminHash === (string) $configStorage->get('config')['admin_password'], 'blank save does not double-hash admin password');
$save(['clear' => ['api_key' => '1']]);
check((string) $configStorage->get('config')['api_key'] === '', 'explicit clear removes saved api key');
removeTree($configDir);

echo "Log redaction\n";
$redactor = new LogRedactor();
$redacted = $redactor->redact('cmd --client-secret "abc" --token xyz apikey=foo token=bar password=baz Authorization: Bearer bearer-secret');
foreach (['abc', 'xyz', 'foo', 'bar', 'baz', 'bearer-secret'] as $secret) {
    check(!str_contains($redacted, $secret), 'redacts ' . $secret);
}
check(str_contains($redacted, '***'), 'redaction marker is present');

echo "Permissions and templates\n";
$permDir = tempDir('perm-smoke');
$permStorage = storageAt($permDir);
$permStorage->set('config', ['api_key' => 'x']);
$configPerms = fileperms($permDir . '/config.json') & 0777;
eq(0600, $configPerms, 'JsonStorage writes config as 0600');
$project = tempDir('db-perm-smoke');
$store = new AutomationStore($project);
$store->initialize();
$dbPerms = fileperms($project . '/var/db/automation.db') & 0777;
eq(0600, $dbPerms, 'automation database is 0600');
removeTree($permDir);
removeTree($project);

$configTemplate = file_get_contents(dirname(__DIR__) . '/templates/config/index.html.twig');
foreach (['api_key', 'openrouter_api_key', 'grok_api_key', 'spotify_client_secret', 'genius_api_token', 'lrclib_token', 'admin_password', 'jellyfin_password'] as $field) {
    check(!preg_match('/name="' . preg_quote($field, '/') . '"[^>]*value="\{\{/', $configTemplate), 'template does not prefill ' . $field);
}

echo "\n$passes passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
