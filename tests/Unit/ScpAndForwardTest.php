<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Contract\SessionInterface;
use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Forward\LocalPortForward;
use CloudCastle\Ssh\Scp\ScpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScpClient::class)]
#[CoversClass(LocalPortForward::class)]
final class ScpAndForwardTest extends TestCase
{
    public function testScpGetPut(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('exec')->willReturnCallback(static function (string $cmd): string {
            if (str_contains($cmd, 'scp -f')) {
                return 'filedata';
            }
            return '';
        });
        $scp = new ScpClient($session);
        self::assertSame('filedata', $scp->get('/r'));
        $scp->put('/r', 'abc');
        $this->addToAssertionCount(1);
    }

    public function testScpEmptyThrows(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('exec')->willReturn('');
        $this->expectException(ConnectionException::class);
        (new ScpClient($session))->get('/x');
    }

    public function testForwardRequest(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('exec')->willReturn(base64_encode('pong'));
        $fwd = new LocalPortForward($session);
        self::assertSame('pong', $fwd->request('127.0.0.1', 80, 'ping'));
    }

    public function testForwardBadPort(): void
    {
        $this->expectException(ConnectionException::class);
        (new LocalPortForward($this->createMock(SessionInterface::class)))->request('h', 0, '');
    }
}