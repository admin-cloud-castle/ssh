[fr] · [ru](../ru/Comparison.md)

# Comparison

Auto-generated tables. Full narrative: [ru](../ru/Comparison.md).

## Features
<!-- FEATURES:START -->
| Фича | cloud-castle/ssh | phpseclib | spatie/ssh | php-ssh | flysystem-sftp | ext-ssh2 | 🏆 Победитель |
| --- | --- | --- | --- | --- | --- | --- | --- |
| PHP 8.1+ | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ | cloud-castle/ssh, phpseclib, spatie/ssh, flysystem-sftp, ext-ssh2 |
| Pure-PHP SSH-2 (без ext-ssh2 / libssh2) | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | cloud-castle/ssh, phpseclib, flysystem-sftp |
| Password auth | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | cloud-castle/ssh, phpseclib, spatie/ssh, php-ssh, flysystem-sftp, ext-ssh2 |
| Publickey auth (RSA / Ed25519) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | cloud-castle/ssh, phpseclib, spatie/ssh, php-ssh, flysystem-sftp, ext-ssh2 |
| Remote exec / shell command | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ | cloud-castle/ssh, phpseclib, spatie/ssh, php-ssh, ext-ssh2 |
| SCP get/put | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | cloud-castle/ssh, phpseclib, spatie/ssh, ext-ssh2 |
| Local port-forward helper | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ | cloud-castle/ssh, phpseclib, ext-ssh2 |
| SSH subsystem API (openSubsystem) | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ | cloud-castle/ssh, phpseclib, ext-ssh2 |
| Fibers async runtime (awaitReadable/Writable) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Session pool (idle reuse) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Process pool (pcntl_fork map) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Host-key fingerprint verify (strict) | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | cloud-castle/ssh, phpseclib, flysystem-sftp, ext-ssh2 |
| SSRF-guard + DNS-pinning по умолчанию | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Ноль Composer runtime-зависимостей | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | cloud-castle/ssh, ext-ssh2 |
| SFTP (встроенный клиент или SSH subsystem API) | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | cloud-castle/ssh, phpseclib, flysystem-sftp, ext-ssh2 |
<!-- FEATURES:END -->

## Performance
<!-- PERF:START -->
Методика: `hrtime(true)` без Xdebug, прогрев + усреднение; `—` — нет сопоставимого API или пакет/расширение недоступны в окружении бенчмарка.

| Метрика | cloud-castle/ssh | phpseclib | spatie/ssh | php-ssh | flysystem-sftp | ext-ssh2 | 🏆 Победитель |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Конструкция клиента · PHP 8.5 | 766 ns | 53.1 µs | 18.0 µs | — | 82.5 µs | — | cloud-castle/ssh |
| Настройка password-auth · PHP 8.5 | 2.6 µs | 14.0 µs | 3.8 µs | — | 4.7 µs | — | cloud-castle/ssh |
| Конфигурация timeout · PHP 8.5 | 1.2 µs | 12.5 µs | 3.1 µs | — | 4.0 µs | — | cloud-castle/ssh |
| Конфигурация порта · PHP 8.5 | 7.8 µs | 9.7 µs | 5.4 µs | — | 4.8 µs | — | flysystem-sftp |
| Политика host key (strict off / allow) · PHP 8.5 | 3.1 µs | — | 3.3 µs | — | — | — | cloud-castle/ssh |
| Подготовка endpoint / connection provider · PHP 8.5 | 8.3 µs | 10.3 µs | 3.8 µs | — | 4.6 µs | — | spatie/ssh |
| Сборка credentials + options (полный стек клиента) · PHP 8.5 | 14.1 µs | 10.7 µs | 4.3 µs | — | 50.1 µs | — | spatie/ssh |
| Session connect+exec (mock peer process) · PHP 8.5 | 2854.95 ms | — | — | — | — | — | cloud-castle/ssh |
| SessionPool acquire/release+drain (mock peer process) · PHP 8.5 | 1872.24 ms | — | — | — | — | — | cloud-castle/ssh |
<!-- PERF:END -->

## Memory
<!-- MEMORY:START -->
Методика: `memory_get_usage(false)` (emalloc) минус baseline пустого цикла — аллокации **пакета** под нагрузкой, не RSS/арена всего процесса.

| Метрика | cloud-castle/ssh | phpseclib | spatie/ssh | php-ssh | flysystem-sftp | ext-ssh2 | 🏆 Победитель |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Память пакета под нагрузкой (hold ×400, emalloc − baseline) | 249.6 KiB | 755.8 KiB | 387.1 KiB | — | — | — | cloud-castle/ssh |
| Сессия+exec под нагрузкой (churn, прирост пакета) | 0 B | — | — | — | — | — | cloud-castle/ssh |
| SessionPool acquire/release (churn, прирост пакета) | 0 B | — | — | — | — | — | cloud-castle/ssh |
<!-- MEMORY:END -->

