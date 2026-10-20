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

        $order = [];

        foreach (array_keys($orderRaw) as $index) {
            if (isset($orderRaw[$index]) && is_string($orderRaw[$index])) {
                $order[] = $orderRaw[$index];
            }
        }

        return [
            'order' => $order,
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
        $raw = self::loadJsonList('comparison-features.json');
        $rows = [];

        foreach (array_keys($raw) as $index) {
            if (!isset($raw[$index]) || !is_array($raw[$index])) {
                continue;
            }

            $feature = $raw[$index]['feature'] ?? null;
            $packages = $raw[$index]['packages'] ?? null;

            if (!is_string($feature) || !is_array($packages)) {
                continue;
            }

            $rows[] = [
                'feature' => $feature,
                'packages' => self::boolMap($packages),
            ];
        }

        return $rows;
    }

    /**
     * Матрица стандартов безопасности.
     *
     * @return list<array{standard: string, packages: array<string, bool>}>
     */
    public static function security(): array
    {
        $raw = self::loadJsonList('comparison-security.json');
        $rows = [];

        foreach (array_keys($raw) as $index) {
            if (!isset($raw[$index]) || !is_array($raw[$index])) {
                continue;
            }

            $standard = $raw[$index]['standard'] ?? null;
            $packages = $raw[$index]['packages'] ?? null;

            if (!is_string($standard) || !is_array($packages)) {
                continue;
            }

            $rows[] = [
                'standard' => $standard,
                'packages' => self::boolMap($packages),
            ];
        }

        return $rows;
    }

    /**
     * Матрица качества кода / CI.
     *
     * @return list<array{metric: string, packages: array<string, bool>}>
     */
    public static function quality(): array
    {
        /** @var list<array{metric: string, packages: array<string, bool>}> $rows */
        $rows = [];
        $raw = self::loadJsonList('comparison-quality.json');

        foreach (array_keys($raw) as $index) {
            if (!isset($raw[$index]) || !is_array($raw[$index])) {
                continue;
            }

            $metric = $raw[$index]['metric'] ?? null;
            $packages = $raw[$index]['packages'] ?? null;

            if (!is_string($metric) || !is_array($packages)) {
                continue;
            }

            $rows[] = [
                'metric' => $metric,
                'packages' => self::boolMap($packages),
            ];
        }

        return $rows;
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
        $raw = self::loadJsonList('comparison-perf.json');

        foreach (array_keys($raw) as $index) {
            if (!isset($raw[$index]) || !is_array($raw[$index])) {
                continue;
            }

            $metric = $raw[$index]['metric'] ?? null;
            $benchesRaw = $raw[$index]['benches'] ?? null;

            if (!is_string($metric) || !is_array($benchesRaw)) {
                continue;
            }

            /** @var array<string, string|null> $benches */
            $benches = [];

            foreach (array_keys($benchesRaw) as $key) {
                if (!is_string($key)) {
                    continue;
                }

                $benchKey = $benchesRaw[$key] ?? null;
                $benches[$key] = is_string($benchKey) ? $benchKey : null;
            }

            $rows[] = [
                'metric' => $metric,
                'benches' => $benches,
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
        $raw = self::loadJsonList('comparison-thresholds.json');

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
     * @return array<array-key, mixed>
     */
    private static function loadJsonList(string $filename): array
    {
        return self::loadJson($filename);
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
