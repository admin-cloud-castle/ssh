<?php

declare(strict_types=1);

namespace CloudCastle\Ssh;

use CloudCastle\Ssh\Async\FiberAsyncRuntime;
use CloudCastle\Ssh\Client\SessionFactory;
use CloudCastle\Ssh\Contract\AsyncRuntimeInterface;
use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Contract\SessionFactoryInterface;
use CloudCastle\Ssh\Contract\SessionInterface;
use CloudCastle\Ssh\Contract\SessionPoolInterface;
use CloudCastle\Ssh\Contract\SshInterface;
use CloudCastle\Ssh\Exception\SshException;
use CloudCastle\Ssh\Forward\LocalPortForward;
use CloudCastle\Ssh\Pool\SessionPool;
use CloudCastle\Ssh\Process\ProcessPool;
use CloudCastle\Ssh\Scp\ScpClient;

/**
 * Публичный фасад cloud-castle/ssh: sync/async, пул, multiprocess, SCP, forward.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final class Ssh implements SshInterface
{
    /**
     * Версия пакета.
     */
    public const VERSION = '1.0.0';

    private ?SessionPoolInterface $poolSlot = null;

    private ?AsyncRuntimeInterface $asyncSlot = null;

    private ?ProcessPool $processSlot = null;

    /**
     * @param SessionFactoryInterface|null $factorySlot Внешняя фабрика (тесты/DI)
     * @param int                          $workers Число process-workers
     */
    public function __construct(private ?SessionFactoryInterface $factorySlot = null, private readonly int $workers = 2)
    {
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function name(): string
    {
        return 'cloud-castle/ssh';
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function connect(
        string $endpoint,
        AuthCredentials $auth,
        ?ConnectionOptions $options = null,
    ): SessionInterface {
        return $this->factory()->connect($endpoint, $auth, $options);
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function factory(): SessionFactoryInterface
    {
        return $this->factorySlot ??= new SessionFactory();
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function pool(): SessionPoolInterface
    {
        return $this->poolSlot ??= new SessionPool($this->factory());
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function async(): AsyncRuntimeInterface
    {
        return $this->asyncSlot ??= new FiberAsyncRuntime();
    }

    /**
     * Пул процессов для параллельных задач.
     */
    public function processes(): ProcessPool
    {
        return $this->processSlot ??= new ProcessPool($this->workers);
    }

    /**
     * SCP-клиент над сессией.
     */
    public function scp(SessionInterface $session): ScpClient
    {
        return new ScpClient($session);
    }

    /**
     * Локальный port-forward helper.
     */
    public function forward(SessionInterface $session): LocalPortForward
    {
        return new LocalPortForward($session);
    }

    /**
     * Завершает работу с ошибкой пакета.
     *
     * @throws SshException
     */
    public function abort(string $message): never
    {
        throw new SshException($message);
    }
}
