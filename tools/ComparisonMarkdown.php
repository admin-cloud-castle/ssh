<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tools;

/**
 * Сборка markdown-таблиц сравнения и выбор 🏆 Победителя.
 */
final class ComparisonMarkdown
{
    /**
     * Победитель булевой строки: наш пакет, если ✅; иначе все с ✅; иначе —.
     *
     * @param array<string, bool>   $flags
     * @param list<string>          $order
     * @param array<string, string> $labels
     */
    public static function boolWinner(
        array $flags,
        array $order,
        array $labels,
        string $productName,
    ): string {
        if (($flags['ours'] ?? false) === true) {
            return $productName;
        }

        $names = [];

        foreach ($order as $key) {
            if ($key === 'ours') {
                continue;
            }

            if (($flags[$key] ?? false) === true) {
                $names[] = $labels[$key] ?? $key;
            }
        }

        return $names === [] ? '—' : implode(', ', $names);
    }

    /**
     * Победитель числовой метрики (меньше — лучше) среди сопоставимых значений.
     *
     * @param array<string, float|int|null> $values null — не участвует в конкурсе
     * @param list<string>                  $order
     * @param array<string, string>         $labels
     */
    public static function minWinner(
        array $values,
        array $order,
        array $labels,
        string $productName,
    ): string {
        $best = null;
        $winnerKey = null;

        foreach ($order as $key) {
            if (!array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];

            if ($value === null) {
                continue;
            }

            $numeric = (float) $value;

            if ($numeric < 0.0) {
                continue;
            }

            if ($best === null || $numeric < $best) {
                $best = $numeric;
                $winnerKey = $key;
            }
        }

        if ($winnerKey === null) {
            return '—';
        }

        return $winnerKey === 'ours' ? $productName : ($labels[$winnerKey] ?? $winnerKey);
    }

    /**
     * @param list<string>       $header
     * @param list<list<string>> $bodyRows
     */
    public static function table(array $header, array $bodyRows): string
    {
        $lines = [];
        $lines[] = '| ' . implode(' | ', $header) . ' |';
        $lines[] = '| ' . implode(' | ', array_fill(0, count($header), '---')) . ' |';

        foreach ($bodyRows as $row) {
            $lines[] = '| ' . implode(' | ', $row) . ' |';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Заменяет или дописывает блок между маркерами.
     */
    public static function replaceBlock(string $content, string $start, string $end, string $body): string
    {
        if (!str_contains($content, $start)) {
            return $content . "\n\n{$start}\n{$body}{$end}\n";
        }

        $replaced = preg_replace(
            '/' . preg_quote($start, '/') . '.*?' . preg_quote($end, '/') . '/s',
            $start . "\n" . $body . $end,
            $content,
        );

        return is_string($replaced) ? $replaced : $content;
    }

    /**
     * Дописывает отсутствующие секции Comparison, не затирая уже написанное.
     */
    public static function ensureComparisonScaffold(string $content): string
    {
        $sections = [
            'FEATURES' => "## Функции\n\n<!-- FEATURES:START -->\n<!-- FEATURES:END -->",
            'PERF' => "## Производительность\n\n<!-- PERF:START -->\n<!-- PERF:END -->",
            'MEMORY' => "## Память\n\n<!-- MEMORY:START -->\n<!-- MEMORY:END -->",
            'QUALITY' => "## Качество кода\n\n<!-- QUALITY:START -->\n<!-- QUALITY:END -->",
            'SECURITY' => "## Безопасность\n\n<!-- SECURITY:START -->\n<!-- SECURITY:END -->",
            'SECURITY_STANDARDS' => "## Соответствие стандартам безопасности\n\n"
                . "<!-- SECURITY_STANDARDS:START -->\n<!-- SECURITY_STANDARDS:END -->",
            'THRESHOLDS' => "## Пороги качества\n\n<!-- THRESHOLDS:START -->\n<!-- THRESHOLDS:END -->",
        ];

        foreach ($sections as $name => $block) {
            $marker = '<!-- ' . $name . ':START -->';

            if (!str_contains($content, $marker)) {
                $content = rtrim($content) . "\n\n" . $block . "\n";
            }
        }

        return $content;
    }
}
