<p align="center">
  <img src="assets/banner.svg" alt="Ssh" width="720">
</p>

# Архитектура

Слои Deptrac (см. `deptrac.yaml`):

1. **Contract** — публичные интерфейсы
2. **Core** — реализация
3. **Exception** — исключения пакета
4. **External** — адаптеры к внешним зависимостям (минимум)
5. **Tests** — не входит в runtime

Правило: зависимости направлены внутрь к Contract; Core не тянет Tests.

Подробности — wiki Architecture и PHPDoc в коде.
