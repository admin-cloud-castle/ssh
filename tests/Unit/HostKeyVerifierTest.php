<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Exception\HostKeyException;
use CloudCastle\Ssh\Security\HostKeyVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HostKeyVerifier::class)]
#[CoversClass(ConnectionOptions::class)]
final class HostKeyVerifierTest extends TestCase
{
    public function testAcceptsTrustedFingerprint(): void
    {
        $verifier = new HostKeyVerifier();
        $fp = base64_encode('abc');
        $options = ConnectionOptions::defaults()->withTrustedHostKeys(['SHA256:' . $fp]);
        $verifier->verify($fp, $options);
        $this->addToAssertionCount(1);
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
        (new HostKeyVerifier())->verify('fp', ConnectionOptions::defaults());
        $this->addToAssertionCount(1);
    }
}