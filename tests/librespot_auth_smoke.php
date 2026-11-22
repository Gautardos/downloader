<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\Spotify\LibrespotAuthService;

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

function makeFakeBinary(string $home, string $mode): string
{
    $binary = $home . '/fake-librespot-' . $mode;
    $credentials = [
        'auth_type' => 1,
        'auth_data' => 'stored-credential-blob',
        'username' => 'listener',
        'expires_at' => 1234567890,
    ];

    $script = <<<'PHP'
#!/usr/bin/env php
<?php
$home = getenv('HOME');
file_put_contents($home . '/fake-argv.json', json_encode([
    'args' => array_slice($_SERVER['argv'], 1),
    'cwd' => getcwd(),
    'home' => $home,
], JSON_UNESCAPED_UNICODE));
$mode = __MODE__;
if ($mode === 'missing') {
    exit(0);
}
if ($mode === 'invalid') {
    file_put_contents($home . '/credentials.json', '{not valid json');
    exit(0);
}
if ($mode === 'exit') {
    fwrite(STDERR, "librespot exploded\n");
    exit(7);
}
if ($mode === 'sleep') {
    sleep(10);
    exit(0);
}
file_put_contents($home . '/credentials.json', json_encode(__CREDS__, JSON_PRETTY_PRINT));
echo "fake librespot done\n";
PHP;

    $script = str_replace(['__MODE__', '__CREDS__'], [var_export($mode, true), var_export($credentials, true)], $script);
    file_put_contents($binary, $script);
    chmod($binary, 0755);

    return $binary;
}

echo "LibrespotAuthService\n";

$home = tempDir('librespot-home');
$target = tempDir('librespot-target') . '/nested/credentials.json';
$binary = makeFakeBinary($home, 'ok');
$service = new LibrespotAuthService(5.0);

$result = $service->generate($binary, $home, $target, 'Speaker 42');
check($result['success'] === true, 'successful generation returns success');
eq('Spotify credentials generated and stored successfully!', $result['message'] ?? null, 'success message');
eq($target, $result['path'] ?? null, 'target path reported');
eq('Speaker 42', $result['deviceName'] ?? null, 'device name reported');
eq('computer', $result['deviceClass'] ?? null, 'default device class is computer');
check(is_file($target), 'target credentials file is written');

$data = json_decode((string) file_get_contents($target), true);
check(is_array($data), 'target file contains JSON');
eq('AUTHENTICATION_STORED_SPOTIFY_CREDENTIALS', $data['type'] ?? null, 'auth_type is converted to type');
eq('stored-credential-blob', $data['credentials'] ?? null, 'auth_data is renamed to credentials');
check(!array_key_exists('auth_type', $data), 'auth_type key removed');
check(!array_key_exists('auth_data', $data), 'auth_data key removed');
eq('listener', $data['username'] ?? null, 'other credential fields are preserved');

$argv = json_decode((string) file_get_contents($home . '/fake-argv.json'), true);
eq(['--name', 'Speaker 42', '--class', 'computer'], $argv['args'] ?? null, 'binary receives --name and the default computer class');
eq(realpath($home), realpath((string) ($argv['cwd'] ?? '')), 'binary runs with HOME as working directory');
eq(realpath($home), realpath((string) ($argv['home'] ?? '')), 'binary receives HOME environment variable');

echo "Device class\n";
$home = tempDir('librespot-class');
$binary = makeFakeBinary($home, 'ok');
$classResult = (new LibrespotAuthService(5.0))->generate($binary, $home, $home . '/out.json', 'Speaker class', 'speaker');
eq(true, $classResult['success'] ?? null, 'explicit device class is accepted');
eq('speaker', $classResult['deviceClass'] ?? null, 'explicit device class is reported');
$classArgv = json_decode((string) file_get_contents($home . '/fake-argv.json'), true);
eq(['--name', 'Speaker class', '--class', 'speaker'], $classArgv['args'] ?? null, 'explicit device class is forwarded');
$fallbackResult = (new LibrespotAuthService(5.0))->generate($binary, $home, $home . '/out.json', 'Speaker fallback', 'bogus');
eq('computer', $fallbackResult['deviceClass'] ?? null, 'invalid device class falls back to computer');
removeTree($home);
removeTree(dirname(dirname($target)));

echo "Invalid JSON\n";
$home = tempDir('librespot-badjson');
$binary = makeFakeBinary($home, 'invalid');
$result = (new LibrespotAuthService(5.0))->generate($binary, $home, $home . '/out.json', 'Speaker bad');
check($result['success'] === false, 'invalid JSON is rejected');
eq('Failed to parse generated JSON.', $result['message'] ?? null, 'invalid JSON message');
removeTree($home);

echo "Missing file\n";
$home = tempDir('librespot-missing');
$binary = makeFakeBinary($home, 'missing');
$result = (new LibrespotAuthService(5.0))->generate($binary, $home, $home . '/out.json', 'Speaker missing');
check($result['success'] === false, 'missing credentials file is rejected');
eq('Credentials file not generated.', $result['message'] ?? null, 'missing file message');
eq($home . '/credentials.json', $result['debug']['expected_file'] ?? null, 'missing file debug points to expected file');
removeTree($home);

echo "Non-zero exit\n";
$home = tempDir('librespot-exit');
$binary = makeFakeBinary($home, 'exit');
$result = (new LibrespotAuthService(5.0))->generate($binary, $home, $home . '/out.json', 'Speaker exit');
check($result['success'] === false, 'non-zero exit without credentials is rejected');
eq('Credentials file not generated.', $result['message'] ?? null, 'non-zero exit message');
eq(7, $result['debug']['exit_code'] ?? null, 'non-zero exit code is reported');
check(str_contains((string) ($result['output'] ?? ''), 'librespot exploded'), 'stderr is captured in output');
removeTree($home);

echo "Timeout\n";
$home = tempDir('librespot-timeout');
$binary = makeFakeBinary($home, 'sleep');
$start = microtime(true);
$result = (new LibrespotAuthService(0.5))->generate($binary, $home, $home . '/out.json', 'Speaker slow');
$elapsed = microtime(true) - $start;
check($result['success'] === false, 'timeout is rejected');
eq('Credentials file not generated.', $result['message'] ?? null, 'timeout message');
check(($result['debug']['timed_out'] ?? false) === true, 'timeout flag is set in debug');
check($elapsed < 5.0, 'timeout is enforced quickly', round($elapsed, 2));
removeTree($home);

echo "Argument safety\n";
$home = tempDir('librespot-inject');
$binary = makeFakeBinary($home, 'ok');
$marker = $home . '/injected-marker';
$payload = 'Speaker "x"; touch ' . $marker . '; echo \'$(id)\' `whoami`';
$result = (new LibrespotAuthService(5.0))->generate($binary, $home, $home . '/out.json', $payload);
check($result['success'] === true, 'quoted device name is accepted');
$argv = json_decode((string) file_get_contents($home . '/fake-argv.json'), true);
eq(['--name', $payload, '--class', 'computer'], $argv['args'] ?? null, 'quoted device name is passed as a single argument');
check(!file_exists($marker), 'shell metacharacters in device name are not executed');
removeTree($home);

echo "\n$passes passed, $failures failed.\n";
exit($failures === 0 ? 0 : 1);
