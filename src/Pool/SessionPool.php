<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Pool;

use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Contract\SessionFactoryInterface;
use CloudCastle\Ssh\Contract\SessionInterface;
use CloudCastle\Ssh\Contract\SessionPoolInterface;

/**
 * Пул idle SSH-сессий с ключом endpoint|sftp.
 */
final class SessionPool implements SessionPoolInterface
{
    private readonly int $maxPerKey;

    /**
     * @var array<string, list<SessionInterface>>
     */
    private array $idle = [];

    /**
     * @param SessionFactoryInterface $factory   Фабрика
     * @param int                     $maxPerKey Максимум idle на ключ
     */
    public function __construct(
        private readonly SessionFactoryInterface $factory,
        int $maxPerKey = 8,
    ) {
        $this->maxPerKey = $maxPerKey > 0 ? $maxPerKey : 8;
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function acquire(
        string $endpoint,
        AuthCredentials $auth,
        ?ConnectionOptions $options = null,
        bool $sftp = false,
    ): SessionInterface {
        $key = $this->key($endpoint, $sftp);

        while (isset($this->idle[$key]) && $this->idle[$key] !== []) {
            $session = array_pop($this->idle[$key]);

            if ($session->isConnected()) {
                return $session;
            }

            $session->disconnect();
        }

        return $this->factory->connect($endpoint, $auth, $options, $sftp);
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function release(SessionInterface $session): void
    {
        if (!$session->isConnected()) {
            return;
        }

        $key = $this->key($session->remoteEndpoint(), false);
        $bucket = $this->idle[$key] ?? [];

        if (count($bucket) >= $this->maxPerKey) {
            $session->disconnect();

            return;
        }

        $bucket[] = $session;
        $this->idle[$key] = $bucket;
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function drain(): void
    {
        foreach ($this->idle as $bucket) {
            foreach ($bucket as $session) {
                $session->disconnect();
            }
        }

        $this->idle = [];
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function size(): int
    {
        $total = 0;

        foreach ($this->idle as $bucket) {
            $total += count($bucket);
        }

        return $total;
    }

    private function key(string $endpoint, bool $sftp): string
    {
        return strtolower($endpoint) . '|' . ($sftp ? 'sftp' : 'ssh');
    }
}
