<?php

/**
 * Пишет PERF, MEMORY, MEMORY_LEAKS, QUALITY, SECURITY, SECURITY_STANDARDS во все Comparison.md.
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
$memoryAvgPath = $root . '/var/benchmark/memory-avg.json';

if (!is_file($avgPath)) {
    fwrite(STDERR, "нет {$avgPath} — сначала: php tools/benchmark-competitors.php\n");
    exit(1);
}

if (!is_file($memoryAvgPath)) {
    fwrite(
        STDERR,
        "нет {$memoryAvgPath} — сначала: php -d xdebug.mode=off tools/benchmark-memory.php"
        . " && php tools/aggregate-memory-json.php\n",
    );
    exit(1);
}

$avgRaw = file_get_contents($avgPath);
$avg = is_string($avgRaw) ? json_decode($avgRaw, true) : null;

if (!is_array($avg) || !isset($avg['benches']) || !is_array($avg['benches'])) {
    fwrite(STDERR, "invalid comparison-avg.json\n");
    exit(1);
}

/** @var array<string, array{ns_per_op: float, memory_delta: int, available: bool}> $benches */
$benches = [];

foreach ($avg['benches'] as $benchName => $benchRow) {
    if (!is_string($benchName) || !is_array($benchRow)) {
        continue;
    }

    $nsPerOp = 0.0;
    $memoryDelta = 0;
    $available = ($benchRow['available'] ?? true) !== false;

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
        'available' => $available && $nsPerOp > 0.0,
    ];
}

$phpVersions = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

