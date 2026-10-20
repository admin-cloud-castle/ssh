**ru** · [en](.docs/en/README.md) · [de](.docs/de/README.md) · [fr](.docs/fr/README.md) · [es](.docs/es/README.md) · [it](.docs/it/README.md)

<p align="center">
  <img src="assets/banner.svg" alt="Ssh" width="720">
</p>

# Ssh

Production-ready PHP 8.1+ библиотека Ssh (CloudCastle).

<!-- Группа 1: Packagist (только живые shields endpoints) -->
[![Packagist Version](https://img.shields.io/packagist/v/cloud-castle/ssh?label=packagist)](https://packagist.org/packages/cloud-castle/ssh)
[![PHP Version](https://img.shields.io/packagist/php-v/cloud-castle/ssh)](https://packagist.org/packages/cloud-castle/ssh)
[![License](https://img.shields.io/packagist/l/cloud-castle/ssh)](LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/cloud-castle/ssh)](https://packagist.org/packages/cloud-castle/ssh)
[![Monthly Downloads](https://img.shields.io/packagist/dm/cloud-castle/ssh)](https://packagist.org/packages/cloud-castle/ssh)
[![Packagist Stars](https://img.shields.io/packagist/stars/cloud-castle/ssh)](https://packagist.org/packages/cloud-castle/ssh)
[![Security Policy](https://img.shields.io/badge/security-policy-blue)](SECURITY.md)

<!-- Группа 2: GitHub Actions + meta -->
[![Quality](https://github.com/admin-cloud-castle/ssh/actions/workflows/quality.yml/badge.svg)](https://github.com/admin-cloud-castle/ssh/actions/workflows/quality.yml)
[![CodeQL](https://github.com/admin-cloud-castle/ssh/actions/workflows/codeql.yml/badge.svg)](https://github.com/admin-cloud-castle/ssh/actions/workflows/codeql.yml)
[![Semgrep](https://github.com/admin-cloud-castle/ssh/actions/workflows/semgrep.yml/badge.svg)](https://github.com/admin-cloud-castle/ssh/actions/workflows/semgrep.yml)
[![Supply chain](https://github.com/admin-cloud-castle/ssh/actions/workflows/supply-chain.yml/badge.svg)](https://github.com/admin-cloud-castle/ssh/actions/workflows/supply-chain.yml)
[![GitGuardian](https://github.com/admin-cloud-castle/ssh/actions/workflows/gitguardian.yml/badge.svg)](https://github.com/admin-cloud-castle/ssh/actions/workflows/gitguardian.yml)
[![Scorecard](https://github.com/admin-cloud-castle/ssh/actions/workflows/scorecard.yml/badge.svg)](https://github.com/admin-cloud-castle/ssh/actions/workflows/scorecard.yml)
[![GitHub stars](https://img.shields.io/github/stars/admin-cloud-castle/ssh?style=flat)](https://github.com/admin-cloud-castle/ssh/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/admin-cloud-castle/ssh?style=flat)](https://github.com/admin-cloud-castle/ssh/network/members)
[![GitHub issues](https://img.shields.io/github/issues/admin-cloud-castle/ssh)](https://github.com/admin-cloud-castle/ssh/issues)
[![GitHub release](https://img.shields.io/github/v/release/admin-cloud-castle/ssh?include_prereleases)](https://github.com/admin-cloud-castle/ssh/releases)
[![Last commit](https://img.shields.io/github/last-commit/admin-cloud-castle/ssh)](https://github.com/admin-cloud-castle/ssh/commits/main)
[![Contributors](https://img.shields.io/github/contributors/admin-cloud-castle/ssh)](https://github.com/admin-cloud-castle/ssh/graphs/contributors)

<!-- Группа 3: качество — только живые метрики / ссылки без фейкового green -->
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-blue)](https://github.com/admin-cloud-castle/ssh/actions/workflows/quality.yml)
[![PHPCS](https://img.shields.io/badge/PHPCS-PSR--12-blue)](https://github.com/admin-cloud-castle/ssh/actions/workflows/quality.yml)
[![Psalm](https://img.shields.io/badge/Psalm-level%201-blue)](https://github.com/admin-cloud-castle/ssh/actions/workflows/quality.yml)
[![Infection](https://img.shields.io/badge/Infection-CI-blue)](https://github.com/admin-cloud-castle/ssh/actions/workflows/quality.yml)
[![Coverage](https://coveralls.io/repos/github/admin-cloud-castle/ssh/badge.svg?branch=main)](https://coveralls.io/github/admin-cloud-castle/ssh?branch=main)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/admin-cloud-castle/ssh/badges/quality-score.png?b=main)](https://scrutinizer-ci.com/g/admin-cloud-castle/ssh/)
[![Scrutinizer Build](https://scrutinizer-ci.com/g/admin-cloud-castle/ssh/badges/build.png?b=main)](https://scrutinizer-ci.com/g/admin-cloud-castle/ssh/)
[![Maintainability](https://qlty.sh/gh/admin-cloud-castle/projects/ssh/maintainability.svg)](https://qlty.sh/gh/admin-cloud-castle/projects/ssh)
[![Qlty Coverage](https://qlty.sh/gh/admin-cloud-castle/projects/ssh/coverage.svg)](https://qlty.sh/gh/admin-cloud-castle/projects/ssh)
[![OpenSSF Scorecard](https://api.scorecard.dev/projects/github.com/admin-cloud-castle/ssh/badge)](https://scorecard.dev/viewer/?uri=github.com/admin-cloud-castle/ssh)
[![OpenSSF Best Practices](https://www.bestpractices.dev/projects?as=badge&url=https%3A%2F%2Fgithub.com%2Fadmin-cloud-castle%2Fssh)](https://www.bestpractices.dev/projects?as=entry&url=https%3A%2F%2Fgithub.com%2Fadmin-cloud-castle%2Fssh)
[![SonarCloud](https://sonarcloud.io/api/project_badges/measure?project=admin-cloud-castle_ssh&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=admin-cloud-castle_ssh)
[![Snyk](https://snyk.io/test/github/admin-cloud-castle/ssh/badge.svg)](https://snyk.io/test/github/admin-cloud-castle/ssh)
[![Codacy](https://img.shields.io/badge/Codacy-dashboard-blue)](https://app.codacy.com/gh/admin-cloud-castle/ssh/dashboard)
[![DeepSource](https://img.shields.io/badge/DeepSource-dashboard-blue)](https://app.deepsource.com/gh/admin-cloud-castle/ssh/)

## Зачем

Production-ready PHP 8.1+ библиотека Ssh (CloudCastle).

Краткая выжимка:

**Плюсы**
- PHP 8.1+, строгие типы, полный quality-стек в CI
- Цель эталона: покрытие ≥99.8%, Infection MSI 100% (по Coveralls / Quality CI)
- Минимум runtime-зависимостей

**Минусы** (допустимые по эталону CloudCastle)
- Пакет моложе и менее распространён, чем зрелые аналоги
- Меньше установок/звёзд на старте

## Когда применять

- Новый код на PHP 8.1+, где нужны предсказуемые контракты и зелёный CI
- Проекты экосистемы CloudCastle / `cloud-castle/*`

## Установка

```bash
composer require cloud-castle/ssh
```

## Быстрый старт

```php
<?php

declare(strict_types=1);

use CloudCastle\Ssh\Ssh;

$pkg = new Ssh();
echo $pkg->name();
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

Актуальные таблицы (фичи / перф / память / безопасность / качество) — в [Wiki → Comparison](https://github.com/admin-cloud-castle/ssh/wiki/Comparison).
В README таблицы не дублируются (защита ветки main).

## Лицензия

MIT — см. [LICENSE](LICENSE).

**ru** · [en](.docs/en/README.md) · [de](.docs/de/README.md) · [fr](.docs/fr/README.md) · [es](.docs/es/README.md) · [it](.docs/it/README.md)
