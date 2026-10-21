<p align="center">
  <img src="assets/banner.svg" alt="Ssh" width="720">
</p>

# Changelog

Все заметные изменения проекта документируются в этом файле.

Формат основан на [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/),
проект следует [Semantic Versioning](https://semver.org/lang/ru/).

## [Unreleased]

### Добавлено

- Нативный SSH-2 клиент: password/publickey, exec, SCP, local port-forward
- `openSubsystem()` для внешних пакетов (SFTP — отдельно)
- Fibers async, SessionPool, ProcessPool (pcntl)
- Host-key verify, SSRF-guard / DNS-pinning

### Удалено

- Встроенный SFTP (будет отдельный пакет поверх subsystem API)

## [0.0.1] — 2026-10-20

### Добавлено

- Начальный скаффолд пакета `cloud-castle/ssh`
- CI/CD, документация, бейджи и GitHub Pages
