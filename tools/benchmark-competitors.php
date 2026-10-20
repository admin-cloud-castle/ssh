<?php

/**
 * Изолированные микро-бенчмарки vs аналоги → var/benchmark/comparison-<php>.json.
 * Запускать без Xdebug.
 */

declare(strict_types=1);

if (extension_loaded('xdebug') && (string) ini_get('xdebug.mode') !== 'off' && (string) ini_get('xdebug.mode') !== '') {
    fwrite(STDERR, "предупреждение: xdebug активен — ns/op зашумлены; запускайте с -d xdebug.mode=off\n");
}

require_once __DIR__ . '/../vendor/autoload.php';

use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Ssh;
use CloudCastle\Ssh\Security\EndpointGuard;

$iterations = 3000;
$warm = 50;

/**
 * @param callable():void $op
 *
 * @return array{ns_per_op: float, total_ns: int, memory_delta: int}
 */
$measure = static function (callable $op, int $iterations, int $warm): array {
    for ($i = 0; $i < $warm; ++$i) {
        $op();
    }

    gc_collect_cycles();
    $memBefore = memory_get_usage(true);
    $start = hrtime(true);

    for ($i = 0; $i < $iterations; ++$i) {
        $op();
    }

    $total = hrtime(true) - $start;
    gc_collect_cycles();
    $memAfter = memory_get_usage(true);

    return [
        'ns_per_op' => $total / max(1, $iterations),
        'total_ns' => $total,
        'memory_delta' => $memAfter - $memBefore,
    ];
};

$benches = [
    'ours_facade_construct' => static fn (): array => $measure(static function (): void {
        $ssh = new Ssh();
        unset($ssh);
    }, $iterations, $warm),
    'ours_credentials_password' => static fn (): array => $measure(static function (): void {
        $auth = AuthCredentials::withPassword('deploy', 'secret');
        unset($auth);
    }, $iterations, $warm),
    'ours_options_defaults' => static fn (): array => $measure(static function (): void {
        $options = ConnectionOptions::defaults()->allowPrivateAddresses();
        unset($options);
    }, $iterations, $warm),
    'ours_endpoint_guard_prepare' => static function () use ($measure, $iterations, $warm): array {
        $guard = new EndpointGuard();
        $options = ConnectionOptions::defaults()->allowPrivateAddresses();

        return $measure(static function () use ($guard, $options): void {
            $pinned = $guard->prepare('127.0.0.1:22', $options);
            unset($pinned);
        }, $iterations, $warm);
    },
    'phpseclib_ssh2_construct' => static fn (): array => $measure(static function (): void {
        if (class_exists(\phpseclib3\Net\SSH2::class)) {
            $client = new \phpseclib3\Net\SSH2('127.0.0.1');
            unset($client);
        }
    }, $iterations, $warm),
    'spatie_ssh_construct' => static fn (): array => $measure(static function (): void {
        if (class_exists(\Spatie\Ssh\Ssh::class)) {
            $client = \Spatie\Ssh\Ssh::create('user', '127.0.0.1');
            unset($client);
        }
    }, $iterations, $warm),
    'herzult_class_exists' => static fn (): array => $measure(static function (): void {
        class_exists('Ssh\\Session');
    }, $iterations, $warm),
    'flysystem_sftp_class_exists' => static fn (): array => $measure(static function (): void {
        class_exists('League\\Flysystem\\PhpseclibV3\\SftpAdapter');
    }, $iterations, $warm),
    'ext_ssh2_function_exists' => static fn (): array => $measure(static function (): void {
        function_exists('ssh2_connect');
    }, $iterations, $warm),
];

$results = [
    'php' => PHP_VERSION,
    'php_id' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
    'iterations' => $iterations,
    'generated_at' => gmdate('c'),
    'benches' => [],
];

foreach ($benches as $name => $runner) {
    $results['benches'][$name] = $runner();
    fwrite(STDOUT, sprintf(
        "%s: %.0f ns/op, mem_delta=%d\n",
        $name,
        $results['benches'][$name]['ns_per_op'],
        $results['benches'][$name]['memory_delta'],
    ));
}

$dir = __DIR__ . '/../var/benchmark';

if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
    fwrite(STDERR, "cannot create {$dir}\n");
    exit(1);
}

$path = $dir . '/comparison-' . $results['php_id'] . '.json';
$encoded = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($encoded === false) {
    fwrite(STDERR, "json_encode failed\n");
    exit(1);
}

file_put_contents($path, $encoded . "\n");
fwrite(STDOUT, "wrote {$path}\n");
