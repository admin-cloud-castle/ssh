<?php

/**
 * Генератор порогов качества (THRESHOLDS) во все Comparison.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CloudCastle\Ssh\Tools\ComparisonMarkdown;

$root = dirname(__DIR__);
$productName = 'cloud-castle/ssh';

$table = ComparisonMarkdown::table(
    ['Метрика', 'Порог', '🏆 Победитель'],
    [
        ['Покрытие строк (per-file)', '≥ 99.8%', $productName],
        ['Infection MSI', '100%', $productName],
        ['PHPStan', 'level max', $productName],
        ['Psalm', 'errorLevel 1', $productName],
        ['PHPCS', 'PSR-12', $productName],
        ['PHPMD', 'clean (правила проекта)', $productName],
        ['Deptrac', '0 нарушений слоёв', $productName],
    ],
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
