<?php

/**
 * Усредняет var/benchmark/comparison-*.json → var/benchmark/comparison-avg.json.
 */

declare(strict_types=1);

$dir = __DIR__ . '/../var/benchmark';
$globbed = glob($dir . '/comparison-*.json');
$files = is_array($globbed) ? $globbed : [];
$files = array_values(array_filter($files, static fn (string $f): bool => !str_ends_with($f, 'comparison-avg.json')));

if ($files === []) {
    fwrite(STDERR, "нет comparison-*.json — сначала php tools/benchmark-competitors.php\n");
    exit(1);
}

/** @var array<string, list<array{ns_per_op: float, memory_delta: int}>> $acc */
$acc = [];
/** @var list<string> $phps */
$phps = [];

foreach ($files as $file) {
    $raw = file_get_contents($file);

    if ($raw === false) {
        continue;
    }

    $data = json_decode($raw, true);

    if (!is_array($data) || !isset($data['benches']) || !is_array($data['benches'])) {
        continue;
    }

    if (array_key_exists('php_id', $data) && is_string($data['php_id'])) {
        $phps[] = $data['php_id'];
    } else {
        $phps[] = basename($file);
    }

    foreach (array_keys($data['benches']) as $name) {
        if (!is_string($name)) {
            continue;
        }

        $benchRow = $data['benches'][$name];

        if (!is_array($benchRow)) {
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

        $acc[$name][] = [
            'ns_per_op' => $nsPerOp,
            'memory_delta' => $memoryDelta,
        ];
    }
}

$avg = [
    'php_versions' => array_values(array_unique($phps)),
    'samples' => count($files),
    'generated_at' => gmdate('c'),
    'benches' => [],
];

foreach ($acc as $name => $samples) {
    $n = count($samples);
    $sumNs = 0.0;
    $sumMem = 0;

    foreach ($samples as $sample) {
        $sumNs += $sample['ns_per_op'];
        $sumMem += $sample['memory_delta'];
    }

    $avg['benches'][$name] = [
        'ns_per_op' => $sumNs / (float) max(1, $n),
        'memory_delta' => (int) round($sumMem / max(1, $n)),
    ];
}

$path = $dir . '/comparison-avg.json';
$encoded = json_encode($avg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($encoded === false) {
    fwrite(STDERR, "json_encode failed\n");
    exit(1);
}

file_put_contents($path, $encoded . "\n");
fwrite(STDOUT, "wrote {$path}\n");
