<?php

/**
 * Изолированные микро-бенчмарки vs аналоги → var/benchmark/comparison-<php>.json.
 * Запускать без Xdebug. Недоступный пакет → available=false (в таблице —).
 */

declare(strict_types=1);

if (extension_loaded('xdebug') && (string) ini_get('xdebug.mode') !== 'off' && (string) ini_get('xdebug.mode') !== '') {
    fwrite(
        STDERR,
        'предупреждение: xdebug активен — ns/op зашумлены;'
        . " запускайте с -d xdebug.mode=off\n",
    );
}

require_once __DIR__ . '/../vendor/autoload.php';

const BENCH_LOOPBACK_HOST = '127.0.0.1';

use CloudCastle\Ssh\Client\SessionFactory;
use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Pool\SessionPool;
use CloudCastle\Ssh\Security\EndpointGuard;
use CloudCastle\Ssh\Ssh;
use CloudCastle\Ssh\Tests\Support\MockSshPeer;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;

$iterations = 2500;
$warm = 40;
$sessionIterations = 20;
$sessionWarm = 3;

/**
 * @param callable():void $op
 *
 * @return array{ns_per_op: float, total_ns: int, memory_delta: int, available: bool}
 */
$measure = static function (callable $op, int $iterations, int $warm): array {
    for ($i = 0; $i < $warm; ++$i) {
        $op();
    }

    gc_collect_cycles();
    $memBefore = memory_get_usage(false);
    $start = hrtime(true);

    for ($i = 0; $i < $iterations; ++$i) {
        $op();
    }

    $total = hrtime(true) - $start;
    gc_collect_cycles();
    $memAfter = memory_get_usage(false);

    return [
        'ns_per_op' => $total / max(1, $iterations),
        'total_ns' => $total,
        'memory_delta' => max(0, $memAfter - $memBefore),
        'available' => true,
    ];
};

$unavailable = static fn (): array => [
    'ns_per_op' => 0.0,
    'total_ns' => 0,
    'memory_delta' => 0,
    'available' => false,
];

$hasPhpsec = class_exists(\phpseclib3\Net\SSH2::class);
$hasSpatie = class_exists(\Spatie\Ssh\Ssh::class);
$hasFly = class_exists(SftpAdapter::class) && class_exists(SftpConnectionProvider::class);

$benches = [];

$benches['ours_client_construct'] = static fn (): array => $measure(static function (): void {
    $client = new Ssh();
    unset($client);
}, $iterations, $warm);

$benches['phpseclib_client_construct'] = static function () use (
    $measure,
    $iterations,
    $warm,
    $unavailable,
    $hasPhpsec
): array {
    if (!$hasPhpsec) {
        return $unavailable();
    }

    return $measure(static function (): void {
        $client = new \phpseclib3\Net\SSH2(BENCH_LOOPBACK_HOST);
        unset($client);
    }, $iterations, $warm);
};

$benches['spatie_client_construct'] = static function () use (
    $measure,
    $iterations,
    $warm,
    $unavailable,
    $hasSpatie
): array {
    if (!$hasSpatie) {
        return $unavailable();
    }

    return $measure(static function (): void {
        $client = \Spatie\Ssh\Ssh::create('user', BENCH_LOOPBACK_HOST);
        unset($client);
    }, $iterations, $warm);
};

$benches['herzult_client_construct'] = $unavailable;
$benches['flysystem_client_construct'] = static function () use (
    $measure,
    $iterations,
    $warm,
    $unavailable,
    $hasFly
): array {
    if (!$hasFly) {
        return $unavailable();
    }

    return $measure(static function (): void {
        $provider = SftpConnectionProvider::fromArray([
            'host' => BENCH_LOOPBACK_HOST,
            'username' => 'user',
            'password' => 'secret',
            'port' => 22,
        ]);
        $adapter = new SftpAdapter($provider, '/');
        unset($adapter, $provider);
    }, $iterations, $warm);
};
$benches['ext_ssh2_client_construct'] = $unavailable;

