<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tools;

/**
 * Изолированный замер памяти библиотеки: вычет baseline пустого цикла, нагрузка и контроль утечек.
 *
 * Использует memory_get_usage(false) (emalloc) — аллокации пакета, а не рост арены всего процесса.
 * Перед замером прогревает кучу Zend полным hold/release того же размера и сбрасывает free-lists
 * через gc_mem_caches(), чтобы рост чанков аллокатора не считался утечкой пакета.
 */
final class PackageMemoryMeter
{
    /**
     * Нагрузка с удержанием результатов: сколько байт удерживает пакет минус baseline.
     *
     * @param callable(): mixed $workload Фабрика; результат удерживается до конца замера
     * @param int               $iterations Число объектов под нагрузкой (≥1)
     * @param int               $warm       Дополнительные итерации прогрева фабрики
     *
     * @return array{
     *     package_bytes: int,
     *     baseline_bytes: int,
     *     retained_bytes: int,
     *     leak_bytes: int,
     *     iterations: int
     * }
     */
    public static function measureHold(callable $workload, int $iterations = 500, int $warm = 30): array
    {
        $iterations = max(1, $iterations);
        $warm = max(0, $warm);

        for ($i = 0; $i < $warm; ++$i) {
            $workload();
        }

        // Прогрев кучи: hold+release того же N, иначе первый рост чанка Zend попадает в leak.
        self::primeHeap($workload, $iterations);
        self::collect();
        $baselineBytes = self::emptyLoopDelta($iterations);

        self::collect();
        $start = memory_get_usage(false);
        /** @var list<mixed> $held */
        $held = [];

        for ($i = 0; $i < $iterations; ++$i) {
            $held[] = $workload();
        }

        $retainedBytes = max(0, memory_get_usage(false) - $start);
        $packageBytes = max(0, $retainedBytes - $baselineBytes);

        unset($held);
        self::collect();
        $leakBytes = max(0, memory_get_usage(false) - $start - $baselineBytes);

        return [
            'package_bytes' => $packageBytes,
            'baseline_bytes' => $baselineBytes,
            'retained_bytes' => $retainedBytes,
            'leak_bytes' => $leakBytes,
            'iterations' => $iterations,
        ];
    }

    /**
     * Нагрузка без удержания вызывающим кодом (create→destroy).
     *
     * package_bytes — прирост сразу после цикла (минус baseline);
     * leak_bytes — после опционального $after (например drain пула) и gc.
     *
     * @param callable():void      $workload
     * @param callable():void|null $after    Очистка после цикла перед замером утечки
     *
     * @return array{
     *     package_bytes: int,
     *     baseline_bytes: int,
     *     retained_bytes: int,
     *     leak_bytes: int,
     *     iterations: int
     * }
     */
    public static function measureChurn(
        callable $workload,
        int $iterations = 500,
        int $warm = 30,
        ?callable $after = null,
    ): array {
        $iterations = max(1, $iterations);
        $warm = max(0, $warm);

        for ($i = 0; $i < $warm; ++$i) {
            $workload();
        }

        self::primeChurn($workload, $iterations, $after);
        self::collect();
        $baselineBytes = self::emptyLoopDelta($iterations);

        self::collect();
        $start = memory_get_usage(false);

        for ($i = 0; $i < $iterations; ++$i) {
            $workload();
        }

        self::collect();
        $gross = max(0, memory_get_usage(false) - $start);
        $packageBytes = max(0, $gross - $baselineBytes);

        if ($after !== null) {
            $after();
        }

        self::collect();
        $leakBytes = max(0, memory_get_usage(false) - $start - $baselineBytes);

        return [
            'package_bytes' => $packageBytes,
            'baseline_bytes' => $baselineBytes,
            'retained_bytes' => $gross,
            'leak_bytes' => $leakBytes,
            'iterations' => $iterations,
        ];
    }

    /**
     * Прирост emalloc на пустом цикле той же длины (шум интерпретатора).
     */
    public static function emptyLoopDelta(int $iterations): int
    {
        self::collect();
        $before = memory_get_usage(false);

        for ($i = 0; $i < $iterations; ++$i) {
            // пустой цикл — стоимость самого интерпретатора/счётчика
        }

        self::collect();

        return max(0, memory_get_usage(false) - $before);
    }

    /**
     * @param callable(): mixed $workload
     */
    private static function primeHeap(callable $workload, int $iterations): void
    {
        /** @var list<mixed> $held */
        $held = [];

        for ($i = 0; $i < $iterations; ++$i) {
            $held[] = $workload();
        }

        unset($held);
        self::collect();
    }

    /**
     * @param callable():void      $workload
     * @param callable():void|null $after
     */
    private static function primeChurn(callable $workload, int $iterations, ?callable $after): void
    {
        for ($i = 0; $i < $iterations; ++$i) {
            $workload();
        }

        if ($after !== null) {
            $after();
        }

        self::collect();
    }

    private static function collect(): void
    {
        gc_collect_cycles();

        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
    }
}
