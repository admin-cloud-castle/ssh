<?php

/**
 * Усредняет var/benchmark/memory-*.json → memory-avg.json.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$dir = $root . '/var/benchmark';
$globbed = glob($dir . '/memory-*.json');
$files = is_array($globbed) ? $globbed : [];
$files = array_values(array_filter(
    $files,
    static fn (string $path): bool => !str_ends_with($path, 'memory-avg.json'),
));

if ($files === []) {
    fwrite(STDERR, "нет memory-*.json — сначала: php -d xdebug.mode=off tools/benchmark-memory.php\n");
    exit(1);
}

/** @var array<string, list<array{package_bytes: int, baseline_bytes: int, retained_bytes: int, leak_bytes: int, iterations: int}>> $acc */
$acc = [];
$phpVersions = [];

foreach ($files as $file) {
    $raw = file_get_contents($file);

    if (!is_string($raw)) {
        continue;
    }

    $data = json_decode($raw, true);

    if (!is_array($data) || !isset($data['scenarios']) || !is_array($data['scenarios'])) {
        continue;
    }

    if (isset($data['php']) && is_string($data['php'])) {
        $phpVersions[] = $data['php'];
    }

    foreach ($data['scenarios'] as $name => $row) {
        if (!is_string($name) || !is_array($row)) {
            continue;
        }

        $acc[$name][] = [
            'package_bytes' => memoryInt($row['package_bytes'] ?? null),
            'baseline_bytes' => memoryInt($row['baseline_bytes'] ?? null),
            'retained_bytes' => memoryInt($row['retained_bytes'] ?? null),
            'leak_bytes' => memoryInt($row['leak_bytes'] ?? null),
            'iterations' => memoryInt($row['iterations'] ?? null),
        ];
    }
}

/**
 * Приводит JSON-число к int (mixed из json_decode).
 */
function memoryInt(mixed $value): int
{
    if (is_int($value)) {
        return $value;
    }

    if (is_float($value) || is_string($value)) {
        return (int) $value;
    }

    return 0;
}

$scenarios = [];

foreach ($acc as $name => $samples) {
    $n = count($samples);
    $sumPkg = 0;
    $sumBase = 0;
    $sumRet = 0;
    $sumLeak = 0;
    $sumIter = 0;

    foreach ($samples as $sample) {
        $sumPkg += $sample['package_bytes'];
        $sumBase += $sample['baseline_bytes'];
        $sumRet += $sample['retained_bytes'];
        $sumLeak += $sample['leak_bytes'];
        $sumIter += $sample['iterations'];
    }

    $scenarios[$name] = [
        'package_bytes' => (int) round($sumPkg / max(1, $n)),
        'baseline_bytes' => (int) round($sumBase / max(1, $n)),
        'retained_bytes' => (int) round($sumRet / max(1, $n)),
        'leak_bytes' => (int) round($sumLeak / max(1, $n)),
        'iterations' => (int) round($sumIter / max(1, $n)),
        'samples' => $n,
    ];
}

$out = [
    'php_versions' => array_values(array_unique($phpVersions)),
    'method' => 'emalloc_minus_empty_loop_baseline',
    'generated_at' => gmdate('c'),
    'scenarios' => $scenarios,
];

$path = $dir . '/memory-avg.json';
$encoded = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($encoded === false) {
    fwrite(STDERR, "json_encode failed\n");
    exit(1);
}

file_put_contents($path, $encoded . "\n");
fwrite(STDOUT, "wrote {$path}\n");
