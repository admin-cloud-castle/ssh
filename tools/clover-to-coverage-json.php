<?php

/**
 * clover.xml → shields.io endpoint JSON (бесплатный бейдж покрытия без Codecov).
 *
 * @see https://shields.io/badges/endpoint-badge
 */

declare(strict_types=1);

$cloverPath = $argv[1] ?? 'var/coverage/clover.xml';
$outPath = $argv[2] ?? 'var/coverage/coverage.json';

if (!is_file($cloverPath)) {
    fwrite(STDERR, "Файл покрытия не найден: {$cloverPath}\n");
    exit(1);
}

$xml = new SimpleXMLElement((string) file_get_contents($cloverPath));
$metricsNodes = $xml->xpath('//project/metrics|//metrics');
$metrics = (is_array($metricsNodes) && isset($metricsNodes[0])) ? $metricsNodes[0] : null;

if ($metrics === null) {
    fwrite(STDERR, "Не удалось прочитать metrics из clover\n");
    exit(1);
}

$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $statements > 0
    ? round(((float) $covered / (float) $statements) * 100.0, 2)
    : 100.0;

$color = 'red';
if ($percent >= 99.8) {
    $color = 'brightgreen';
} elseif ($percent >= 90.0) {
    $color = 'green';
} elseif ($percent >= 75.0) {
    $color = 'yellowgreen';
} elseif ($percent >= 50.0) {
    $color = 'yellow';
} elseif ($percent >= 25.0) {
    $color = 'orange';
}

$payload = [
    'schemaVersion' => 1,
    'label' => 'coverage',
    'message' => sprintf('%.2f%%', $percent),
    'color' => $color,
];

$dir = dirname($outPath);
if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
    mkdir($dir, 0o775, true);
}

file_put_contents(
    $outPath,
    json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);

fwrite(STDOUT, "Записан {$outPath}: {$payload['message']}\n");
