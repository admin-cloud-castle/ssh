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

$toInt = static function (mixed $value): int {
    if (is_int($value)) {
        return $value;
    }

    if (is_float($value) || is_string($value)) {
        return (int) $value;
    }

    return 0;
};

/** @return array<array-key, mixed>|null */
$toArray = (static fn (mixed $value): ?array => is_array($value) ? $value : null);

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

    $scenariosInFile = $data['scenarios'];

    foreach (array_keys($scenariosInFile) as $name) {
        if (!is_string($name)) {
            continue;
        }

        $row = $toArray($scenariosInFile[$name] ?? null);

        if ($row === null) {
            continue;
        }

        $acc[$name][] = [
            'package_bytes' => $toInt($row['package_bytes'] ?? null),
            'baseline_bytes' => $toInt($row['baseline_bytes'] ?? null),
            'retained_bytes' => $toInt($row['retained_bytes'] ?? null),
            'leak_bytes' => $toInt($row['leak_bytes'] ?? null),
            'iterations' => $toInt($row['iterations'] ?? null),
        ];
    }
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
        'available' => true,
    ];
}

$out = [
    'php_versions' => array_values(array_unique($phpVersions)),
    'method' => 'emalloc_minus_empty_loop_baseline',
    'generated_at' => gmdate('c'),
    'scenarios' => $scenarios,
];

$path = $dir . '/memory-avg.json';
$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    fwrite(STDERR, "json_encode failed\n");
    exit(1);
}
file_put_contents($path, $json . "\n");
fwrite(STDOUT, "wrote {$path}\n");
