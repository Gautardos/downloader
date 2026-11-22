<?php

namespace App\Service\Spotify;

use Symfony\Component\Process\Process;

class LibrespotAuthService
{
    public function __construct(private float $timeout = 60.0)
    {
    }

    public function generate(string $binary, string $homeDir, string $targetPath, string $deviceName): array
    {
        $homeDir = rtrim($homeDir, DIRECTORY_SEPARATOR);
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $generatedFile = $homeDir . DIRECTORY_SEPARATOR . 'credentials.json';

        if (file_exists($generatedFile)) {
            @unlink($generatedFile);
        }

        $command = [$binary, '--name', $deviceName];
        $debug = [
            'user' => $this->currentUser(),
            'php_cwd' => getcwd() ?: '',
            'home_writable' => is_writable($homeDir) ? 'Yes' : 'No',
            'binary_executable' => is_executable($binary) ? 'Yes' : 'No',
            'target_dir' => $homeDir,
            'binary_path' => $binary,
            'expected_file' => $generatedFile,
        ];

        $output = '';
        $timedOut = false;

        try {
            $process = new Process($command, $homeDir, ['HOME' => $homeDir], null, $this->timeout);
            $process->run();
            $output = $process->getOutput() . $process->getErrorOutput();
            $debug['exit_code'] = $process->getExitCode();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            $timedOut = true;
            $output = $e->getMessage();
            $debug['exit_code'] = null;
            $debug['error'] = 'timeout';
        } catch (\Throwable $e) {
            $output = $e->getMessage();
            $debug['exit_code'] = null;
            $debug['error'] = $e->getMessage();
        }

        if ($timedOut) {
            $debug['timed_out'] = true;
        }

        if (!file_exists($generatedFile)) {
            return [
                'success' => false,
                'message' => 'Credentials file not generated.',
                'debug' => $debug,
                'command' => $this->displayCommand($command),
                'output' => $output,
            ];
        }

        $content = file_get_contents($generatedFile);
        $data = json_decode((string) $content, true);

        if (!is_array($data) || $data === []) {
            return [
                'success' => false,
                'message' => 'Failed to parse generated JSON.',
                'debug' => $debug,
                'command' => $this->displayCommand($command),
                'output' => $output,
            ];
        }

        if (isset($data['auth_type'])) {
            $data['type'] = 'AUTHENTICATION_STORED_SPOTIFY_CREDENTIALS';
            unset($data['auth_type']);
        }

        if (isset($data['auth_data'])) {
            $data['credentials'] = $data['auth_data'];
            unset($data['auth_data']);
        }

        file_put_contents($targetPath, json_encode($data, JSON_PRETTY_PRINT));

        return [
            'success' => true,
            'message' => 'Spotify credentials generated and stored successfully!',
            'path' => $targetPath,
            'deviceName' => $deviceName,
        ];
    }

    private function currentUser(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (is_array($info) && isset($info['name'])) {
                return (string) $info['name'];
            }
        }

        $user = getenv('USER') ?: getenv('LOGNAME');

        return $user !== false && $user !== '' ? (string) $user : 'unknown';
    }

    private function displayCommand(array $command): string
    {
        return implode(' ', array_map(static fn ($part) => escapeshellarg((string) $part), $command));
    }
}
