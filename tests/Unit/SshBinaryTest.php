<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Wire\SshBinary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Тесты бинарного кодека SSH.
 */
#[CoversClass(SshBinary::class)]
final class SshBinaryTest extends TestCase
{
    public function testUint32RoundTrip(): void
    {
        $encoded = SshBinary::uint32(0x01020304);
        $offset = 0;

        self::assertSame(0x01020304, SshBinary::readUint32($encoded, $offset));
        self::assertSame(4, $offset);
    }

    public function testSshStringRoundTrip(): void
    {
        $encoded = SshBinary::sshString('abc');
        $offset = 0;

        self::assertSame('abc', SshBinary::readString($encoded, $offset));
    }

    public function testNameList(): void
    {
        $encoded = SshBinary::nameList(['a', 'b']);
        $offset = 0;

        self::assertSame('a,b', SshBinary::readString($encoded, $offset));
    }

    public function testMpintAddsLeadingZeroWhenHighBitSet(): void
    {
        $encoded = SshBinary::mpint("\x80\x01");
        $offset = 0;
        $raw = SshBinary::readString($encoded, $offset);

        self::assertSame("\x00\x80\x01", $raw);
    }

    public function testReadMpintStripsSignByte(): void
    {
        $buffer = SshBinary::sshString("\x00\x01\x02");
        $offset = 0;

        self::assertSame("\x01\x02", SshBinary::readMpint($buffer, $offset));
    }

    public function testReadStringOutOfBoundsReturnsEmpty(): void
    {
        $offset = 0;

        self::assertSame('', SshBinary::readString("\x00\x00\x00\x05ab", $offset));
    }
}
