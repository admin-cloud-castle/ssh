[fr] · [ru](../ru/Comparison.md)

# Comparison

Auto-generated tables. Full narrative: [ru](../ru/Comparison.md).

## Features
<!-- FEATURES:START -->
| Фича | cloud-castle/ssh | phpseclib | spatie/ssh | php-ssh | flysystem-sftp | ext-ssh2 | 🏆 Победитель |
| --- | --- | --- | --- | --- | --- | --- | --- |
| PHP 8.1+ | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ | cloud-castle/ssh |
| Pure-PHP SSH-2 (без ext-ssh2 / libssh2) | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | cloud-castle/ssh |
| Password auth | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | cloud-castle/ssh |
| Publickey auth (RSA / Ed25519) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | cloud-castle/ssh |
| Remote exec / shell command | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ | cloud-castle/ssh |
| SCP get/put | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | cloud-castle/ssh |
| Local port-forward helper | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ | cloud-castle/ssh |
| SSH subsystem API (openSubsystem) | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ | cloud-castle/ssh |
| Fibers async runtime (awaitReadable/Writable) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Session pool (idle reuse) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Process pool (pcntl_fork map) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Host-key fingerprint verify (strict) | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | cloud-castle/ssh |
| SSRF-guard + DNS-pinning по умолчанию | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Ноль Composer runtime-зависимостей | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | cloud-castle/ssh |
| SFTP (встроенный клиент или SSH subsystem API) | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | cloud-castle/ssh |
<!-- FEATURES:END -->

## Performance
<!-- PERF:START -->
| Метрика | cloud-castle/ssh | phpseclib | spatie/ssh | php-ssh | flysystem-sftp | ext-ssh2 | 🏆 Победитель |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Конструкция клиента (ns/op, avg PHP 8.5) | 278 ns | 8.8 µs | 3.9 µs | — | — | — | cloud-castle/ssh |
| AuthCredentials::withPassword (ns/op) | 518 ns | — | — | — | — | — | cloud-castle/ssh |
| EndpointGuard::prepare localhost (ns/op) | 3.0 µs | — | — | — | — | — | cloud-castle/ssh |
<!-- PERF:END -->

## Memory
<!-- MEMORY:START -->
| Метрика | cloud-castle/ssh | phpseclib | spatie/ssh | php-ssh | flysystem-sftp | ext-ssh2 | 🏆 Победитель |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Δ памяти на конструкцию клиента | 0 B | 0 B | 2.00 MiB | — | — | — | cloud-castle/ssh |
<!-- MEMORY:END -->

## Code quality
<!-- QUALITY:START -->
| Метрика качества | cloud-castle/ssh | phpseclib | spatie/ssh | php-ssh | flysystem-sftp | ext-ssh2 | 🏆 Победитель |
| --- | --- | --- | --- | --- | --- | --- | --- |
| PHPStan level max в CI | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Psalm errorLevel 1 в CI | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| PHPCS PSR-12 в CI | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | cloud-castle/ssh |
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
| Строгая проверка host key | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | cloud-castle/ssh |
| Timing-safe fingerprint (hash_equals) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Ноль Composer runtime-зависимостей (меньше attack surface) | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | cloud-castle/ssh |
<!-- SECURITY:END -->

## Security standards
<!-- SECURITY_STANDARDS:START -->
| Стандарт / практика | cloud-castle/ssh | phpseclib | spatie/ssh | php-ssh | flysystem-sftp | ext-ssh2 | 🏆 Победитель |
| --- | --- | --- | --- | --- | --- | --- | --- |
| SECURITY.md / политика раскрытия уязвимостей | ✅ | ✅ | ✅ | ❌ | ✅ | ❌ | cloud-castle/ssh |
| Packagist / FriendsOfPHP security advisories | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | cloud-castle/ssh |
| composer audit в CI | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| roave/security-advisories в require-dev | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| OpenSSF Scorecard | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| OpenSSF Best Practices | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Статанализ безопасности в CI (PHPStan max / Psalm L1) | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| CodeQL / Semgrep / supply-chain сканы в CI | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| SSRF-guard (блок частных/metadata IP по умолчанию) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| DNS pin / anti-rebinding после resolve | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Строгая проверка host key (список fingerprints) | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | cloud-castle/ssh |
| Timing-safe сравнение fingerprint (CWE-208) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Защита endpoint от управляющих символов / path abuse (CWE-22) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
| Явный opt-in allowPrivateAddresses | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | cloud-castle/ssh |
<!-- SECURITY_STANDARDS:END -->

## Quality thresholds
<!-- THRESHOLDS:START -->
| Метрика | Порог | 🏆 Победитель |
| --- | --- | --- |
| Покрытие строк (per-file) | ≥ 99.8% | cloud-castle/ssh |
| Infection MSI | 100% | cloud-castle/ssh |
| PHPStan | level max | cloud-castle/ssh |
| Psalm | errorLevel 1 | cloud-castle/ssh |
| PHPCS | PSR-12 | cloud-castle/ssh |
| PHPMD | clean (правила проекта) | cloud-castle/ssh |
| Deptrac | 0 нарушений слоёв | cloud-castle/ssh |
<!-- THRESHOLDS:END -->
