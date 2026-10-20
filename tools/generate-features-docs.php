<?php

/**
 * Генератор матрицы фич (FEATURES) во все языковые Comparison.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CloudCastle\Ssh\Tools\ComparisonLoader;
use CloudCastle\Ssh\Tools\ComparisonMarkdown;

$root = dirname(__DIR__);
$matrix = ComparisonLoader::features();
$packages = ComparisonLoader::packages();
$order = $packages['order'];
$labels = $packages['labels'];
$productName = 'cloud-castle/ssh';

$header = [
    'Фича',
    $productName,
    'phpseclib',
    'spatie/ssh',
    'php-ssh',
    'flysystem-sftp',
    'ext-ssh2',
    '🏆 Победитель',
];

$body = [];

foreach ($matrix as $row) {
    $cells = [$row['feature']];

    foreach ($order as $key) {
        $cells[] = ($row['packages'][$key] ?? false) ? '✅' : '❌';
    }

    $cells[] = ComparisonMarkdown::boolWinner($row['packages'], $order, $labels, $productName);
    $body[] = $cells;
}

$table = ComparisonMarkdown::table($header, $body);

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
        '<!-- FEATURES:START -->',
        '<!-- FEATURES:END -->',
        $table,
    );
    file_put_contents($path, $content);
    fwrite(STDOUT, "updated {$rel}\n");
}

fwrite(STDOUT, "generate-features-docs: OK\n");
