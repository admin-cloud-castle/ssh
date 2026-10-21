<p align="center">
  <img src="assets/banner.svg" alt="Ssh" width="720">
</p>

# Архитектура

Слои Deptrac (см. `deptrac.yaml`):

1. **Contract** — публичные интерфейсы (`Session*`, `AuthCredentials`, options)
2. **Exception** — иерархия `SshException`
3. **Core** — `Client`, `Session`, `Wire`, `Auth`, `Security`, `Async`, `Pool`, `Process`, `Scp`, `Forward`
4. **Tests** — PHPUnit + TCP MockSshPeer (без fork, совместимо с coverage)

Правило: Contract не зависит от Exception/Core; Core → Contract + Exception; Tests → все слои.

Поток: `Ssh` → `SessionFactory` (+ EndpointGuard / HostKeyVerifier) → `NativeSession`
(Wire + KEX + auth) → exec / SCP / forward / `openSubsystem()`.

Подробности — wiki Architecture и PHPDoc в коде.