$benches['ours_password_auth_setup'] = static fn (): array => $measure(static function (): void {
    $auth = AuthCredentials::withPassword('deploy', 'secret');
    unset($auth);
}, $iterations, $warm);

$benches['phpseclib_password_auth_setup'] = static function () use (
    $measure,
    $iterations,
    $warm,
    $unavailable,
    $hasPhpsec
): array {
    if (!$hasPhpsec) {
        return $unavailable();
    }

    return $measure(static function (): void {
        $client = new \phpseclib3\Net\SSH2(BENCH_LOOPBACK_HOST);
        $user = 'deploy';
        $pass = 'secret';
        unset($client, $user, $pass);
    }, $iterations, $warm);
};

$benches['spatie_password_auth_setup'] = static function () use (
    $measure,
    $iterations,
    $warm,
    $unavailable,
    $hasSpatie
): array {
    if (!$hasSpatie) {
        return $unavailable();
    }

    return $measure(static function (): void {
        $client = \Spatie\Ssh\Ssh::create('deploy', BENCH_LOOPBACK_HOST)->usePassword('secret');
        unset($client);
    }, $iterations, $warm);
};

$benches['herzult_password_auth_setup'] = $unavailable;
$benches['flysystem_password_auth_setup'] = static function () use (
    $measure,
    $iterations,
    $warm,
    $unavailable,
    $hasFly
): array {
    if (!$hasFly) {
        return $unavailable();
    }

    return $measure(static function (): void {
        $provider = SftpConnectionProvider::fromArray([
            'host' => BENCH_LOOPBACK_HOST,
            'username' => 'deploy',
            'password' => 'secret',
            'port' => 22,
        ]);
        unset($provider);
    }, $iterations, $warm);
};
$benches['ext_ssh2_password_auth_setup'] = $unavailable;

$benches['ours_timeout_configure'] = static fn (): array => $measure(static function (): void {
    $options = new ConnectionOptions(10.0, 30.0, false);
    unset($options);
}, $iterations, $warm);

$benches['phpseclib_timeout_configure'] = static function () use (
    $measure,
    $iterations,
    $warm,
    $unavailable,
    $hasPhpsec
): array {
    if (!$hasPhpsec) {
        return $unavailable();
    }

    return $measure(static function (): void {
        $client = new \phpseclib3\Net\SSH2(BENCH_LOOPBACK_HOST);
        $client->setTimeout(10);
        unset($client);
    }, $iterations, $warm);
};

$benches['spatie_timeout_configure'] = static function () use (
    $measure,
    $iterations,
    $warm,
    $unavailable,
    $hasSpatie
): array {
    if (!$hasSpatie) {
        return $unavailable();
    }

    return $measure(static function (): void {
        $client = \Spatie\Ssh\Ssh::create('user', BENCH_LOOPBACK_HOST)->setTimeout(10);
        unset($client);
    }, $iterations, $warm);
};

$benches['flysystem_timeout_configure'] = static function () use (
    $measure,
    $iterations,
    $warm,
    $unavailable,
    $hasFly
): array {
    if (!$hasFly) {
        return $unavailable();
    }

    return $measure(static function (): void {
        $provider = SftpConnectionProvider::fromArray([
            'host' => BENCH_LOOPBACK_HOST,
            'username' => 'user',
            'password' => 'secret',
            'port' => 22,
            'timeout' => 10,
        ]);
        unset($provider);
    }, $iterations, $warm);
};

$benches['ours_port_configure'] = static function () use ($measure, $iterations, $warm): array {
    $guard = new EndpointGuard();
    $options = ConnectionOptions::defaults()->allowPrivateAddresses();

    return $measure(static function () use ($guard, $options): void {
        $pinned = $guard->prepare(BENCH_LOOPBACK_HOST . ':2222', $options);
        unset($pinned);
    }, $iterations, $warm);
};