if (isset($avg['php_versions']) && is_array($avg['php_versions'])) {
    $parts = [];

    foreach (array_keys($avg['php_versions']) as $versionKey) {
        if (!isset($avg['php_versions'][$versionKey])) {
            continue;
        }

        if (is_string($avg['php_versions'][$versionKey])) {
            $parts[] = $avg['php_versions'][$versionKey];
        } elseif (is_int($avg['php_versions'][$versionKey]) || is_float($avg['php_versions'][$versionKey])) {
            $parts[] = (string) $avg['php_versions'][$versionKey];
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

/**
 * ns/op по ключу бенча или null если недоступен / нет данных.
 */
$nsOrNull = static function (string $key) use ($benches): ?float {
    $row = $benches[$key] ?? null;

    if ($row === null || !($row['available'] ?? false)) {
        return null;
    }

    return $row['ns_per_op'];
};

$memoryRaw = file_get_contents($memoryAvgPath);
$memoryAvg = is_string($memoryRaw) ? json_decode($memoryRaw, true) : null;

if (!is_array($memoryAvg) || !isset($memoryAvg['scenarios']) || !is_array($memoryAvg['scenarios'])) {
    fwrite(STDERR, "invalid memory-avg.json\n");
    exit(1);
}

/**
 * @return array{package_bytes: int, leak_bytes: int, iterations: int, available: bool}|null
 */
$memScenario = static function (string $key) use ($memoryAvg): ?array {
    $row = $memoryAvg['scenarios'][$key] ?? null;

    if (!is_array($row)) {
        return null;
    }

    $iterations = (int) ($row['iterations'] ?? 0);
    $available = ($row['available'] ?? true) !== false && $iterations > 0;

    if (!$available) {
        return null;
    }

    return [
        'package_bytes' => (int) ($row['package_bytes'] ?? 0),
        'leak_bytes' => (int) ($row['leak_bytes'] ?? 0),
        'iterations' => $iterations,
        'available' => true,
    ];
};

$oursHold = $memScenario('ours_hold_facade_stack');
$oursChurn = $memScenario('ours_churn_session_exec');
$oursPool = $memScenario('ours_churn_session_pool');
$phpseclibHold = $memScenario('phpseclib_hold_ssh2');
$spatieHold = $memScenario('spatie_hold_ssh');

/** Память пакета под нагрузкой (hold N объектов, emalloc − baseline). */
$loadHoldMem = [
    'ours' => $oursHold['package_bytes'] ?? null,
    'phpseclib/phpseclib' => $phpseclibHold['package_bytes'] ?? null,
    'spatie/ssh' => $spatieHold['package_bytes'] ?? null,
    'herzult/php-ssh' => null,
    'league/flysystem-sftp-v3' => null,
    'ext-ssh2' => null,
];

$holdN = $oursHold['iterations'] ?? 0;

$pkgHeader = [
    $productName,
    'phpseclib',
    'spatie/ssh',
    'php-ssh',
    'flysystem-sftp',
    'ext-ssh2',
];

/** @var list<list<string>> $perfBody */
$perfBody = [];

foreach (ComparisonLoader::perf() as $perfRow) {
    /** @var array<string, float|null> $values */
    $values = [];
    $cells = [$perfRow['metric'] . ' · PHP ' . $phpVersions];

    foreach ($order as $pkgKey) {
        $benchKey = $perfRow['benches'][$pkgKey] ?? null;

        if (!is_string($benchKey)) {
            $values[$pkgKey] = null;
            $cells[] = '—';

            continue;
        }

        $nsValue = $nsOrNull($benchKey);
        $values[$pkgKey] = $nsValue;
        $cells[] = $fmtNs($nsValue);
    }

    $cells[] = ComparisonMarkdown::minWinner($values, $order, $labels, $productName);
    $perfBody[] = $cells;
}

$perfIntro = 'Методика: `hrtime(true)` без Xdebug, прогрев + усреднение; '
    . '`—` — нет сопоставимого API '
    . "или пакет/расширение недоступны в окружении бенчмарка.\n\n";

$perfTable = $perfIntro . ComparisonMarkdown::table(
    array_merge(['Метрика'], $pkgHeader, ['🏆 Победитель']),
    $perfBody,
);

$memoryIntro = 'Методика: `memory_get_usage(false)` (emalloc) '
    . 'минус baseline пустого цикла — '
    . "аллокации **пакета** под нагрузкой, "
    . "не RSS/арена всего процесса.\n\n";

$memoryTable = $memoryIntro . ComparisonMarkdown::table(
    array_merge(['Метрика'], $pkgHeader, ['🏆 Победитель']),
    [
        [
            'Память пакета под нагрузкой (hold ×' . $holdN . ', emalloc − baseline)',
            $fmtMem($loadHoldMem['ours']),
            $fmtMem($loadHoldMem['phpseclib/phpseclib']),
            $fmtMem($loadHoldMem['spatie/ssh']),
            $fmtMem($loadHoldMem['herzult/php-ssh']),
            $fmtMem($loadHoldMem['league/flysystem-sftp-v3']),
            $fmtMem($loadHoldMem['ext-ssh2']),
            ComparisonMarkdown::minWinner($loadHoldMem, $order, $labels, $productName),
        ],
        [
            'Сессия+exec под нагрузкой (churn, прирост пакета)',
            $fmtMem($oursChurn['package_bytes'] ?? null),
            '—',
            '—',
            '—',
            '—',
            '—',
            $productName,
        ],
        [
            'SessionPool acquire/release (churn, прирост пакета)',
            $fmtMem($oursPool['package_bytes'] ?? null),
            '—',
            '—',
            '—',
            '—',
            '—',
            $productName,
        ],
    ],
);

$leakHold = [
    'ours' => $oursHold['leak_bytes'] ?? null,
    'phpseclib/phpseclib' => $phpseclibHold['leak_bytes'] ?? null,
    'spatie/ssh' => $spatieHold['leak_bytes'] ?? null,
    'herzult/php-ssh' => null,
    'league/flysystem-sftp-v3' => null,
    'ext-ssh2' => null,
];

$leakVerdict = static function (?int $bytes): string {
    if ($bytes === null) {
        return '—';
    }

    if ($bytes === 0) {
        return 'OK (0 B)';
    }

    if ($bytes <= 65536) {
        return 'leak (' . $bytes . ' B)';
    }

    return 'leak (' . sprintf('%.1f KiB', $bytes / 1024) . ')';
};

$leaksIntro = 'После нагрузки и `unset`+`gc_collect_cycles`+`gc_mem_caches`: '
    . 'остаточный прирост emalloc '
    . "пакета должен быть **0 B** (любой прирост = leak).\n\n";

$memoryLeaksTable = $leaksIntro . ComparisonMarkdown::table(
    array_merge(['Сценарий'], $pkgHeader, ['🏆 Победитель']),
    [
        [
            'После hold ×' . $holdN . ' + unset+gc',
            $leakVerdict($leakHold['ours']),
            $leakVerdict($leakHold['phpseclib/phpseclib']),
            $leakVerdict($leakHold['spatie/ssh']),
            $leakVerdict($leakHold['herzult/php-ssh']),
            $leakVerdict($leakHold['league/flysystem-sftp-v3']),
            $leakVerdict($leakHold['ext-ssh2']),
            ComparisonMarkdown::minWinner($leakHold, $order, $labels, $productName),
        ],
        [
            'После churn session+exec',
            $leakVerdict($oursChurn['leak_bytes'] ?? null),
            '—',
            '—',
            '—',
            '—',
            '—',
            $productName,
        ],
        [
            'После churn SessionPool',
            $leakVerdict($oursPool['leak_bytes'] ?? null),
            '—',
            '—',
            '—',
            '—',
            '—',
            $productName,
        ],
    ],
);

/**
 * @param list<array<string, mixed>> $rows
 */
$boolMatrixTable = static function (
    string $firstCol,
    array $rows,
    string $labelKey,
) use (
    $order,
    $labels,
    $productName,
    $pkgHeader
): string {
    /** @var list<list<string>> $body */
    $body = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $flagsRaw = $row['packages'] ?? null;

        if (!is_array($flagsRaw)) {
            continue;
        }

        /** @var array<string, bool> $flags */
        $flags = [];

        foreach (array_keys($flagsRaw) as $flagKey) {
            if (!is_string($flagKey)) {
                continue;
            }

            $flags[$flagKey] = ($flagsRaw[$flagKey] ?? false) === true;
        }

        $cells = [''];

        if (isset($row[$labelKey]) && is_string($row[$labelKey])) {
            $cells = [$row[$labelKey]];
        }

        foreach ($order as $key) {
            $cells[] = ($flags[$key] ?? false) ? '✅' : '❌';
        }

        $cells[] = ComparisonMarkdown::boolWinner($flags, $order, $labels, $productName);
        $body[] = $cells;
    }

    return ComparisonMarkdown::table(array_merge([$firstCol], $pkgHeader, ['🏆 Победитель']), $body);
};

$qualityTable = $boolMatrixTable('Метрика качества', ComparisonLoader::quality(), 'metric');

// SECURITY: краткая матрица security-фич из FEATURES
// (SSRF / host-key / timing-safe).
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
    $content = ComparisonMarkdown::replaceBlock(
        $content,
        '<!-- PERF:START -->',
        '<!-- PERF:END -->',
        $perfTable,
    );
    $content = ComparisonMarkdown::replaceBlock(
        $content,
        '<!-- MEMORY:START -->',
        '<!-- MEMORY:END -->',
        $memoryTable,
    );
    $content = ComparisonMarkdown::replaceBlock(
        $content,
        '<!-- MEMORY_LEAKS:START -->',
        '<!-- MEMORY_LEAKS:END -->',
        $memoryLeaksTable,
    );
    $content = ComparisonMarkdown::replaceBlock(
        $content,
        '<!-- QUALITY:START -->',
        '<!-- QUALITY:END -->',
        $qualityTable,
    );
    $content = ComparisonMarkdown::replaceBlock(
        $content,
        '<!-- SECURITY:START -->',
        '<!-- SECURITY:END -->',
        $securityTable,
    );
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
