<?php

/**
 * Проверяет порог покрытия по clover.xml (общий и per-file).
 */

declare(strict_types=1);

const MIN_LINE_COVERAGE_PERCENT = 99.8;

$cloverPath = $argv[1] ?? 'var/coverage/clover.xml';

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
    ? ((float) $covered / (float) $statements) * 100.0
    : 100.0;

if ($percent + 0.0001 < MIN_LINE_COVERAGE_PERCENT) {
    fwrite(STDERR, sprintf("Покрытие %.2f%% ниже порога %.1f%%\n", $percent, MIN_LINE_COVERAGE_PERCENT));
    exit(1);
}

$files = $xml->xpath('//file');
if (!is_array($files)) {
    $files = [];
}

foreach ($files as $file) {
    $fileMetricsNodes = $file->xpath('./metrics');
    $fileMetrics = (is_array($fileMetricsNodes) && isset($fileMetricsNodes[0])) ? $fileMetricsNodes[0] : null;

    if ($fileMetrics === null) {
        continue;
    }

    $fileStatements = (int) $fileMetrics['statements'];
    $fileCovered = (int) $fileMetrics['coveredstatements'];

    if ($fileStatements === 0) {
        continue;
    }

    $filePercent = ((float) $fileCovered / (float) $fileStatements) * 100.0;

    if ($filePercent + 0.0001 < MIN_LINE_COVERAGE_PERCENT) {
        $name = (string) $file['name'];
        fwrite(STDERR, sprintf("Файл %s: %.2f%% ниже порога %.1f%%\n", $name, $filePercent, MIN_LINE_COVERAGE_PERCENT));
        exit(1);
    }
}

fwrite(STDOUT, sprintf("Покрытие OK: %.2f%% (порог %.1f%%)\n", $percent, MIN_LINE_COVERAGE_PERCENT));
