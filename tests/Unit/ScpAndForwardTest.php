<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Contract\SessionInterface;
use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Forward\LocalPortForward;
use CloudCastle\Ssh\Scp\ScpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.ShortVariable")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.MissingImport")
 * @SuppressWarnings("PHPMD.ExcessivePublicCount")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 * @SuppressWarnings("PHPMD.UnusedLocalVariable")
 */
#[CoversClass(ScpClient::class)]
#[CoversClass(LocalPortForward::class)]
final class ScpAndForwardTest extends TestCase
{
    public function testScpGetUsesExactCommand(): void
    {
        $remotePath = '/remote/file.txt';
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())
            ->method('exec')
            ->with('scp -f ' . escapeshellarg($remotePath))
            ->willReturn('filedata');
        $scp = new ScpClient($session);
        self::assertSame('filedata', $scp->get($remotePath));
    }

    public function testScpPutUsesExactCommand(): void
    {
        $remotePath = '/remote/out.bin';
        $contents = "line\n\x00binary";
        $b64 = base64_encode($contents);
        $expected = 'printf %s ' . escapeshellarg($b64) . ' | base64 -d > ' . escapeshellarg($remotePath);
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())->method('exec')->with($expected);
        (new ScpClient($session))->put($remotePath, $contents);
    }

    public function testScpEmptyThrows(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('exec')->willReturn('');
        $this->expectException(ConnectionException::class);
        (new ScpClient($session))->get('/x');
    }

    public function testForwardRequestBuildsExactRemoteCommand(): void
    {
        $remoteHost = '127.0.0.1';
        $remotePort = 8080;
        $payload = 'ping';
        $timeout = 2.5;
        $expectedCmd = $this->buildExpectedForwardCommand($remoteHost, $remotePort, $payload, $timeout);
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())
            ->method('exec')
            ->with($expectedCmd)
            ->willReturnCallback(static function (string $cmd) use ($expectedCmd): string {
                self::assertSame($expectedCmd, $cmd);

                return '  ' . base64_encode('pong') . '  ';
            });
        $fwd = new LocalPortForward($session);
        self::assertSame('pong', $fwd->request($remoteHost, $remotePort, $payload, $timeout));
    }

    public function testForwardRawResponseWhenNotBase64(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('exec')->willReturn('  not-base64!!!  ');
        $fwd = new LocalPortForward($session);
        self::assertSame('not-base64!!!', $fwd->request('h', 1, 'x'));
    }

    public function testForwardFailedDecodeReturnsTrimmedStdout(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('exec')->willReturn("  \t not-valid-b64 \n");
        $fwd = new LocalPortForward($session);
        self::assertSame('not-valid-b64', $fwd->request('127.0.0.1', 80, 'ping'));
    }

    public function testForwardBadPortZero(): void
    {
        $this->expectException(ConnectionException::class);
        (new LocalPortForward($this->createMock(SessionInterface::class)))->request('h', 0, '');
    }

    public function testForwardBadPortTooHigh(): void
    {
        $this->expectException(ConnectionException::class);
        (new LocalPortForward($this->createMock(SessionInterface::class)))->request('h', 65536, '');
    }

    public function testForwardTimeoutFloorsToOneSecond(): void
    {
        $remoteHost = 'example.internal';
        $remotePort = 443;
        $payload = 'x';
        $expectedCmd = $this->buildExpectedForwardCommand($remoteHost, $remotePort, $payload, 0.2);
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())
            ->method('exec')
            ->with($expectedCmd)
            ->willReturn(' ' . base64_encode('ok') . ' ');
        $fwd = new LocalPortForward($session);
        self::assertSame('ok', $fwd->request($remoteHost, $remotePort, $payload, 0.2));
    }

    public function testForwardAcceptsMaxPort(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('exec')->willReturn("\n" . base64_encode('ok') . "\n");
        $fwd = new LocalPortForward($session);
        self::assertSame('ok', $fwd->request('h', 65535, ''));
    }

    /**
     * Дублирует сборку remote-команды LocalPortForward::request для точных assert.
     */
    private function buildExpectedForwardCommand(
        string $remoteHost,
        int $remotePort,
        string $payload,
        float $timeout,
    ): string {
        $host = escapeshellarg($remoteHost);
        $port = sprintf('%d', $remotePort);
        $b64 = base64_encode($payload);
        $timeoutSeconds = max(1, (int) $timeout);

        return sprintf(
            'timeout %s bash -c %s',
            escapeshellarg((string) $timeoutSeconds),
            escapeshellarg(
                'echo ' . escapeshellarg($b64) . ' | base64 -d | nc -w ' . $timeoutSeconds
                . ' ' . trim($host, "'") . ' ' . $port . ' | base64 -w0',
            ),
        );
    }
}
