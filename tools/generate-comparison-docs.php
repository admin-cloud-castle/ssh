<?php

/**
 * Пишет PERF, MEMORY, QUALITY, SECURITY, SECURITY_STANDARDS во все Comparison.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CloudCastle\Ssh\Tools\ComparisonLoader;
use CloudCastle\Ssh\Tools\ComparisonMarkdown;

$root = dirname(__DIR__);
$packages = ComparisonLoader::packages();
$order = $packages['order'];
$labels = $packages['labels'];
$productName = 'cloud-castle/ssh';
$avgPath = $root . '/var/benchmark/comparison-avg.json';

if (!is_file($avgPath)) {
    fwrite(STDERR, "нет {$avgPath} — сначала: php tools/benchmark-competitors.php\n");
    exit(1);
}

$avgRaw = file_get_contents($avgPath);
$avg = is_string($avgRaw) ? json_decode($avgRaw, true) : null;

if (!is_array($avg) || !isset($avg['benches']) || !is_array($avg['benches'])) {
    fwrite(STDERR, "invalid comparison-avg.json\n");
    exit(1);
}

/** @var array<string, array{ns_per_op: float, memory_delta: int}> $benches */
$benches = [];

foreach ($avg['benches'] as $benchName => $benchRow) {
    if (!is_string($benchName) || !is_array($benchRow)) {
        continue;
    }

    $nsPerOp = 0.0;
    $memoryDelta = 0;

    if (
        array_key_exists('ns_per_op', $benchRow)
        && (is_int($benchRow['ns_per_op']) || is_float($benchRow['ns_per_op']))
    ) {
        $nsPerOp = (float) $benchRow['ns_per_op'];
    }

    if (array_key_exists('memory_delta', $benchRow) && is_int($benchRow['memory_delta'])) {
        $memoryDelta = $benchRow['memory_delta'];
    } elseif (array_key_exists('memory_delta', $benchRow) && is_float($benchRow['memory_delta'])) {
        $memoryDelta = (int) $benchRow['memory_delta'];
    }

    $benches[$benchName] = [
        'ns_per_op' => $nsPerOp,
        'memory_delta' => $memoryDelta,
    ];
}

$phpVersions = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

if (isset($avg['php_versions']) && is_array($avg['php_versions'])) {
    $parts = [];

    foreach ($avg['php_versions'] as $version) {
        if (is_string($version) || is_int($version) || is_float($version)) {
            $parts[] = (string) $version;
        }
    }

    if ($parts !== []) {
        $phpVersions = implode(', ', $parts);
    }
}

$fmtNs = static function (?float $ns): string {
    if ($ns === null || $ns <= 0.0) {
        return '—';
    }

    if ($ns >= 1_000_000.0) {
        return sprintf('%.2f ms', $ns / 1_000_000.0);
    }

    if ($ns >= 1000.0) {
        return sprintf('%.1f µs', $ns / 1000.0);
    }

    return sprintf('%.0f ns', $ns);
};

$fmtMem = static function (?int $bytes): string {
    if ($bytes === null) {
        return '—';
    }

    if (abs($bytes) >= 1024 * 1024) {
        return sprintf('%.2f MiB', $bytes / (1024 * 1024));
    }

    if (abs($bytes) >= 1024) {
        return sprintf('%.1f KiB', $bytes / 1024);
    }

    return $bytes . ' B';
};

$ns = static fn (string $key): float => $benches[$key]['ns_per_op'] ?? 0.0;
$mem = static fn (string $key): int => $benches[$key]['memory_delta'] ?? 0;

// Только сопоставимые конструкции клиента (не class_exists / function_exists).
$constructNs = [
    'ours' => $ns('ours_facade_construct'),
    'phpseclib/phpseclib' => $ns('phpseclib_ssh2_construct'),
    'spatie/ssh' => $ns('spatie_ssh_construct'),
    'herzult/php-ssh' => null,
    'league/flysystem-sftp-v3' => null,
    'ext-ssh2' => null,
];

$constructMem = [
    'ours' => $mem('ours_facade_construct'),
    'phpseclib/phpseclib' => $mem('phpseclib_ssh2_construct'),
    'spatie/ssh' => $mem('spatie_ssh_construct'),
    'herzult/php-ssh' => null,
    'league/flysystem-sftp-v3' => null,
    'ext-ssh2' => null,
];

$pkgHeader = [
    $productName,
    'phpseclib',
    'spatie/ssh',
    'php-ssh',
    'flysystem-sftp',
    'ext-ssh2',
];

$perfTable = ComparisonMarkdown::table(
    array_merge(['Метрика'], $pkgHeader, ['🏆 Победитель']),
    [
        array_merge(
            ['Конструкция клиента (ns/op, avg PHP ' . $phpVersions . ')'],
            [
                $fmtNs($constructNs['ours']),
                $fmtNs($constructNs['phpseclib/phpseclib']),
                $fmtNs($constructNs['spatie/ssh']),
                $fmtNs($constructNs['herzult/php-ssh']),
                $fmtNs($constructNs['league/flysystem-sftp-v3']),
                $fmtNs($constructNs['ext-ssh2']),
            ],
            [ComparisonMarkdown::minWinner($constructNs, $order, $labels, $productName)],
        ),
        [
            'AuthCredentials::withPassword (ns/op)',
            $fmtNs($ns('ours_credentials_password')),
            '—',
            '—',
            '—',
            '—',
            '—',
            $productName,
        ],
        [
            'EndpointGuard::prepare localhost (ns/op)',
            $fmtNs($ns('ours_endpoint_guard_prepare')),
            '—',
            '—',
            '—',
            '—',
            '—',
            $productName,
        ],
    ],
);

