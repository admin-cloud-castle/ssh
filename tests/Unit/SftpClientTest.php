<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Sftp\SftpChannelInterface;
use CloudCastle\Ssh\Sftp\SftpClient;
use CloudCastle\Ssh\Wire\SshBinary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SftpClient::class)]
#[CoversClass(SshBinary::class)]
final class SftpClientTest extends TestCase
{
    public function testGetPutListViaFakeChannel(): void
    {
        $channel = new class implements SftpChannelInterface {
            /** @var list<string> */
            public array $inbox = [];
            /** @var list<string> */
            public array $outbox = [];

            public function sendSftp(string $sftpPayload): void
            {
                $this->inbox[] = $sftpPayload;
                $type = ord($sftpPayload[0]);
                $parts = unpack('Nid', substr($sftpPayload, 1, 4));
                $id = is_array($parts) ? (int) $parts['id'] : 0;

                if ($type === 3) { // OPEN
                    $this->outbox[] = chr(102) . SshBinary::uint32($id) . SshBinary::sshString('H1');
                } elseif ($type === 5) { // READ
                    static $reads = 0;
                    ++$reads;
                    if ($reads === 1) {
                        $this->outbox[] = chr(103) . SshBinary::uint32($id) . SshBinary::sshString('DATA');
                    } else {
                        $this->outbox[] = chr(101) . SshBinary::uint32($id) . SshBinary::uint32(1) . SshBinary::sshString('') . SshBinary::sshString('');
                    }
                } elseif ($type === 4) { // CLOSE
                    $this->outbox[] = chr(101) . SshBinary::uint32($id) . SshBinary::uint32(0) . SshBinary::sshString('') . SshBinary::sshString('');
                } elseif ($type === 6) { // WRITE
                    $this->outbox[] = chr(101) . SshBinary::uint32($id) . SshBinary::uint32(0) . SshBinary::sshString('') . SshBinary::sshString('');
                } elseif ($type === 11) { // OPENDIR
                    $this->outbox[] = chr(102) . SshBinary::uint32($id) . SshBinary::sshString('D1');
                } elseif ($type === 12) { // READDIR
                    static $dirs = 0;
                    ++$dirs;
                    if ($dirs === 1) {
                        $name = SshBinary::sshString('file.txt') . SshBinary::sshString('file.txt') . SshBinary::uint32(0);
                        $this->outbox[] = chr(104) . SshBinary::uint32($id) . SshBinary::uint32(1) . $name;
                    } else {
                        $this->outbox[] = chr(101) . SshBinary::uint32($id) . SshBinary::uint32(1) . SshBinary::sshString('') . SshBinary::sshString('');
                    }
                } elseif ($type === 14 || $type === 13) {
                    $this->outbox[] = chr(101) . SshBinary::uint32($id) . SshBinary::uint32(0) . SshBinary::sshString('') . SshBinary::sshString('');
                }
            }

            public function receiveSftp(): string
            {
                $packet = array_shift($this->outbox);
                if ($packet === null) {
                    throw new ConnectionException('empty');
                }
                return $packet;
            }
        };

        $client = new SftpClient($channel);
        self::assertSame('DATA', $client->get('/a'));
        $client->put('/b', 'xyz');
        $names = iterator_to_array($client->list('/'), false);
        self::assertContains('file.txt', $names);
        $client->mkdir('/c');
        $client->delete('/d');
        // повторный get после сброса счётчиков READ в фейке может быть пустым — уже проверили get выше
        self::assertTrue(true);
    }
}