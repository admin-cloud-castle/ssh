**ru** · [en](../en/Examples.md) · [de](../de/Examples.md) · [fr](../fr/Examples.md) · [es](../es/Examples.md) · [it](../it/Examples.md)

# Examples

## SCP

```php
$scp = $ssh->scp($session);
$body = $scp->get('/etc/hostname');
$scp->put('/tmp/hello.txt', "hi\n");
```

## Port-forward helper

```php
$fwd = $ssh->forward($session);
$reply = $fwd->request('127.0.0.1', 8080, "GET / HTTP/1.0\r\n\r\n");
```

## Session pool

```php
$session = $ssh->pool()->acquire('example.com:22', $auth, $options);
// ...
$ssh->pool()->release($session);
```

## Process pool

```php
foreach ($ssh->processes()->map($hosts, static fn (string $h): string => $h) as $result) {
    // ...
}
```

## Subsystem (SFTP — отдельный пакет)

```php
$session->openSubsystem('sftp');
```

**ru** · [en](../en/Examples.md) · [de](../de/Examples.md) · [fr](../fr/Examples.md) · [es](../es/Examples.md) · [it](../it/Examples.md)
