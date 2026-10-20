<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Security;

use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Exception\HostKeyException;

/**
 * Проверка SHA256 fingerprint host key (timing-safe).
 */
final class HostKeyVerifier
{
    /**
     * Сверяет fingerprint с доверенным списком при strictHostKey.
     *
     * @throws HostKeyException
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
     * Убирает префикс SHA256: если есть.
     */
    private function normalize(string $fingerprint): string
    {
        if (str_starts_with($fingerprint, 'SHA256:')) {
            return substr($fingerprint, 7);
        }

        return $fingerprint;
    }
}
