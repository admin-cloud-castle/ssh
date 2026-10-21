<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Wire\SshBinary;
use CloudCastle\Ssh\Wire\SshWire;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

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
        self::assertCount(2, $pair);

        return [$pair[0], $pair[1]];
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
        $expectedPad = $this->expectedPlaintextPadding(strlen($payload));
        self::assertSame($expectedPad, $paddingLength);
        self::assertSame(strlen($payload) + $paddingLength + 1, $packetLength);
        self::assertSame(0, (4 + $packetLength) % 8);
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
        $mac = substr($cipher, -32);
        $encrypted = substr($cipher, 0, -32);
        $packet = openssl_decrypt($encrypted, 'aes-128-ctr', $keys['c2sKey'], OPENSSL_RAW_DATA, $keys['c2sIv']);
        self::assertNotFalse($packet);
        self::assertSame(
            hash_hmac('sha256', SshBinary::uint32(0) . $packet, $keys['c2sMac'], true),
            $mac,
        );
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
        self::assertIsString($next);
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

    public function testCloseFclosesStreamAndWipesSecrets(): void
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
        $wire->send(chr(50));
        $sendSeq = new ReflectionProperty(SshWire::class, 'sendSeq');
        self::assertSame(1, $sendSeq->getValue($wire));
        $wire->close();
        self::assertFalse(is_resource($client));
        self::assertSame(0, $sendSeq->getValue($wire));
        $macKey = new ReflectionProperty(SshWire::class, 'clientToServerMacKey');
        self::assertSame('', $macKey->getValue($wire));
        $encrypted = new ReflectionProperty(SshWire::class, 'encrypted');
        self::assertFalse($encrypted->getValue($wire));
        fclose($server);
    }

    public function testPlaintextPaddingWhenComputedLengthIsFour(): void
    {
        [$client, $server] = $this->pair();
        $wire = new SshWire($client);
        $payload = str_repeat('x', 7);
        $wire->send($payload);
        $raw = $this->readAll($server);
        self::assertSame(4, ord($raw[4]));
        fclose($client);
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
     * RFC 4253 padding для plaintext block=8.
     *
     * @param int $payloadLength Длина payload без length/padding
     *
     * @return int Длина padding ≥ 4, выровненная по 8
     */
    private function expectedPlaintextPadding(int $payloadLength): int
    {
        $block = 8;
        $paddingLength = $block - (($payloadLength + 5) % $block);

        if ($paddingLength < 4) {
            $paddingLength += $block;
        }

        return $paddingLength;
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
