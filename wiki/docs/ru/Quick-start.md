**ru** · [en](../en/Quick-start.md) · [de](../de/Quick-start.md) · [fr](../fr/Quick-start.md) · [es](../es/Quick-start.md) · [it](../it/Quick-start.md)

# Quick-start

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

Для локальных тестов без SSRF-guard:

```php
$options = ConnectionOptions::defaults()->allowPrivateAddresses();
```

Publickey:

```php
AuthCredentials::withPublicKey('deploy', '/path/to/id_ed25519');
```

**ru** · [en](../en/Quick-start.md) · [de](../de/Quick-start.md) · [fr](../fr/Quick-start.md) · [es](../es/Quick-start.md) · [it](../it/Quick-start.md)
