<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Support;

use CloudCastle\Ssh\Wire\SshBinary;
use GMP;
use RuntimeException;
use Throwable;

/**
 * Минимальный SSH-2 peer для модульных тестов (group14-sha256, aes128-ctr, hmac-sha2-256, password).
 *
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
 * @SuppressWarnings("PHPMD.NPathComplexity")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.ShortVariable")
 * @SuppressWarnings("PHPMD.MissingImport")
 * @SuppressWarnings("PHPMD.UnusedLocalVariable")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */
final class MockSshServer
{
    private const GROUP14_P =
        'FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD129024E088A67CC74'
        . '020BBEA63B139B22514A08798E3404DDEF9519B3CD3A431B302B0A6DF25F1437'
        . '4FE1356D6D51C245E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7ED'
        . 'EE386BFB5A899FA5AE9F24117C4B1FE649286651ECE45B3DC2007CB8A163BF05'
        . '98DA48361C55D39A69163FA8FD24CF5F83655D23DCA3AD961C62F356208552BB'
        . '9ED529077096966D670C354E4ABC9804F1746C08CA18217C32905E462E36CE3B'
        . 'E39E772C180E86039B2783A2EC07A28FB5C55DF06F4C52C9DE2BCBF695581718'
        . '3995497CEA956AE515D2261898FA051015728E5A8AACAA68FFFFFFFFFFFFFFFF';

    private const SERVER_ID = 'SSH-2.0-CloudCastle_Mock';

    private int $sendSeq = 0;
    private int $recvSeq = 0;
    private bool $encrypted = false;
    private string $c2sKey = '';
    private string $s2cKey = '';
    private string $c2sIv = '';
    private string $s2cIv = '';
    private string $macProbe = '';
    private string $s2cMac = '';

    private string $hostKeyPem;
    private string $hostKeyBlob;

    private string $clientId = '';

    /**
     * @param resource $stream Парный stream клиента
     */
    public function __construct(private $stream)
    {
        $this->prepareHostKey();
    }

    /**
     * Обслуживает один клиентский handshake + команды до disconnect.
     */
    public function serve(): void
    {
        $this->exchangeId();
        $this->performKeyExchange();

        if (!$this->acceptServiceAndAuth()) {
            return;
        }

        $this->handleChannels();
    }

    /**
     * KEXINIT / KEXDH / NEWKEYS и установка ключей шифрования.
     */
    private function performKeyExchange(): void
    {
        $clientKex = $this->expectType(20);
        $serverKex = $this->buildKexInit();
        $this->sendPlain($serverKex);
        [$kMpint, $hash, $fBytes] = $this->computeDh($clientKex, $serverKex);
        $this->sendPlain($this->buildDhReply($hash, $fBytes));
        $this->expectType(21);
        $this->sendPlain(chr(21));
        $this->enableKeys($kMpint, $hash);
    }

    /**
     * SERVICE_REQUEST / USERAUTH с опциональными reject/odd через getenv.
     *
     * @return bool true — можно открывать каналы
     */
    private function acceptServiceAndAuth(): bool
    {
        $svc = $this->expectType(5);
        $off = 1;
        $name = SshBinary::readString($svc, $off);
        $this->send(chr(6) . SshBinary::sshString($name));
        $this->receive();

        if (getenv('CC_SSH_REJECT_AUTH') === '1') {
            $this->send(chr(51) . SshBinary::nameList(['password']) . "\x00");

            return false;
        }

        if (getenv('CC_SSH_AUTH_ODD') === '1') {
            $this->send(chr(60));

            return false;
        }

        $this->send(chr(52));

        return true;
    }

    /**
     * @return array{0: string, 1: string, 2: string} kMpint, hash, fBytes
     */
    private function computeDh(string $clientKex, string $serverKex): array
    {
        $dhInit = $this->expectType(30);
        $offset = 1;
        $eBytes = SshBinary::readMpint($dhInit, $offset);
        $p = gmp_init(self::GROUP14_P, 16);
        $y = gmp_random_bits(256);
        $f = gmp_powm(gmp_init(2), $y, $p);
        $fBytes = $this->gmpToBytes($f);
        $e = gmp_import($eBytes);
        $k = gmp_powm($e, $y, $p);
        $kMpint = SshBinary::mpint($this->gmpToBytes($k));
        $hash = hash(
            'sha256',
            SshBinary::sshString($this->clientId)
            . SshBinary::sshString(self::SERVER_ID)
            . SshBinary::sshString($clientKex)
            . SshBinary::sshString($serverKex)
            . SshBinary::sshString($this->hostKeyBlob)
            . SshBinary::mpint($eBytes)
            . SshBinary::mpint($fBytes)
            . $kMpint,
            true,
        );

        return [$kMpint, $hash, $fBytes];
    }

