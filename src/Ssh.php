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
 * Публичный фасад cloud-castle/ssh: sync connect, пул, Fibers-async, multiprocess, SCP, forward.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final class Ssh implements SshInterface
{
    /**
     * SemVer-версия пакета (совпадает с релизным тегом мажор.минор.патч при релизе).
     *
     * @var string
     */
    public const VERSION = '1.0.0';

    /**
     * Ленивый слот пула сессий.
     */
    private ?SessionPoolInterface $poolSlot = null;

    /**
     * Ленивый слот async-рантайма.
     */
    private ?AsyncRuntimeInterface $asyncSlot = null;

    /**
     * Ленивый слот пула процессов.
     */
    private ?ProcessPool $processSlot = null;

    /**
     * Создаёт фасад; фабрику можно подменить для тестов/DI.
     *
     * @param SessionFactoryInterface|null $factorySlot Внешняя фабрика или null (Native SessionFactory)
     * @param int                          $workers     Число worker-процессов для ProcessPool
     */
    public function __construct(
        /** Слот фабрики сессий; null — ленивое создание SessionFactory. @var SessionFactoryInterface|null */
        private ?SessionFactoryInterface $factorySlot = null,
        /** Число worker-процессов ProcessPool (≥1 рекомендуется). @var int */
        private readonly int $workers = 2,
    ) {
    }

    /**
     * Возвращает Composer-имя пакета.
     *
     * @return string Всегда `cloud-castle/ssh`
     */
    #[\Override]
    public function name(): string
    {
        return 'cloud-castle/ssh';
    }

    /**
     * Синхронно подключается к endpoint через фабрику фасада.
     *
     * @param string                 $endpoint Цель SSH
     * @param AuthCredentials        $auth     Учётные данные
     * @param ConnectionOptions|null $options  Опции или null
     *
     * @return SessionInterface Активная сессия
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
     * Возвращает фабрику сессий (создаёт SessionFactory при первом обращении).
     *
     * @return SessionFactoryInterface Фабрика connect()
     */
    #[\Override]
    public function factory(): SessionFactoryInterface
    {
        return $this->factorySlot ??= new SessionFactory();
    }

    /**
     * Возвращает пул idle-сессий (создаёт SessionPool при первом обращении).
     *
     * @return SessionPoolInterface Пул над factory()
     */
    #[\Override]
    public function pool(): SessionPoolInterface
    {
        return $this->poolSlot ??= new SessionPool($this->factory());
    }

    /**
     * Возвращает Fiber async-рантайм (создаёт FiberAsyncRuntime при первом обращении).
     *
     * @return AsyncRuntimeInterface Рантайм awaitReadable/awaitWritable
     */
    #[\Override]
    public function async(): AsyncRuntimeInterface
    {
        return $this->asyncSlot ??= new FiberAsyncRuntime();
    }

    /**
     * Возвращает пул процессов для параллельных задач (создаёт при первом обращении).
     *
     * @return ProcessPool Пул с concurrency = $workers
     */
    public function processes(): ProcessPool
    {
        return $this->processSlot ??= new ProcessPool($this->workers);
    }

    /**
     * Создаёт SCP-клиент над переданной сессией.
     *
     * @param SessionInterface $session Активная сессия
     *
     * @return ScpClient Клиент get/put
     */
    public function scp(SessionInterface $session): ScpClient
    {
        return new ScpClient($session);
    }

    /**
     * Создаёт helper локального port-forward над сессией.
     *
     * @param SessionInterface $session Активная сессия
     *
     * @return LocalPortForward Клиент request()
     */
    public function forward(SessionInterface $session): LocalPortForward
    {
        return new LocalPortForward($session);
    }

    /**
     * Прерывает работу фасада, выбрасывая доменное исключение пакета.
     *
     * @param string $message Текст ошибки для вызывающего кода
     *
     * @throws SshException Всегда
     */
    public function abort(string $message): never
    {
        throw new SshException($message);
    }
}
