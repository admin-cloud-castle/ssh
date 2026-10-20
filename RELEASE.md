<p align="center">
  <img src="assets/banner.svg" alt="Ssh" width="720">
</p>

# Релизы

1. Обновить [CHANGELOG.md](CHANGELOG.md) и при breaking — [UPGRADING.md](UPGRADING.md)
2. SemVer: `MAJOR.MINOR.PATCH` (pre-1.0 — допустимы breaking в minor с записью в UPGRADING)
3. Тег `vX.Y.Z` + GitHub Release (notes из CHANGELOG)
4. Packagist подхватывает тег автоматически (webhook / workflow `packagist.yml`)
5. После релиза — проверить бейджи версии и Pages

Подпись/аттестация артефактов — workflow `attest-release.yml`.