    private function buildDhReply(string $hash, string $fBytes): string
    {
        $signature = '';
        $key = openssl_pkey_get_private($this->hostKeyPem);

        if ($key === false) {
            throw new RuntimeException('host key load failed');
        }

        if (!openssl_sign($hash, $signature, $key, OPENSSL_ALGO_SHA256) || $signature === '') {
            throw new RuntimeException('host key sign failed');
        }

        $sigBlob = SshBinary::sshString('rsa-sha2-256') . SshBinary::sshString($signature);

        return chr(31)
            . SshBinary::sshString($this->hostKeyBlob)
            . SshBinary::mpint($fBytes)
            . SshBinary::sshString($sigBlob);
    }

    private function handleChannels(): void
    {
        while (true) {
            try {
                $packet = $this->receive();
            } catch (Throwable) {
                return;
            }

            if ($packet === '' || !$this->dispatchChannelPacket($packet)) {
                return;
            }
        }
    }

    /**
     * @return bool false — завершить цикл каналов
     */
    private function dispatchChannelPacket(string $packet): bool
    {
        $type = ord($packet[0]);

        if ($type === 1) {
            return false;
        }

        if ($type === 90) {
            $this->confirmChannelOpen($packet);

            return true;
        }

        if ($type === 94) {
            $this->echoChannelData($packet);

            return true;
        }

        if ($type === 98) {
            $this->handleChannelRequest($packet);
        }

        return true;
    }

    private function confirmChannelOpen(string $packet): void
    {
        $off = 1;
        SshBinary::readString($packet, $off);
        $sender = SshBinary::readUint32($packet, $off);
        $this->send(
            chr(91)
            . SshBinary::uint32($sender)
            . SshBinary::uint32(0)
            . SshBinary::uint32(2_097_152)
            . SshBinary::uint32(32_768),
        );
    }

    private function echoChannelData(string $packet): void
    {
        $off = 1;
        $channel = SshBinary::readUint32($packet, $off);
        $data = SshBinary::readString($packet, $off);
        $this->send(chr(94) . SshBinary::uint32($channel) . SshBinary::sshString('echo:' . $data));
    }

    private function handleChannelRequest(string $packet): void
    {
        $off = 1;
        $channel = SshBinary::readUint32($packet, $off);
        $request = SshBinary::readString($packet, $off);
        $want = ord($packet[$off] ?? "\x00");
        ++$off;

        if ($want === 1) {
            $this->send(chr(99) . SshBinary::uint32($channel));
        }

        if ($request === 'exec') {
            $command = SshBinary::readString($packet, $off);
            $this->send(chr(94) . SshBinary::uint32($channel) . SshBinary::sshString('mock:' . $command));
            $this->send(chr(96) . SshBinary::uint32($channel));
            $this->send(chr(97) . SshBinary::uint32($channel));
        }

        if ($request === 'subsystem') {
            SshBinary::readString($packet, $off);
        }
    }

    private function exchangeId(): void
    {
        $line = '';

        while (!str_ends_with($line, "\n")) {
            $chunk = fread($this->stream, 512);

            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('id read');
            }

            $line .= $chunk;
        }

