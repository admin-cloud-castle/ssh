<?php

/**
 * Матрица фич для сравнения с аналогами (≥5 конкурентов).
 *
 * @return list<array{feature: string, packages: array<string, bool>}>
 */

declare(strict_types=1);

return [
    [
        'feature' => 'PHP 8.1+',
        'packages' => [
            'ours' => true,
        ],
    ],
];
