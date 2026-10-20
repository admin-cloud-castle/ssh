<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use CloudCastle\Ssh\Tests\Support\MockSshServer;

$portFile = $argv[1] ?? sys_get_temp_dir() . '/cc-ssh-port';
$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, "bind failed: {$errstr}\n");
    exit(1);
}

$meta = stream_get_meta_data($server);
$uri = is_array($meta) && isset($meta['uri']) && is_string($meta['uri']) ? $meta['uri'] : '';
$name = stream_socket_get_name($server, false);

if (!is_string($name) || !preg_match('/:(\d+)$/', $name, $m)) {
    fwrite(STDERR, "port detect failed\n");
    exit(1);
}

file_put_contents($portFile, $m[1]);
$client = @stream_socket_accept($server, 10);

if ($client === false) {
    exit(1);
}

try {
    (new MockSshServer($client))->serve();
} catch (Throwable) {
}

fclose($client);
fclose($server);
