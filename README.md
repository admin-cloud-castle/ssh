**ru** · [en](.docs/en/README.md) · [de](.docs/de/README.md) · [fr](.docs/fr/README.md) · [es](.docs/es/README.md) · [it](.docs/it/README.md)

<p align="center">
  <img src="assets/banner.svg" alt="Ssh" width="720">
</p>

# cloud-castle/ssh

Нативный SSH-2 клиент на PHP 8.1+: password/publickey, exec, SCP, port-forward,
`openSubsystem()` для внешних пакетов (например SFTP), Fibers-async, пул сессий,
мультипроцесс, host-key verify и SSRF-guard — без Composer runtime-зависимостей.

[![Packagist Version](https://img.shields.io/packagist/v/cloud-castle/ssh?label=packagist)](https://packagist.org/packages/cloud-castle/ssh)
[![PHP Version](https://img.shields.io/packagist/php-v/cloud-castle/ssh)](https://packagist.org/packages/cloud-castle/ssh)
[![License](https://img.shields.io/packagist/l/cloud-castle/ssh)](LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/cloud-castle/ssh)](https://packagist.org/packages/cloud-castle/ssh)
[![Monthly Downloads](https://img.shields.io/packagist/dm/cloud-castle/ssh)](https://packagist.org/packages/cloud-castle/ssh)
[![Packagist Stars](https://img.shields.io/packagist/stars/cloud-castle/ssh)](https://packagist.org/packages/cloud-castle/ssh)
[![Dependents](https://img.shields.io/packagist/dependents/cloud-castle/ssh)](https://packagist.org/packages/cloud-castle/ssh/dependents)
[![Suggesters](https://img.shields.io/packagist/suggesters/cloud-castle/ssh)](https://packagist.org/packages/cloud-castle/ssh)
[![Advisories](https://img.shields.io/badge/advisories-none-brightgreen)](https://packagist.org/packages/cloud-castle/ssh)
<!-- группа Packagist -->

[![Quality](https://github.com/admin-cloud-castle/ssh/actions/workflows/quality.yml/badge.svg)](https://github.com/admin-cloud-castle/ssh/actions/workflows/quality.yml)
[![CodeQL](https://github.com/admin-cloud-castle/ssh/actions/workflows/codeql.yml/badge.svg)](https://github.com/admin-cloud-castle/ssh/actions/workflows/codeql.yml)
[![GitHub stars](https://img.shields.io/github/stars/admin-cloud-castle/ssh?style=flat)](https://github.com/admin-cloud-castle/ssh/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/admin-cloud-castle/ssh?style=flat)](https://github.com/admin-cloud-castle/ssh/network/members)
[![GitHub issues](https://img.shields.io/github/issues/admin-cloud-castle/ssh)](https://github.com/admin-cloud-castle/ssh/issues)
[![GitHub release](https://img.shields.io/github/v/release/admin-cloud-castle/ssh?include_prereleases)](https://github.com/admin-cloud-castle/ssh/releases)
[![Last commit](https://img.shields.io/github/last-commit/admin-cloud-castle/ssh)](https://github.com/admin-cloud-castle/ssh/commits/main)
[![Contributors](https://img.shields.io/github/contributors/admin-cloud-castle/ssh)](https://github.com/admin-cloud-castle/ssh/graphs/contributors)
<!-- группа GitHub -->

[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-blue)](https://github.com/admin-cloud-castle/ssh/actions/workflows/quality.yml)
[![PHPMD](https://img.shields.io/badge/PHPMD-clean-blue)](https://github.com/admin-cloud-castle/ssh/actions/workflows/quality.yml)
[![PHPCS](https://img.shields.io/badge/PHPCS-PSR--12-blue)](https://github.com/admin-cloud-castle/ssh/actions/workflows/quality.yml)
[![Psalm](https://img.shields.io/badge/Psalm-level%201-blue)](https://github.com/admin-cloud-castle/ssh/actions/workflows/quality.yml)
[![Coverage](https://coveralls.io/repos/github/admin-cloud-castle/ssh/badge.svg?branch=main)](https://coveralls.io/github/admin-cloud-castle/ssh?branch=main)
[![Infection MSI](https://img.shields.io/badge/Infection-MSI%20100%25-blue)](https://github.com/admin-cloud-castle/ssh/actions/workflows/quality.yml)
[![OpenSSF Scorecard](https://api.scorecard.dev/projects/github.com/admin-cloud-castle/ssh/badge)](https://scorecard.dev/viewer/?uri=github.com/admin-cloud-castle/ssh)
<!-- группа качество -->

## Зачем

Чистый PHP SSH-2 транспорт с тем же security-контуром, что и остальная инфраструктура
CloudCastle (SSRF-guard, DNS-pinning, строгий host key), плюс async/pool/multiprocess
из коробки.

### Плюсы

- Pure-PHP SSH-2 (openssl/gmp), password и publickey (RSA / Ed25519)
- Sync + Fibers async, SessionPool, ProcessPool (pcntl)
- SCP и local port-forward; `openSubsystem()` для отдельного SFTP-пакета
- PHPStan max / Psalm 1 / PHPMD / Deptrac; покрытие строк ≥99.8%
- Ноль Composer runtime-зависимостей

### Минусы

Допустимые по эталону CloudCastle:

- Пакет моложе и менее распространён, чем phpseclib / spatie/ssh
- Меньше установок и звёзд на старте

### Когда применять

- Автоматизация по SSH: деплой, remote exec, SCP, туннели
- Сервисы, где нужен SSRF-safe outbound SSH из PHP без libssh2
- Экосистема CloudCastle / `cloud-castle/*`, когда SFTP подключается отдельным пакетом

## Установка

```bash
composer require cloud-castle/ssh
```

## Быстрый старт

```php
<?php

declare(strict_types=1);

use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Ssh;

$ssh = new Ssh();
$session = $ssh->connect(
    'example.com:22',
    AuthCredentials::withPassword('deploy', getenv('SSH_PASSWORD') ?: ''),
    ConnectionOptions::defaults(),
);
echo $session->exec('uname -a');
$session->disconnect();
```

## Документация

- [Wiki](https://github.com/admin-cloud-castle/ssh/wiki)
- [CHANGELOG](CHANGELOG.md)
- [SECURITY](SECURITY.md)
- [CONTRIBUTING](CONTRIBUTING.md)
- [DONATE](DONATE.md)
- [ROADMAP](ROADMAP.md)
- [AUTHORS](AUTHORS.md)
- [ACCESSIBILITY](ACCESSIBILITY.md)
- [RELEASE](RELEASE.md)
- [INSTALL](INSTALL.md)
- [ADOPTERS](ADOPTERS.md)
- [ARCHITECTURE](ARCHITECTURE.md)
- [FAQ](FAQ.md)
- [VERSIONING](VERSIONING.md)
- [CODE_STYLE](CODE_STYLE.md)

## Сравнение с аналогами

В [Wiki → Comparison](https://github.com/admin-cloud-castle/ssh/wiki/Comparison) — отдельные таблицы
с колонкой 🏆 Победитель: функции, производительность, память, качество кода, безопасность
и соответствие стандартам безопасности (≥5 аналогов). В README таблицы не дублируются.

## Лицензия

MIT — см. [LICENSE](LICENSE).

**ru** · [en](.docs/en/README.md) · [de](.docs/de/README.md) · [fr](.docs/fr/README.md) · [es](.docs/es/README.md) · [it](.docs/it/README.md)
