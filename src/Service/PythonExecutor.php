<?php

namespace App\Service;

use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

class PythonExecutor
{
    private string $projectDir;
    private JsonStorage $storage;

    public function __construct(KernelInterface $kernel, JsonStorage $storage)
    {
        $this->projectDir = $kernel->getProjectDir();
        $this->storage = $storage;
    }

    /**
     * Tries to find the most suitable Python binary path.
     */
    public function getPythonBinary(): string
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $binaryName = $isWindows ? 'python.exe' : 'python3';
        $binDir = $isWindows ? 'Scripts' : 'bin';

        // 1. Check if we are in the Docker container (standard path)
        $containerVenv = '/opt/venv/' . $binDir . '/' . $binaryName;
        if (!$isWindows && file_exists($containerVenv)) {
            return $containerVenv;
        }

        // 2. Check configuration
        $config = $this->storage->get('config', []);
        $configuredVenv = $config['music_venv_path'] ?? 'venv';

        if (!empty($configuredVenv)) {
            // Check if absolute
            if (file_exists($configuredVenv)) {
                $p = rtrim($configuredVenv, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binDir . DIRECTORY_SEPARATOR . $binaryName;
                if (file_exists($p))
                    return $p;

                // Maybe the path already includes bin/python?
                if (str_contains($configuredVenv, $binaryName))
                    return $configuredVenv;
            }

            // Check relative to project dir
            $relativeVenv = $this->projectDir . DIRECTORY_SEPARATOR . $configuredVenv . DIRECTORY_SEPARATOR . $binDir . DIRECTORY_SEPARATOR . $binaryName;
            if (file_exists($relativeVenv)) {
                return $relativeVenv;
            }
        }

        // 3. Fallback to system python
        return $binaryName;
    }

    /**
     * Builds a shell command for a Python script.
     */
    public function buildCommand(string $scriptRelativePath, array $args = []): string
    {
        $python = $this->getPythonBinary();
        $script = $this->projectDir . DIRECTORY_SEPARATOR . ltrim($scriptRelativePath, DIRECTORY_SEPARATOR);

        $cmd = "\"$python\" \"$script\"";

        foreach ($args as $key => $value) {
            if (is_numeric($key)) {
                $cmd .= " \"$value\"";
            } else {
                $escapedValue = str_replace('"', '\"', (string) $value);
                $cmd .= " $key \"$escapedValue\"";
            }
        }

        return $cmd;
    }

    /**
     * Executes a Python script and returns the Process object.
     */
    public function execute(string $scriptRelativePath, array $args = [], int $timeout = 600): Process
    {
        $cmd = $this->buildCommand($scriptRelativePath, $args);
        $process = Process::fromShellCommandline($cmd);
        $process->setWorkingDirectory($this->projectDir);
        $process->setTimeout($timeout);
        return $process;
    }
}
