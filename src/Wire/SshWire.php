<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Wire;

use CloudCastle\Ssh\Exception\ConnectionException;

/**
 * Обмен SSH-пакетами: plaintext и AES-128-CTR + HMAC-SHA256.
 *
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
 * @SuppressWarnings("PHPMD.NPathComplexity")
 * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.ShortVariable")
 */
final class SshWire
{
    private int $sendSeq = 0;

    private int $recvSeq = 0;

    private bool $encrypted = false;

    private string $clientToServerKey = '';

    private string $serverToClientKey = '';

    private string $clientToServerIv = '';

    private string $serverToClientIv = '';

    private string $clientToServerMacKey = '';

    private string $serverToClientMacKey = '';

    /** @var resource|null */
    private $stream;

    /**
     * @param resource $stream
     */
    public function __construct($stream)
    {
        $this->stream = $stream;
    }

    /**
     * Включает шифрование после NEWKEYS.
     */
    public function enableEncryption(
        string $clientToServerKey,
        string $serverToClientKey,
        string $clientToServerIv,
        string $serverToClientIv,
        string $clientToServerMacKey,
        string $serverToClientMacKey,
    ): void {
        $this->clientToServerKey = $clientToServerKey;
        $this->serverToClientKey = $serverToClientKey;
        $this->clientToServerIv = $clientToServerIv;
        $this->serverToClientIv = $serverToClientIv;
        $this->clientToServerMacKey = $clientToServerMacKey;
        $this->serverToClientMacKey = $serverToClientMacKey;
        $this->encrypted = true;
    }

    /**
     * Отправляет payload (message type + тело).
     *
     * @throws ConnectionException
     */
    public function send(string $payload): void
    {
        $block = $this->encrypted ? 16 : 8;
        $payloadLength = strlen($payload);
        $paddingLength = $block - (($payloadLength + 5) % $block);

        if ($paddingLength < 4) {
            $paddingLength += $block;
        }

        $packetLength = $payloadLength + $paddingLength + 1;
        $padding = random_bytes($paddingLength);
        $packet = SshBinary::uint32($packetLength) . chr($paddingLength) . $payload . $padding;

        if ($this->encrypted) {
            $mac = hash_hmac('sha256', SshBinary::uint32($this->sendSeq) . $packet, $this->clientToServerMacKey, true);
            $encrypted = openssl_encrypt(
                $packet,
                'aes-128-ctr',
                $this->clientToServerKey,
                OPENSSL_RAW_DATA,
                $this->clientToServerIv,
            );

            if ($encrypted === false) { // @codeCoverageIgnore
                throw new ConnectionException('SSH encrypt не удался'); // @codeCoverageIgnore
            }

            $this->clientToServerIv = $this->advanceCtr($this->clientToServerIv, (int) (strlen($packet) / 16));
            $packet = $encrypted . $mac;
        }

        $this->writeAll($packet);
        ++$this->sendSeq;
    }

    /**
     * Читает следующий payload.
     *
     * @throws ConnectionException
     */
    public function receive(): string
    {
        if (!$this->encrypted) {
            $header = $this->readExact(5);
            $offset = 0;
            $packetLength = SshBinary::readUint32($header, $offset);
            $paddingLength = ord($header[4]);
            $rest = $this->readExact($packetLength - 1);
            $payloadLength = $packetLength - $paddingLength - 1;
            ++$this->recvSeq;

            return substr($rest, 0, $payloadLength);
        }

        $firstBlock = $this->readExact(16);
        $plainHeader = openssl_decrypt(
            $firstBlock,
            'aes-128-ctr',
            $this->serverToClientKey,
            OPENSSL_RAW_DATA,
            $this->serverToClientIv,
        );

        if ($plainHeader === false) { // @codeCoverageIgnore
            throw new ConnectionException('SSH decrypt header не удался'); // @codeCoverageIgnore
        }

        $this->serverToClientIv = $this->advanceCtr($this->serverToClientIv, 1);
        $offset = 0;
        $packetLength = SshBinary::readUint32($plainHeader, $offset);
        $paddingLength = ord($plainHeader[4]);
        $remaining = $packetLength + 4 - 16;

        if ($remaining < 0) { // @codeCoverageIgnore
            throw new ConnectionException('SSH: некорректный packet_length'); // @codeCoverageIgnore
        }

        $restEnc = $remaining > 0 ? $this->readExact($remaining) : '';
        $restPlain = $restEnc === '' ? '' : openssl_decrypt(
            $restEnc,
            'aes-128-ctr',
            $this->serverToClientKey,
            OPENSSL_RAW_DATA,
            $this->serverToClientIv,
        );

        if ($restPlain === false) { // @codeCoverageIgnore
            throw new ConnectionException('SSH decrypt body не удался'); // @codeCoverageIgnore
        }

        if ($remaining > 0) {
            $this->serverToClientIv = $this->advanceCtr($this->serverToClientIv, (int) ($remaining / 16));
        }

        $packet = $plainHeader . $restPlain;
        $mac = $this->readExact(32);
        $expected = hash_hmac('sha256', SshBinary::uint32($this->recvSeq) . $packet, $this->serverToClientMacKey, true);

        if (!hash_equals($expected, $mac)) { // @codeCoverageIgnore
            throw new ConnectionException('SSH MAC mismatch'); // @codeCoverageIgnore
        }

        ++$this->recvSeq;
        $payloadLength = $packetLength - $paddingLength - 1;

        return substr($packet, 5, $payloadLength);
    }

    /**
     * Закрывает stream.
     */
    public function close(): void
    {
        $stream = $this->stream;
        $this->stream = null;

        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    /**
     * @throws ConnectionException
     */
    private function writeAll(string $data): void
    {
        $stream = $this->openStream();
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $written = @fwrite($stream, substr($data, $offset));

            if ($written === false || $written === 0) { // @codeCoverageIgnore
                throw new ConnectionException('SSH write не удался'); // @codeCoverageIgnore
            }

            $offset += $written;
        }
    }

    /**
     * @throws ConnectionException
     */
    private function readExact(int $length): string
    {
        $stream = $this->openStream();
        $buffer = '';

        while (strlen($buffer) < $length) {
            $need = $length - strlen($buffer);

            if ($need < 1) { // @codeCoverageIgnore
                break; // @codeCoverageIgnore
            }

            $chunk = @fread($stream, $need);

            if ($chunk === false || $chunk === '') { // @codeCoverageIgnore
                throw new ConnectionException('SSH read не удался / соединение закрыто'); // @codeCoverageIgnore
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * @return resource
     *
     * @throws ConnectionException
     */
    private function openStream()
    {
        $stream = $this->stream;

        if (!is_resource($stream)) {
            throw new ConnectionException('SSH stream закрыт');
        }

        return $stream;
    }

    private function advanceCtr(string $counterIv, int $blocks): string
    {
        if ($blocks < 1) { // @codeCoverageIgnore
            return $counterIv; // @codeCoverageIgnore
        }

        $value = $counterIv;

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
}
