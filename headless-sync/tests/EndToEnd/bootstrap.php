<?php
/**
 * E2E Test Suite Bootstrap File
 */

$autoloader = dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!file_exists($autoloader)) {
    throw new RuntimeException("Composer autoloader not found at '{$autoloader}'. Run 'composer install' in the plugin directory.");
}

require_once $autoloader;

// Auto-start PHP development server on port 9000 if not already running
if (@fsockopen('127.0.0.1', 9000) === false) {
    $process = new \Symfony\Component\Process\Process([
        'php',
        '-S',
        '0.0.0.0:9000',
        dirname(__DIR__, 2) . '/delivery-api.php'
    ]);
    $process->start();

    register_shutdown_function(function() use ($process) {
        $process->stop();
    });

    // Wait for the server to spin up
    usleep(500000); // 0.5s
}

// Optional: Load a local .env.testing file if it exists at the project or test root
$envFile = dirname(__DIR__, 2) . '/.env.testing';
if (file_exists($envFile)) {
    if (is_readable($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim(trim($value), '"\''); // Strip quotes
                if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                    putenv("{$name}={$value}");
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
    }
}
