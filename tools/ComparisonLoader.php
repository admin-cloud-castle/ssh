<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tools;

/**
 * Типизированная загрузка каталога/матриц сравнения для генераторов docs.
 */
final class ComparisonLoader
{
    /**
     * Загружает каталог пакетов.
     *
     * @return array{
     *     order: list<string>,
     *     labels: array<string, string>,
     *     composer: array<string, string>,
     *     classes: array<string, string>
     * }
     */
    public static function packages(): array
    {
        $raw = self::loadJson('comparison-packages.json');
        $orderRaw = $raw['order'] ?? null;
        $labelsRaw = $raw['labels'] ?? null;
        $composerRaw = $raw['composer'] ?? null;
        $classesRaw = $raw['classes'] ?? null;

        if (!is_array($orderRaw) || !is_array($labelsRaw) || !is_array($composerRaw) || !is_array($classesRaw)) {
            throw new ComparisonCatalogException('comparison-packages.json: неполная структура');
        }

        return [
            'order' => self::stringList($orderRaw),
            'labels' => self::stringMap($labelsRaw),
            'composer' => self::stringMap($composerRaw),
            'classes' => self::stringMap($classesRaw),
        ];
    }

    /**
     * Матрица фич.
     *
     * @return list<array{feature: string, packages: array<string, bool>}>
     */
    public static function features(): array
    {
        /** @var list<array{feature: string, packages: array<string, bool>}> */
        return self::namedBoolRows('comparison-features.json', 'feature');
    }

    /**
     * Матрица стандартов безопасности.
     *
     * @return list<array{standard: string, packages: array<string, bool>}>
     */
    public static function security(): array
    {
        /** @var list<array{standard: string, packages: array<string, bool>}> */
        return self::namedBoolRows('comparison-security.json', 'standard');
    }

    /**
     * Матрица качества кода / CI.
     *
     * @return list<array{metric: string, packages: array<string, bool>}>
     */
    public static function quality(): array
    {
        /** @var list<array{metric: string, packages: array<string, bool>}> */
        return self::namedBoolRows('comparison-quality.json', 'metric');
    }

    /**
     * Сценарии производительности: метрика → ключи бенчмарков по пакетам.
     *
     * @return list<array{metric: string, benches: array<string, string|null>}>
     */
    public static function perf(): array
    {
        /** @var list<array{metric: string, benches: array<string, string|null>}> $rows */
        $rows = [];
        $raw = self::loadJson('comparison-perf.json');

        foreach (array_keys($raw) as $index) {
            if (!isset($raw[$index]) || !is_array($raw[$index])) {
                continue;
            }

            $metric = $raw[$index]['metric'] ?? null;
            $benchesRaw = $raw[$index]['benches'] ?? null;

            if (!is_string($metric) || !is_array($benchesRaw)) {
                continue;
            }

            $rows[] = [
                'metric' => $metric,
                'benches' => self::nullableStringMap($benchesRaw),
            ];
        }

        return $rows;
    }

    /**
     * Пороги качества: метрика + порог + кто выполняет среди аналогов.
     *
     * @return list<array{metric: string, threshold: string, packages: array<string, bool>}>
     */
    public static function thresholds(): array
    {
        /** @var list<array{metric: string, threshold: string, packages: array<string, bool>}> $rows */
        $rows = [];
        $raw = self::loadJson('comparison-thresholds.json');

        foreach (array_keys($raw) as $index) {
            if (!isset($raw[$index]) || !is_array($raw[$index])) {
                continue;
            }

            $metric = $raw[$index]['metric'] ?? null;
            $threshold = $raw[$index]['threshold'] ?? null;
            $packages = $raw[$index]['packages'] ?? null;

            if (!is_string($metric) || !is_string($threshold) || !is_array($packages)) {
                continue;
            }

            $rows[] = [
                'metric' => $metric,
                'threshold' => $threshold,
                'packages' => self::boolMap($packages),
            ];
        }

        return $rows;
    }

    /**
     * Строки вида {nameKey: string, packages: bool-map} из JSON-списка.
     *
     * @return list<array<string, mixed>>
     */
    private static function namedBoolRows(string $filename, string $nameKey): array
    {
        $rows = [];
        $raw = self::loadJson($filename);

        foreach (array_keys($raw) as $index) {
            if (!isset($raw[$index]) || !is_array($raw[$index])) {
                continue;
            }

            $name = $raw[$index][$nameKey] ?? null;
            $packages = $raw[$index]['packages'] ?? null;

            if (!is_string($name) || !is_array($packages)) {
                continue;
            }

            $rows[] = [
                $nameKey => $name,
                'packages' => self::boolMap($packages),
            ];
        }

        return $rows;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function loadJson(string $filename): array
    {
        $path = __DIR__ . '/' . $filename;
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new ComparisonCatalogException('Не удалось прочитать ' . $filename);
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new ComparisonCatalogException('Некорректный JSON: ' . $filename);
        }

        return $data;
    }

    /**
     * @param array<array-key, mixed> $input
     *
     * @return list<string>
     */
    private static function stringList(array $input): array
    {
        $out = [];

        foreach (array_keys($input) as $index) {
            if (isset($input[$index]) && is_string($input[$index])) {
                $out[] = $input[$index];
            }
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $input
     *
     * @return array<string, string>
     */
    private static function stringMap(array $input): array
    {
        $out = [];

        foreach (array_keys($input) as $key) {
            if (!is_string($key)) {
                continue;
            }

            if (isset($input[$key]) && is_string($input[$key])) {
                $out[$key] = $input[$key];
            }
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $input
     *
     * @return array<string, string|null>
     */
    private static function nullableStringMap(array $input): array
    {
        $out = [];

        foreach (array_keys($input) as $key) {
            if (!is_string($key)) {
                continue;
            }

            $out[$key] = self::nullableString($input[$key] ?? null);
        }

        return $out;
    }

    /**
     * @param mixed $value Сырое значение из JSON
     */
    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $input
     *
     * @return array<string, bool>
     */
    private static function boolMap(array $input): array
    {
        $out = [];

        foreach (array_keys($input) as $key) {
            if (!is_string($key)) {
                continue;
            }

            $out[$key] = (bool) $input[$key];
        }

        return $out;
    }
}
