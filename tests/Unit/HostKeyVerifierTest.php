<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Exception\HostKeyException;
use CloudCastle\Ssh\Security\HostKeyVerifier;
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
#[CoversClass(HostKeyVerifier::class)]
#[CoversClass(ConnectionOptions::class)]
final class HostKeyVerifierTest extends TestCase
{
    public function testAcceptsTrustedFingerprint(): void
    {
        $verifier = new HostKeyVerifier();
        $fingerprint = base64_encode('abc');
        $options = ConnectionOptions::defaults()->withTrustedHostKeys(['SHA256:' . $fingerprint]);
        $verifier->verify($fingerprint, $options);
        self::assertNotSame('', $fingerprint);
    }

    public function testRejectsMismatch(): void
    {
        $verifier = new HostKeyVerifier();
        $options = ConnectionOptions::defaults()->withTrustedHostKeys([base64_encode('x')]);
        $this->expectException(HostKeyException::class);
        $verifier->verify(base64_encode('y'), $options);
    }

    public function testStrictWithoutListFails(): void
    {
        $verifier = new HostKeyVerifier();
        $options = new ConnectionOptions(strictHostKey: true);
        $this->expectException(HostKeyException::class);
        $verifier->verify('aa', $options);
    }

    public function testNoListNoStrictOk(): void
    {
        $options = ConnectionOptions::defaults();
        (new HostKeyVerifier())->verify('fp', $options);
        self::assertFalse($options->strictHostKey());
    }

    public function testWithTrustedHostKeysDefaultsToStrict(): void
    {
        $strict = ConnectionOptions::defaults()->withTrustedHostKeys(['fp']);
        self::assertTrue($strict->strictHostKey());
        self::assertSame(['fp'], $strict->trustedHostKeys());
        $relaxed = ConnectionOptions::defaults()->withTrustedHostKeys(['fp'], false);
        self::assertFalse($relaxed->strictHostKey());
    }
}