$benches['phpseclib_port_configure'] = static function () use (
    $measure,
    $iterations,
    $warm,
    $unavailable,
    $hasPhpsec
): array {
    if (!$hasPhpsec) {
        return $unavailable();
    }

    return $measure(static function (): void {
        $client = new \phpseclib3\Net\SSH2(BENCH_LOOPBACK_HOST, 2222);
        unset($client);
    }, $iterations, $warm);
};

$benches['spatie_port_configure'] = static function () use (
    $measure,
    $iterations,
    $warm,
    $unavailable,
    $hasSpatie
): array {
    if (!$hasSpatie) {
        return $unavailable();
    }

    return $measure(static function (): void {
        $client = \Spatie\Ssh\Ssh::create('user', BENCH_LOOPBACK_HOST)->usePort(2222);
        unset($client);
    }, $iterations, $warm);
};

$benches['flysystem_port_configure'] = static function () use (
    $measure,
    $iterations,
    $warm,
    $unavailable,
    $hasFly
): array {
    if (!$hasFly) {
        return $unavailable();
    }

    return $measure(static function (): void {
        $provider = SftpConnectionProvider::fromArray([
            'host' => BENCH_LOOPBACK_HOST,
            'username' => 'user',
            'password' => 'secret',
            'port' => 2222,
        ]);
        unset($provider);
    }, $iterations, $warm);
};

$benches['ours_hostkey_policy'] = static fn (): array => $measure(static function (): void {
    $options = ConnectionOptions::defaults()->withTrustedHostKeys([], false);
    unset($options);
}, $iterations, $warm);

$benches['spatie_hostkey_policy'] = static function () use (
    $measure,
    $iterations,
    $warm,
    $unavailable,
    $hasSpatie
): array {
    if (!$hasSpatie) {
        return $unavailable();
    }

    return $measure(static function (): void {
        $client = \Spatie\Ssh\Ssh::create('user', BENCH_LOOPBACK_HOST)->disableStrictHostKeyChecking();
        unset($client);
    }, $iterations, $warm);
};

$benches['ours_endpoint_prepare'] = static function () use ($measure, $iterations, $warm): array {
    $guard = new EndpointGuard();
    $options = ConnectionOptions::defaults()->allowPrivateAddresses();

    return $measure(static function () use ($guard, $options): void {
        $pinned = $guard->prepare(BENCH_LOOPBACK_HOST . ':22', $options);
        unset($pinned);
    }, $iterations, $warm);
};

$benches['phpseclib_endpoint_prepare'] = static function () use (
    $measure,
    $iterations,
    $warm,
    $unavailable,
    $hasPhpsec
): array {
    if (!$hasPhpsec) {
        return $unavailable();
    }

    return $measure(static function (): void {
        $client = new \phpseclib3\Net\SSH2(BENCH_LOOPBACK_HOST, 22);
        unset($client);
    }, $iterations, $warm);
};

$benches['spatie_endpoint_prepare'] = static function () use (
    $measure,
    $iterations,
    $warm,
    $unavailable,
    $hasSpatie
): array {
    if (!$hasSpatie) {
        return $unavailable();
    }

    return $measure(static function (): void {
        $client = \Spatie\Ssh\Ssh::create('user', BENCH_LOOPBACK_HOST)->usePort(22);
        unset($client);
    }, $iterations, $warm);
};

$benches['flysystem_endpoint_prepare'] = static function () use (
    $measure,
    $iterations,
    $warm,
    $unavailable,
    $hasFly
): array {
    if (!$hasFly) {
        return $unavailable();
    }

    return $measure(static function (): void {
        $provider = SftpConnectionProvider::fromArray([
            'host' => BENCH_LOOPBACK_HOST,
            'username' => 'user',
            'password' => 'secret',
            'port' => 22,
        ]);
        unset($provider);
    }, $iterations, $warm);
};

$benches['ours_full_client_stack'] = static function () use ($measure, $iterations, $warm): array {
    $guard = new EndpointGuard();

    return $measure(static function () use ($guard): void {
        $ssh = new Ssh();
        $auth = AuthCredentials::withPassword('deploy', 'secret');
        $options = (new ConnectionOptions(10.0, 30.0, false))->allowPrivateAddresses();
        $pinned = $guard->prepare(BENCH_LOOPBACK_HOST . ':22', $options);
        unset($ssh, $auth, $options, $pinned);
    }, $iterations, $warm);
};