$memoryTable = ComparisonMarkdown::table(
    array_merge(['Метрика'], $pkgHeader, ['🏆 Победитель']),
    [
        array_merge(
            ['Δ памяти на конструкцию клиента'],
            [
                $fmtMem($constructMem['ours']),
                $fmtMem($constructMem['phpseclib/phpseclib']),
                $fmtMem($constructMem['spatie/ssh']),
                $fmtMem($constructMem['herzult/php-ssh']),
                $fmtMem($constructMem['league/flysystem-sftp-v3']),
                $fmtMem($constructMem['ext-ssh2']),
            ],
            [ComparisonMarkdown::minWinner($constructMem, $order, $labels, $productName)],
        ),
    ],
);

$boolMatrixTable = static function (
    string $firstCol,
    array $rows,
    string $labelKey,
) use ($order, $labels, $productName, $pkgHeader): string {
    $body = [];

    foreach ($rows as $row) {
        $flags = $row['packages'];
        $cells = [$row[$labelKey]];

        foreach ($order as $key) {
            $cells[] = ($flags[$key] ?? false) ? '✅' : '❌';
        }

        $cells[] = ComparisonMarkdown::boolWinner($flags, $order, $labels, $productName);
        $body[] = $cells;
    }

    return ComparisonMarkdown::table(array_merge([$firstCol], $pkgHeader, ['🏆 Победитель']), $body);
};

$qualityTable = $boolMatrixTable('Метрика качества', ComparisonLoader::quality(), 'metric');

// SECURITY: краткая матрица security-фич из FEATURES-пересечения (SSRF / host-key / timing-safe).
$securityFeatureRows = [
    [
        'feature' => 'SSRF-guard + DNS-pinning по умолчанию',
        'packages' => [
            'ours' => true,
            'phpseclib/phpseclib' => false,
            'spatie/ssh' => false,
            'herzult/php-ssh' => false,
            'league/flysystem-sftp-v3' => false,
            'ext-ssh2' => false,
        ],
    ],
    [
        'feature' => 'Строгая проверка host key',
        'packages' => [
            'ours' => true,
            'phpseclib/phpseclib' => true,
            'spatie/ssh' => false,
            'herzult/php-ssh' => false,
            'league/flysystem-sftp-v3' => true,
            'ext-ssh2' => true,
        ],
    ],
    [
        'feature' => 'Timing-safe fingerprint (hash_equals)',
        'packages' => [
            'ours' => true,
            'phpseclib/phpseclib' => false,
            'spatie/ssh' => false,
            'herzult/php-ssh' => false,
            'league/flysystem-sftp-v3' => false,
            'ext-ssh2' => false,
        ],
    ],
    [
        'feature' => 'Ноль Composer runtime-зависимостей (меньше attack surface)',
        'packages' => [
            'ours' => true,
            'phpseclib/phpseclib' => false,
            'spatie/ssh' => false,
            'herzult/php-ssh' => false,
            'league/flysystem-sftp-v3' => false,
            'ext-ssh2' => true,
        ],
    ],
];
$securityTable = $boolMatrixTable('Возможность безопасности', $securityFeatureRows, 'feature');
$standardsTable = $boolMatrixTable('Стандарт / практика', ComparisonLoader::security(), 'standard');

$targets = [
    'wiki/docs/ru/Comparison.md',
    'wiki/docs/en/Comparison.md',
    'wiki/docs/de/Comparison.md',
    'wiki/docs/fr/Comparison.md',
    'wiki/docs/es/Comparison.md',
    'wiki/docs/it/Comparison.md',
];

foreach ($targets as $rel) {
    $path = $root . '/' . $rel;
    $content = is_file($path) ? (string) file_get_contents($path) : '';
    $content = ComparisonMarkdown::ensureComparisonScaffold($content);
    $content = ComparisonMarkdown::replaceBlock($content, '<!-- PERF:START -->', '<!-- PERF:END -->', $perfTable);
    $content = ComparisonMarkdown::replaceBlock($content, '<!-- MEMORY:START -->', '<!-- MEMORY:END -->', $memoryTable);
    $content = ComparisonMarkdown::replaceBlock($content, '<!-- QUALITY:START -->', '<!-- QUALITY:END -->', $qualityTable);
    $content = ComparisonMarkdown::replaceBlock($content, '<!-- SECURITY:START -->', '<!-- SECURITY:END -->', $securityTable);
    $content = ComparisonMarkdown::replaceBlock(
        $content,
        '<!-- SECURITY_STANDARDS:START -->',
        '<!-- SECURITY_STANDARDS:END -->',
        $standardsTable,
    );
    // Убрать устаревший объединённый BENCH-блок, если остался.
    $content = preg_replace(
        '/\n*<!-- BENCH:START -->.*?<!-- BENCH:END -->\n*/s',
        "\n",
        $content,
    ) ?? $content;
    file_put_contents($path, $content);
    fwrite(STDOUT, "updated {$rel}\n");
}

fwrite(STDOUT, "generate-comparison-docs: OK\n");
