**ru** · [en](../en/FAQ.md) · [de](../de/FAQ.md) · [fr](../fr/FAQ.md) · [es](../es/FAQ.md) · [it](../it/FAQ.md)

# FAQ

**Почему нет встроенного SFTP?**  
SFTP вынесен в отдельный пакет экосистемы; этот пакет даёт `openSubsystem('sftp')`.

**Почему SSRF-guard режет localhost?**  
По умолчанию `blockPrivateAddresses=true`. Для тестов: `allowPrivateAddresses()`.

**Нужен ли ext-ssh2?**  
Нет. Транспорт — pure-PHP (openssl/gmp).

**ru** · [en](../en/FAQ.md) · [de](../de/FAQ.md) · [fr](../fr/FAQ.md) · [es](../es/FAQ.md) · [it](../it/FAQ.md)
