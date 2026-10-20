<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Wire\SshBinary;
use CloudCastle\Ssh\Wire\SshWire;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Точечные тесты SshWire для Infection (padding, seq, MAC, CTR).
 *
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
#[CoversClass(SshWire::class)]
#[CoversClass(SshBinary::class)]
final class CoverageGapsWireTest extends TestCase
{
    /**
     * @return array{0: resource, 1: resource}
     */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);

        return $pair;
    }

    public function testPlaintextSendPacketLayoutAndPayload(): void
    {
        [$client, $server] = $this->pair();
        $wire = new SshWire($client);
        $payload = chr(5) . SshBinary::sshString('ssh-userauth');
        $wire->send($payload);
        $raw = $this->readAll($server);
        $offset = 0;
        $packetLength = SshBinary::readUint32($raw, $offset);
        $paddingLength = ord($raw[4]);
        self::assertSame(strlen($payload) + $paddingLength + 1, $packetLength);
        self::assertGreaterThanOrEqual(4, $paddingLength);
        self::assertSame($payload, substr($raw, 5, strlen($payload)));
        fclose($client);
        fclose($server);
    }

    public function testPlaintextReceiveTwoPacketsIncrementsRecvSeq(): void
    {
        [$client, $server] = $this->pair();
        $wire = new SshWire($client);
        $first = chr(6) . SshBinary::sshString('ssh-userauth');
        $second = chr(52);
        $this->writePlainPacket($server, $first);
        $this->writePlainPacket($server, $second);
        self::assertSame($first, $wire->receive());
        self::assertSame($second, $wire->receive());
        fclose($client);
        fclose($server);
    }

    public function testPlaintextReceiveEmptyPayloadLength(): void
    {
        [$client, $server] = $this->pair();
        $wire = new SshWire($client);
        $this->writePlainPacket($server, chr(21));
        self::assertSame(chr(21), $wire->receive());
        fclose($client);
        fclose($server);
    }

    public function testEncryptedRoundTripAndMacSeq(): void
    {
        [$client, $server] = $this->pair();
        $wire = new SshWire($client);
        $keys = $this->sampleKeys();
        $wire->enableEncryption(
            $keys['c2sKey'],
            $keys['s2cKey'],
            $keys['c2sIv'],
            $keys['s2cIv'],
            $keys['c2sMac'],
            $keys['s2cMac'],
        );
        $outbound = chr(50) . SshBinary::sshString('user');
        $wire->send($outbound);
        $cipher = $this->readAll($server);
        self::assertGreaterThan(32, strlen($cipher));
        $inbound = chr(52);
        $this->writeEncryptedPacket($server, $inbound, 0, $keys['s2cKey'], $keys['s2cIv'], $keys['s2cMac']);
        self::assertSame($inbound, $wire->receive());
        fclose($client);
        fclose($server);
    }

    public function testEncryptedMacMismatchMessagePrefix(): void
    {
        [$client, $server] = $this->pair();
        $wire = new SshWire($client);
        $keys = $this->sampleKeys();
        $wire->enableEncryption(
            $keys['c2sKey'],
            $keys['s2cKey'],
            $keys['c2sIv'],
            $keys['s2cIv'],
            $keys['c2sMac'],
            $keys['s2cMac'],
        );
        $payload = chr(52);
        $this->writeEncryptedPacket($server, $payload, 0, $keys['s2cKey'], $keys['s2cIv'], str_repeat('X', 32));
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('SSH MAC mismatch');
        $wire->receive();
        fclose($client);
        fclose($server);
    }

    public function testAdvanceCtrCarriesOverAllBytes(): void
    {
        [$client, $server] = $this->pair();
        $wire = new SshWire($client);
        $method = new ReflectionMethod(SshWire::class, 'advanceCtr');
        $start = str_repeat("\xFF", 16);
        $next = $method->invoke($wire, $start, 1);
        self::assertSame(16, strlen($next));
        self::assertSame(str_repeat("\x00", 16), $next);
        fclose($client);
        fclose($server);
    }

    public function testReadExactAcrossMultipleChunks(): void
    {
        [$client, $server] = $this->pair();
        $wire = new SshWire($client);
        $payload = chr(52);
        $paddingLength = 4;
        $packetLength = strlen($payload) + $paddingLength + 1;
        $packet = SshBinary::uint32($packetLength)
            . chr($paddingLength)
            . $payload
            . str_repeat("\x00", $paddingLength);
        fwrite($server, substr($packet, 0, 3));
        fwrite($server, substr($packet, 3));
        self::assertSame($payload, $wire->receive());
        fclose($client);
        fclose($server);
    }

    public function testCloseClearsStream(): void
    {
        [$client, $server] = $this->pair();
        $wire = new SshWire($client);
        $wire->close();
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('SSH stream закрыт');
        $wire->send('x');
        fclose($server);
    }

    /**
     * @return array{c2sKey: string, s2cKey: string, c2sIv: string, s2cIv: string, c2sMac: string, s2cMac: string}
     */
    private function sampleKeys(): array
    {
        return [
            'c2sKey' => str_repeat('C', 16),
            's2cKey' => str_repeat('D', 16),
            'c2sIv' => str_repeat('A', 16),
            's2cIv' => str_repeat('B', 16),
            'c2sMac' => str_repeat('E', 32),
            's2cMac' => str_repeat('F', 32),
        ];
    }

    /**
     * @param resource $stream
     */
    private function writePlainPacket($stream, string $payload): void
    {
        $block = 8;
        $payloadLength = strlen($payload);
        $paddingLength = $block - (($payloadLength + 5) % $block);

        if ($paddingLength < 4) {
            $paddingLength += $block;
        }

        $packetLength = $payloadLength + $paddingLength + 1;
        $packet = SshBinary::uint32($packetLength)
            . chr($paddingLength)
            . $payload
            . str_repeat("\x00", $paddingLength);
        fwrite($stream, $packet);
    }

    /**
     * @param resource $stream
     */
    private function writeEncryptedPacket(
        $stream,
        string $payload,
        int $seq,
        string $key,
        string $iv,
        string $macKey,
    ): void {
        $block = 16;
        $payloadLength = strlen($payload);
        $paddingLength = $block - (($payloadLength + 5) % $block);

        if ($paddingLength < 4) {
            $paddingLength += $block;
        }

        $packetLength = $payloadLength + $paddingLength + 1;
        $packet = SshBinary::uint32($packetLength)
            . chr($paddingLength)
            . $payload
            . str_repeat("\x00", $paddingLength);
        $mac = hash_hmac('sha256', SshBinary::uint32($seq) . $packet, $macKey, true);
        $encrypted = openssl_encrypt($packet, 'aes-128-ctr', $key, OPENSSL_RAW_DATA, $iv);
        self::assertNotFalse($encrypted);
        fwrite($stream, $encrypted . $mac);
    }

    /**
     * @param resource $stream
     */
    private function readAll($stream): string
    {
        stream_set_blocking($stream, false);
        $buffer = '';
        $deadline = microtime(true) + 1.0;

        while (microtime(true) < $deadline) {
            $chunk = fread($stream, 8192);

            if ($chunk === false || $chunk === '') {
                usleep(1000);

                continue;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }
}
