**ru** · [en](../en/Testing.md) · [de](../de/Testing.md) · [fr](../fr/Testing.md) · [es](../es/Testing.md) · [it](../it/Testing.md)

# Testing

```bash
composer ci:linters
composer ci:static-analysis
# покрытие: PHP с Xdebug/PCOV (локально php8.1)
php8.1 -d xdebug.mode=coverage vendor/bin/phpunit --coverage-clover=var/coverage/clover.xml
php tools/coverage-check.php var/coverage/clover.xml
# мутации (PATH должен указывать на php8.1 как php)
composer test:mutation
composer docs:sync
```

Пороги: покрытие строк ≥99.8% на каждый файл `src/`, Infection MSI 100%.

**ru** · [en](../en/Testing.md) · [de](../de/Testing.md) · [fr](../fr/Testing.md) · [es](../es/Testing.md) · [it](../it/Testing.md)
