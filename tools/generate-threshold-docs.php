<?php

/**
 * Генератор порогов качества (THRESHOLDS) во все Comparison.md — матрица по всем аналогам.
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

$pkgHeader = [];

foreach ($order as $key) {
    $pkgHeader[] = $key === 'ours' ? $productName : ($labels[$key] ?? $key);
}

/** @var list<list<string>> $body */
$body = [];

foreach (ComparisonLoader::thresholds() as $row) {
    $flags = $row['packages'];
    $cells = [$row['metric'], $row['threshold']];

    foreach ($order as $key) {
        $cells[] = ($flags[$key] ?? false) ? '✅' : '❌';
    }

    $cells[] = ComparisonMarkdown::boolWinner($flags, $order, $labels, $productName);
    $body[] = $cells;
}

$table = ComparisonMarkdown::table(
    array_merge(['Метрика', 'Порог'], $pkgHeader, ['🏆 Победитель']),
    $body,
);

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
        '<!-- THRESHOLDS:START -->',
        '<!-- THRESHOLDS:END -->',
        $table,
    );
    file_put_contents($path, $content);
    fwrite(STDOUT, "updated {$rel}\n");
}

fwrite(STDOUT, "generate-threshold-docs: OK\n");