        $this->clientId = trim($line);
        fwrite($this->stream, self::SERVER_ID . "\r\n");
    }

    private function buildKexInit(): string
    {
        return chr(20) . random_bytes(16)
            . SshBinary::nameList(['diffie-hellman-group14-sha256'])
            . SshBinary::nameList(['rsa-sha2-256'])
            . SshBinary::nameList(['aes128-ctr'])
            . SshBinary::nameList(['aes128-ctr'])
            . SshBinary::nameList(['hmac-sha2-256'])
            . SshBinary::nameList(['hmac-sha2-256'])
            . SshBinary::nameList(['none'])
            . SshBinary::nameList(['none'])
            . SshBinary::nameList([])
            . SshBinary::nameList([])
            . "\x00"
            . SshBinary::uint32(0);
    }

    private function enableKeys(string $kMpint, string $h): void
    {
        $derive = static function (string $letter, int $len) use ($kMpint, $h): string {
            $key = hash('sha256', $kMpint . $h . $letter . $h, true);

            while (strlen($key) < $len) {
                $key .= hash('sha256', $kMpint . $h . $key, true);
            }

            return substr($key, 0, $len);
        };
        // client letters from client POV: C/D keys, A/B iv, E/F mac — server swaps directions
        $this->c2sKey = $derive('C', 16);
        $this->s2cKey = $derive('D', 16);
        $this->c2sIv = $derive('A', 16);
        $this->s2cIv = $derive('B', 16);
        $this->macProbe = $derive('E', 32);
        $this->s2cMac = $derive('F', 32);
        $this->encrypted = true;
    }

    private function expectType(int $type): string
    {
        $payload = $this->encrypted ? $this->receive() : $this->receivePlain();

        if ($payload === '' || ord($payload[0]) !== $type) {
            throw new \RuntimeException('expected ' . $type . ' got ' . ($payload === '' ? 'empty' : ord($payload[0])));
        }

        return $payload;
    }

    private function sendPlain(string $payload): void
    {
        $block = 8;
        $payloadLength = strlen($payload);
        $paddingLength = $block - (($payloadLength + 5) % $block);

        if ($paddingLength < 4) {
            $paddingLength += $block;
        }

        $packetLength = $payloadLength + $paddingLength + 1;
        $packet = SshBinary::uint32($packetLength) . chr($paddingLength) . $payload . random_bytes($paddingLength);
        fwrite($this->stream, $packet);
        ++$this->sendSeq;
    }

    private function receivePlain(): string
    {
        $header = $this->readExact(5);
        $offset = 0;
        $packetLength = SshBinary::readUint32($header, $offset);
        $paddingLength = ord($header[4]);
        $rest = $this->readExact($packetLength - 1);
        ++$this->recvSeq;

        return substr($rest, 0, $packetLength - $paddingLength - 1);
    }

    private function send(string $payload): void
    {
        $block = 16;
        $payloadLength = strlen($payload);
        $paddingLength = $block - (($payloadLength + 5) % $block);

        if ($paddingLength < 4) {
            $paddingLength += $block;
        }

        $packetLength = $payloadLength + $paddingLength + 1;
        $packet = SshBinary::uint32($packetLength) . chr($paddingLength) . $payload . random_bytes($paddingLength);
        $mac = hash_hmac('sha256', SshBinary::uint32($this->sendSeq) . $packet, $this->s2cMac, true);
        $enc = openssl_encrypt($packet, 'aes-128-ctr', $this->s2cKey, OPENSSL_RAW_DATA, $this->s2cIv);

        if ($enc === false) {
            throw new RuntimeException('encrypt failed');
        }

        $this->s2cIv = $this->advance($this->s2cIv, (int) (strlen($packet) / 16));
        fwrite($this->stream, $enc . $mac);
        ++$this->sendSeq;
    }

    private function receive(): string
    {
        if (!$this->encrypted) {
            return $this->receivePlain();
        }

        $first = $this->readExact(16);
        $plain = $this->decryptOrFail($first);

        if (strlen($plain) < 5) {
            throw new RuntimeException('decrypt failed');
        }

        $this->c2sIv = $this->advance($this->c2sIv, 1);
        $offset = 0;
        $packetLength = SshBinary::readUint32($plain, $offset);
        $paddingLength = ord($plain[4]);
        $remaining = $packetLength + 4 - 16;
        $restEnc = $remaining > 0 ? $this->readExact($remaining) : '';
        $rest = $restEnc === '' ? '' : $this->decryptOrFail($restEnc);

        if ($remaining > 0) {
            $this->c2sIv = $this->advance($this->c2sIv, (int) ($remaining / 16));
        }

        $packet = $plain . $rest;
        $this->readExact(32);
        ++$this->recvSeq;
        if ($this->macProbe === '') {
            throw new RuntimeException('mac not ready');
        }

        return substr($packet, 5, $packetLength - $paddingLength - 1);
    }

    /**
     * AES-128-CTR decrypt с явной проверкой false от openssl_decrypt.
     */
    private function decryptOrFail(string $cipher): string
    {
        $plain = openssl_decrypt($cipher, 'aes-128-ctr', $this->c2sKey, OPENSSL_RAW_DATA, $this->c2sIv);

        if ($plain === false) {
            throw new RuntimeException('decrypt failed');
        }

        return $plain;
    }

    private function readExact(int $n): string
    {
        $buf = '';

        while (strlen($buf) < $n) {
            $need = $n - strlen($buf);
            if ($need < 1) {
                break;
            }
            $chunk = fread($this->stream, $need);

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('eof');
            }

            $buf .= $chunk;
        }

        return $buf;
    }

    private function advance(string $iv, int $blocks): string
    {
        $value = $iv;

        for ($i = 0; $i < $blocks; ++$i) {
            for ($j = 15; $j >= 0; --$j) {
                $byte = (ord($value[$j]) + 1) & 0xFF;
                $value[$j] = chr($byte);

                if ($byte !== 0) {
                    break;
                }
            }
        }

        return $value;
    }

    private function prepareHostKey(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($key === false) {
            throw new RuntimeException('openssl_pkey_new failed');
        }

        $pem = '';

        if (!openssl_pkey_export($key, $pem) || $pem === '') {
            throw new RuntimeException('openssl_pkey_export failed');
        }

        /** @var string $pemString */
        $pemString = $pem;
        $this->hostKeyPem = $pemString;
        $details = openssl_pkey_get_details($key);

        if (!is_array($details) || !isset($details['rsa']) || !is_array($details['rsa'])) {
            throw new RuntimeException('rsa details missing');
        }

        $n = $details['rsa']['n'] ?? null;
        $e = $details['rsa']['e'] ?? null;

        if (!is_string($n) || !is_string($e)) {
            throw new RuntimeException('rsa n/e missing');
        }
        $this->hostKeyBlob = SshBinary::sshString('ssh-rsa') . SshBinary::mpint($e) . SshBinary::mpint($n);
    }

    private function gmpToBytes(GMP $value): string
    {
        $hex = gmp_strval($value, 16);

        if ((strlen($hex) % 2) === 1) {
            $hex = '0' . $hex;
        }

        $bin = hex2bin($hex);

        return is_string($bin) ? $bin : '';
    }
}
