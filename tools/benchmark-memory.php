<?php

/**
 * Замер памяти пакета под нагрузкой (emalloc − baseline) vs аналоги.
 * Результат: var/benchmark/memory-<php>.json.
 */

declare(strict_types=1);

if (extension_loaded('xdebug') && (string) ini_get('xdebug.mode') !== 'off' && (string) ini_get('xdebug.mode') !== '') {
    fwrite(
        STDERR,
        'предупреждение: xdebug активен — память завышена;'
        . " запускайте с -d xdebug.mode=off\n",
    );
}

require_once __DIR__ . '/../vendor/autoload.php';

use CloudCastle\Ssh\Client\SessionFactory;
use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Pool\SessionPool;
use CloudCastle\Ssh\Security\EndpointGuard;
use CloudCastle\Ssh\Ssh;
use CloudCastle\Ssh\Tests\Support\MockSshPeer;
use CloudCastle\Ssh\Tools\PackageMemoryMeter;

$holdIterations = 400;
$churnSessions = 25;
$warm = 15;

$options = ConnectionOptions::defaults()->allowPrivateAddresses();
$auth = AuthCredentials::withPassword('user', 'secret');

$emptyResult = static fn (): array => [
    'package_bytes' => 0,
    'baseline_bytes' => 0,
    'retained_bytes' => 0,
    'leak_bytes' => 0,
    'iterations' => 0,
    'available' => false,
];

$scenarios = [
    'ours_hold_facade_stack' => static function () use ($holdIterations, $warm, $options): array {
        $guard = new EndpointGuard();
        $row = PackageMemoryMeter::measureHold(static fn (): array => [
            new Ssh(),
            AuthCredentials::withPassword('deploy', 'secret'),
            $guard->prepare('127.0.0.1:22', $options),
        ], $holdIterations, $warm);
        $row['available'] = true;

        return $row;
    },
    'ours_churn_session_exec' => static function () use ($churnSessions, $warm, $auth, $options): array {
        $factory = new SessionFactory();
        $row = PackageMemoryMeter::measureChurn(static function () use ($factory, $auth, $options): void {
            [$port, $process] = MockSshPeer::listen();

            try {
                $session = $factory->connect('127.0.0.1:' . $port, $auth, $options);
                $session->exec('uname -a');
                $session->disconnect();
            } finally {
                MockSshPeer::stop($process);
            }
        }, $churnSessions, max(2, intdiv($warm, 3)));
        $row['available'] = true;

        return $row;
    },
    'ours_churn_session_pool' => static function () use ($churnSessions, $warm, $auth, $options): array {
        $pool = new SessionPool(new SessionFactory(), 2);
        $row = PackageMemoryMeter::measureChurn(static function () use ($pool, $auth, $options): void {
            [$port, $process] = MockSshPeer::listen();
            $endpoint = '127.0.0.1:' . $port;

            try {
                $session = $pool->acquire($endpoint, $auth, $options);
                $session->exec('id');
                $pool->release($session);
                $pool->drain();
            } finally {
                MockSshPeer::stop($process);
            }
        }, max(12, intdiv($churnSessions, 2)), max(2, intdiv($warm, 4)));
        $row['available'] = true;

        return $row;
    },
    'phpseclib_hold_ssh2' => static function () use ($holdIterations, $warm, $emptyResult): array {
        if (!class_exists(\phpseclib3\Net\SSH2::class)) {
            return $emptyResult();
        }

        $row = PackageMemoryMeter::measureHold(
            static fn (): \phpseclib3\Net\SSH2 => new \phpseclib3\Net\SSH2('127.0.0.1'),
            $holdIterations,
            $warm,
        );
        $row['available'] = true;

        return $row;
    },
    'spatie_hold_ssh' => static function () use ($holdIterations, $warm, $emptyResult): array {
        if (!class_exists(\Spatie\Ssh\Ssh::class)) {
            return $emptyResult();
        }

        $row = PackageMemoryMeter::measureHold(
            static fn (): \Spatie\Ssh\Ssh => \Spatie\Ssh\Ssh::create('user', '127.0.0.1'),
            $holdIterations,
            $warm,
        );
        $row['available'] = true;

        return $row;
    },
];

$results = [
    'php' => PHP_VERSION,
    'php_id' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
    'hold_iterations' => $holdIterations,
    'churn_sessions' => $churnSessions,
    'method' => 'emalloc_minus_empty_loop_baseline',
    'note' => 'package_bytes = retained/churn − baseline пустого цикла; '
        . 'leak_bytes — остаток после unset+gc (hold) или прирост после churn',
    'generated_at' => gmdate('c'),
    'scenarios' => [],
];

foreach ($scenarios as $name => $runner) {
    $row = $runner();
    $results['scenarios'][$name] = $row;
    fwrite(STDOUT, sprintf(
        "%s: package=%d B, retained=%d B, leak=%d B, baseline=%d B (n=%d)\n",
        $name,
        $row['package_bytes'],
        $row['retained_bytes'],
        $row['leak_bytes'],
        $row['baseline_bytes'],
        $row['iterations'],
    ));
}

$dir = __DIR__ . '/../var/benchmark';

if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
    fwrite(STDERR, "cannot create {$dir}\n");
    exit(1);
}

$path = $dir . '/memory-' . $results['php_id'] . '.json';
$encoded = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($encoded === false) {
    fwrite(STDERR, "json_encode failed\n");
    exit(1);
}

file_put_contents($path, $encoded . "\n");
fwrite(STDOUT, "wrote {$path}\n");