$benches['phpseclib_full_client_stack'] = static function () use (
    $measure,
    $iterations,
    $warm,
    $unavailable,
    $hasPhpsec
): array {
    if (!$hasPhpsec) {
        return $unavailable();
    }

    return $measure(static function (): void {
        $client = new \phpseclib3\Net\SSH2(BENCH_LOOPBACK_HOST, 22);
        $client->setTimeout(10);
        $user = 'deploy';
        $pass = 'secret';
        unset($client, $user, $pass);
    }, $iterations, $warm);
};

$benches['spatie_full_client_stack'] = static function () use (
    $measure,
    $iterations,
    $warm,
    $unavailable,
    $hasSpatie
): array {
    if (!$hasSpatie) {
        return $unavailable();
    }

    return $measure(static function (): void {
        $client = \Spatie\Ssh\Ssh::create('deploy', BENCH_LOOPBACK_HOST)
            ->usePassword('secret')
            ->usePort(22)
            ->setTimeout(10)
            ->disableStrictHostKeyChecking();
        unset($client);
    }, $iterations, $warm);
};

$benches['flysystem_full_client_stack'] = static function () use (
    $measure,
    $iterations,
    $warm,
    $unavailable,
    $hasFly
): array {
    if (!$hasFly) {
        return $unavailable();
    }

    return $measure(static function (): void {
        $provider = SftpConnectionProvider::fromArray([
            'host' => BENCH_LOOPBACK_HOST,
            'username' => 'deploy',
            'password' => 'secret',
            'port' => 22,
            'timeout' => 10,
        ]);
        $adapter = new SftpAdapter($provider, '/');
        unset($adapter, $provider);
    }, $iterations, $warm);
};

$benches['ours_session_connect_exec'] = static function () use ($measure, $sessionIterations, $sessionWarm): array {
    $factory = new SessionFactory();
    $auth = AuthCredentials::withPassword('u', 'p');
    $options = ConnectionOptions::defaults()->allowPrivateAddresses();

    return $measure(static function () use ($factory, $auth, $options): void {
        [$port, $process] = MockSshPeer::listen();

        try {
            $session = $factory->connect(BENCH_LOOPBACK_HOST . ':' . $port, $auth, $options);
            $session->exec('id');
            $session->disconnect();
        } finally {
            MockSshPeer::stop($process);
        }
    }, $sessionIterations, $sessionWarm);
};

$benches['ours_session_pool_cycle'] = static function () use ($measure, $sessionIterations, $sessionWarm): array {
    $pool = new SessionPool(new SessionFactory(), 2);
    $auth = AuthCredentials::withPassword('u', 'p');
    $options = ConnectionOptions::defaults()->allowPrivateAddresses();

    return $measure(static function () use ($pool, $auth, $options): void {
        [$port, $process] = MockSshPeer::listen();
        $endpoint = BENCH_LOOPBACK_HOST . ':' . $port;

        try {
            $session = $pool->acquire($endpoint, $auth, $options);
            $session->exec('id');
            $pool->release($session);
            $pool->drain();
        } finally {
            MockSshPeer::stop($process);
        }
    }, $sessionIterations, $sessionWarm);
};

$results = [
    'php' => PHP_VERSION,
    'php_id' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
    'iterations' => $iterations,
    'session_iterations' => $sessionIterations,
    'generated_at' => gmdate('c'),
    'benches' => [],
];

foreach ($benches as $name => $runner) {
    /** @var array{available: bool, ns_per_op: float|int, total_ns: int, memory_delta: int} $row */
    $row = $runner();
    $results['benches'][$name] = $row;
    fwrite(STDOUT, sprintf(
        "%s: %s %.0f ns/op\n",
        $name,
        ($row['available'] ?? true) ? 'ok' : 'n/a',
        (float) $row['ns_per_op'],
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
