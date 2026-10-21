<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Security;

use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Exception\HostKeyException;

/**
 * Timing-safe проверка SHA-256 fingerprint host key по политике ConnectionOptions.
 */
final class HostKeyVerifier
{
    /**
     * Сверяет fingerprint сервера с trustedHostKeys и флагом strictHostKey.
     *
     * Если список null и strict выключен — проверка пропускается.
     * Если список null и strict включён — ошибка.
     * Если список задан — требуется hash_equals с одним из элементов.
     *
     * @param string            $fingerprint Base64 SHA-256 без обязательного префикса SHA256:
     * @param ConnectionOptions $options     Политика host key
     *
     * @throws HostKeyException При несовпадении или пустом списке в strict-режиме
     */
    public function verify(string $fingerprint, ConnectionOptions $options): void
    {
        $trusted = $options->trustedHostKeys();

        if ($trusted === null) {
            if ($options->strictHostKey()) {
                throw new HostKeyException('strictHostKey включён, но trustedHostKeys пуст');
            }

            return;
        }

        foreach ($trusted as $item) {
            $normalized = $this->normalize($item);

            if (hash_equals($normalized, $fingerprint)) {
                return;
            }
        }

        throw new HostKeyException('Host key fingerprint не совпал с доверенным списком');
    }

    /**
     * Убирает префикс «SHA256:», если он присутствует в строке fingerprint.
     *
     * @param string $fingerprint Сырой или префиксированный fingerprint
     *
     * @return string Base64 без префикса
     */
    private function normalize(string $fingerprint): string
    {
        if (str_starts_with($fingerprint, 'SHA256:')) {
            return substr($fingerprint, 7);
        }

        return $fingerprint;
    }
}