## Утечки памяти

После нагрузки и `unset`+`gc_collect_cycles`: остаточный прирост emalloc (вычтена стоимость пустого цикла).

<!-- MEMORY_LEAKS:START -->
После нагрузки и `unset`+`gc_collect_cycles`+`gc_mem_caches`: остаточный прирост emalloc пакета должен быть **0 B** (любой прирост = leak).

| Сценарий | cloud-castle/ssh | phpseclib | spatie/ssh | php-ssh | flysystem-sftp | ext-ssh2 | 🏆 Победитель |
| --- | --- | --- | --- | --- | --- | --- | --- |
| После hold ×400 + unset+gc | OK (0 B) | OK (0 B) | OK (0 B) | — | — | — | cloud-castle/ssh, phpseclib, spatie/ssh |
| После churn session+exec | OK (0 B) | — | — | — | — | — | cloud-castle/ssh |
| После churn SessionPool | OK (0 B) | — | — | — | — | — | cloud-castle/ssh |
<!-- MEMORY_LEAKS:END -->

## Code quality
<!-- QUALITY:START -->
| Метрика качества | cloud-castle/ssh | phpseclib | spatie/ssh | php-ssh | flysystem-sftp | ext-ssh2 | 🏆 Победитель |
| --- | --- | --- | --- | --- | --- | --- | --- |
| PHPStan level max в CI | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | cloud-castle/ssh, spatie/ssh |
| Psalm errorLevel 1 в CI | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| PHPCS PSR-12 в CI | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | cloud-castle/ssh, spatie/ssh |
| PHPMD в CI | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Deptrac (слои архитектуры) в CI | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Покрытие строк ≥99.8% (per-file) в CI | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Infection MSI 100% в CI | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Rector dry-run в CI | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
<!-- QUALITY:END -->

## Security
<!-- SECURITY:START -->
| Возможность безопасности | cloud-castle/ssh | phpseclib | spatie/ssh | php-ssh | flysystem-sftp | ext-ssh2 | 🏆 Победитель |
| --- | --- | --- | --- | --- | --- | --- | --- |
| SSRF-guard + DNS-pinning по умолчанию | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Строгая проверка host key | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | cloud-castle/ssh, phpseclib, flysystem-sftp, ext-ssh2 |
| Timing-safe fingerprint (hash_equals) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Ноль Composer runtime-зависимостей (меньше attack surface) | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | cloud-castle/ssh, ext-ssh2 |
<!-- SECURITY:END -->

## Security standards
<!-- SECURITY_STANDARDS:START -->
| Стандарт / практика | cloud-castle/ssh | phpseclib | spatie/ssh | php-ssh | flysystem-sftp | ext-ssh2 | 🏆 Победитель |
| --- | --- | --- | --- | --- | --- | --- | --- |
| SECURITY.md / политика раскрытия уязвимостей | ✅ | ✅ | ✅ | ❌ | ✅ | ❌ | cloud-castle/ssh, phpseclib, spatie/ssh, flysystem-sftp |
| Packagist / FriendsOfPHP security advisories | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | cloud-castle/ssh, phpseclib, spatie/ssh, php-ssh, flysystem-sftp |
| composer audit в CI | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| roave/security-advisories в require-dev | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| OpenSSF Scorecard | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| OpenSSF Best Practices | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Статанализ безопасности в CI (PHPStan max / Psalm L1) | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | cloud-castle/ssh, spatie/ssh |
| CodeQL / Semgrep / supply-chain сканы в CI | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| SSRF-guard (блок частных/metadata IP по умолчанию) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| DNS pin / anti-rebinding после resolve | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Строгая проверка host key (список fingerprints) | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | cloud-castle/ssh, phpseclib, flysystem-sftp, ext-ssh2 |
| Timing-safe сравнение fingerprint (CWE-208) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Защита endpoint от управляющих символов / path abuse (CWE-22) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Явный opt-in allowPrivateAddresses | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
<!-- SECURITY_STANDARDS:END -->

## Quality thresholds
<!-- THRESHOLDS:START -->
| Метрика | Порог | cloud-castle/ssh | phpseclib | spatie/ssh | php-ssh | flysystem-sftp | ext-ssh2 | 🏆 Победитель |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Покрытие строк (per-file) | ≥ 99.8% | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Infection MSI | 100% | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| PHPStan | level max | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | cloud-castle/ssh, spatie/ssh |
| Psalm | errorLevel 1 | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| PHPCS | PSR-12 | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | cloud-castle/ssh, spatie/ssh |
| PHPMD | clean (правила проекта) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Deptrac | 0 нарушений слоёв | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Rector | dry-run в CI | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
<!-- THRESHOLDS:END -->
