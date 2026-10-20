<?php

/**
 * Матрица фич. Источник — comparison-features.json.
 */

declare(strict_types=1);

require_once __DIR__ . '/ComparisonCatalogException.php';

use CloudCastle\Ssh\Tools\ComparisonCatalogException;

$raw = file_get_contents(__DIR__ . '/comparison-features.json');

if ($raw === false) {
    throw new ComparisonCatalogException('Не удалось прочитать comparison-features.json');
}

$data = json_decode($raw, true);

if (!is_array($data)) {
    throw new ComparisonCatalogException('Некорректный comparison-features.json');
}

return $data;
