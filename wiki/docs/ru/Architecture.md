**ru** · [en](../en/Architecture.md) · [de](../de/Architecture.md) · [fr](../fr/Architecture.md) · [es](../es/Architecture.md) · [it](../it/Architecture.md)

# Architecture

Слои Deptrac:

1. **Contract** — публичные интерфейсы
2. **Exception** — иерархия `SshException`
3. **Core** — Client, Session, Wire, Auth, Security, Async, Pool, Process, Scp, Forward
4. **Tests** — PHPUnit + TCP MockSshPeer

```mermaid
flowchart TB
  Ssh --> SessionFactory
  SessionFactory --> EndpointGuard
  SessionFactory --> HostKeyVerifier
  SessionFactory --> NativeSession
  NativeSession --> SshWire
  NativeSession --> PublicKeySigner
  Ssh --> SessionPool
  Ssh --> FiberAsyncRuntime
  Ssh --> ProcessPool
  Ssh --> ScpClient
  Ssh --> LocalPortForward
```

Подробности — корневой [ARCHITECTURE.md](https://github.com/admin-cloud-castle/ssh/blob/main/ARCHITECTURE.md).

**ru** · [en](../en/Architecture.md) · [de](../de/Architecture.md) · [fr](../fr/Architecture.md) · [es](../es/Architecture.md) · [it](../it/Architecture.md)
