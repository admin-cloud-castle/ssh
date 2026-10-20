<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tools;

/**
 * Сборка markdown-таблиц сравнения и выбор 🏆 Победителя.
 */
final class ComparisonMarkdown
{
    /**
     * Победители булевой строки: все пакеты с ✅ (через запятую); иначе —.
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
        $names = [];

        foreach ($order as $key) {
            if (($flags[$key] ?? false) !== true) {
                continue;
            }

            $names[] = $key === 'ours' ? $productName : ($labels[$key] ?? $key);
        }

        return $names === [] ? '—' : implode(', ', $names);
    }

    /**
     * Победители числовой метрики (меньше — лучше): все с минимальным значением.
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
        $best = self::lowestEligible($values, $order);

        if ($best === null) {
            return '—';
        }

        $names = self::tiedWinnerNames($values, $order, $labels, $productName, $best);

        return $names === [] ? '—' : implode(', ', $names);
    }

    /**
     * Минимальное неотрицательное значение среди участников порядка.
     *
     * @param array<string, float|int|null> $values
     * @param list<string>                  $order
     */
    private static function lowestEligible(array $values, array $order): ?float
    {
        $best = null;

        foreach ($order as $key) {
            $numeric = self::eligibleNumeric($values, $key);

            if ($numeric === null) {
                continue;
            }

            if ($best === null || $numeric < $best) {
                $best = $numeric;
            }
        }

        return $best;
    }

    /**
     * Имена пакетов, чьи значения совпали с лучшим (с float-допуском).
     *
     * @param array<string, float|int|null> $values
     * @param list<string>                  $order
     * @param array<string, string>         $labels
     *
     * @return list<string>
     */
    private static function tiedWinnerNames(
        array $values,
        array $order,
        array $labels,
        string $productName,
        float $best,
    ): array {
        $names = [];

        foreach ($order as $key) {
            $numeric = self::eligibleNumeric($values, $key);

            if ($numeric === null || !self::isTiedBest($numeric, $best)) {
                continue;
            }

            $names[] = $key === 'ours' ? $productName : ($labels[$key] ?? $key);
        }

        return $names;
    }

    /**
     * Числовое значение участника или null, если ключа нет / null / отрицательное.
     *
     * @param array<string, float|int|null> $values
     */
    private static function eligibleNumeric(array $values, string $key): ?float
    {
        if (!array_key_exists($key, $values)) {
            return null;
        }

        $value = $values[$key];

        if ($value === null) {
            return null;
        }

        $numeric = (float) $value;

        return $numeric < 0.0 ? null : $numeric;
    }

    /**
     * Считает значения равными лучшему (допуск на float-шум бенчмарков).
     */
    private static function isTiedBest(float $value, float $best): bool
    {
        $absTol = 1e-6;
        $relTol = 1e-9;

        if (abs($value - $best) <= $absTol) {
            return true;
        }

        $scale = max(abs($best), abs($value), 1.0);

        return abs($value - $best) / $scale <= $relTol;
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
            'MEMORY' => "## Память\n\n"
                . 'Методика: `memory_get_usage(false)` (emalloc) минус baseline пустого цикла — '
                . "аллокации пакета под нагрузкой, не весь процесс.\n\n"
                . "<!-- MEMORY:START -->\n<!-- MEMORY:END -->",
            'MEMORY_LEAKS' => "## Утечки памяти\n\n"
                . 'После нагрузки и `unset`+`gc_collect_cycles`: остаточный прирост emalloc '
                . "(вычтена стоимость пустого цикла).\n\n"
                . "<!-- MEMORY_LEAKS:START -->\n<!-- MEMORY_LEAKS:END -->",
            'QUALITY' => "## Качество кода\n\n<!-- QUALITY:START -->\n<!-- QUALITY:END -->",
            'SECURITY' => "## Безопасность\n\n<!-- SECURITY:START -->\n<!-- SECURITY:END -->",
            'SECURITY_STANDARDS' => "## Соответствие стандартам безопасности\n\n"
                . "<!-- SECURITY_STANDARDS:START -->\n<!-- SECURITY_STANDARDS:END -->",
            'THRESHOLDS' => "## Пороги качества\n\n<!-- THRESHOLDS:START -->\n<!-- THRESHOLDS:END -->",
        ];

        foreach ($sections as $name => $block) {
            $marker = '<!-- ' . $name . ':START -->';

            if (str_contains($content, $marker)) {
                continue;
            }

            if ($name === 'MEMORY_LEAKS' && str_contains($content, '<!-- MEMORY:END -->')) {
                $content = str_replace(
                    '<!-- MEMORY:END -->',
                    "<!-- MEMORY:END -->\n\n" . $block,
                    $content,
                );

                continue;
            }

            $content = rtrim($content) . "\n\n" . $block . "\n";
        }

        return $content;
    }
}
