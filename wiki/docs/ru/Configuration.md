**ru** · [en](../en/Configuration.md) · [de](../de/Configuration.md) · [fr](../fr/Configuration.md) · [es](../es/Configuration.md) · [it](../it/Configuration.md)

# Configuration

`ConnectionOptions`:

| Опция | По умолчанию | Смысл |
| --- | --- | --- |
| `connectTimeout` | 10.0 | Таймаут TCP, сек |
| `ioTimeout` | 30.0 | Таймаут I/O stream, сек |
| `blockPrivateAddresses` | true | SSRF-guard |
| `trustedHostKeys` | null | Список SHA-256 fingerprints |
| `strictHostKey` | false | Требовать совпадение fingerprint |

```php
$options = ConnectionOptions::defaults()
    ->withTrustedHostKeys(['BASE64...'], true)
    ->allowPrivateAddresses(); // только доверенная сеть / тесты
```

**ru** · [en](../en/Configuration.md) · [de](../de/Configuration.md) · [fr](../fr/Configuration.md) · [es](../es/Configuration.md) · [it](../it/Configuration.md)
