<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\TestEnvironment;
use App\Entity\TestRun;
use App\Message\TestRunMessage;
use App\MessageHandler\TestRunMessageHandler;
use App\Repository\SettingsRepository;
use App\Repository\TestRunRepository;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use App\Service\TestRunnerService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class TestRunMessageHandlerTest extends TestCase
{
    public function testInvokeExecutePhaseForwardsInjectedCallbacks(): void
    {
        $run = $this->createRun(runId: 279, envId: 1);
        $message = new TestRunMessage(279, 1, TestRunMessage::PHASE_EXECUTE);

        $testRunRepository = $this->createMock(TestRunRepository::class);
        $testRunRepository->expects($this->once())
            ->method('find')
            ->with(279)
            ->willReturn($run);

        $receiverLockRefreshCallback = static function (): void {};
        $heartbeatCallback = static function (): void {};

        $testRunnerService = $this->createMock(TestRunnerService::class);
        $testRunnerService->expects($this->once())
            ->method('executeRun')
            ->with($run, $receiverLockRefreshCallback, $heartbeatCallback);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects($this->once())->method('acquire')->willReturn(true);
        $lock->expects($this->once())->method('release');

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects($this->once())
            ->method('createLock')
            ->with('test_run_279', 3600)
            ->willReturn($lock);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (TestRunMessage $dispatchedMessage): bool {
                return 279 === $dispatchedMessage->testRunId
                    && 1 === $dispatchedMessage->environmentId
                    && TestRunMessage::PHASE_REPORT === $dispatchedMessage->phase;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $handler = new TestRunMessageHandler(
            $testRunRepository,
            $this->createStub(UserRepository::class),
            $this->createStub(SettingsRepository::class),
            $testRunnerService,
            $this->createStub(NotificationService::class),
            $messageBus,
            $lockFactory,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(Connection::class),
            $this->createStub(LoggerInterface::class),
        );

        $handler($message, $receiverLockRefreshCallback, $heartbeatCallback);
    }

    public function testInvokeExecutePhaseWorksWithoutInjectedCallbacks(): void
    {
        $run = $this->createRun(runId: 280, envId: 2);
        $message = new TestRunMessage(280, 2, TestRunMessage::PHASE_EXECUTE);

        $testRunRepository = $this->createMock(TestRunRepository::class);
        $testRunRepository->expects($this->once())
            ->method('find')
            ->with(280)
            ->willReturn($run);

        $testRunnerService = $this->createMock(TestRunnerService::class);
        $testRunnerService->expects($this->once())
            ->method('executeRun')
            ->with($run, null, null);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects($this->once())->method('acquire')->willReturn(true);
        $lock->expects($this->once())->method('release');

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects($this->once())
            ->method('createLock')
            ->with('test_run_280', 3600)
            ->willReturn($lock);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('debug')
            ->with('Receiver callbacks missing, transport heartbeat/lock refresh partially disabled', [
                'runId' => 280,
                'phase' => TestRunMessage::PHASE_EXECUTE,
            ]);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $handler = new TestRunMessageHandler(
            $testRunRepository,
            $this->createStub(UserRepository::class),
            $this->createStub(SettingsRepository::class),
            $testRunnerService,
            $this->createStub(NotificationService::class),
            $messageBus,
            $lockFactory,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(Connection::class),
            $logger,
        );

        $handler($message);
    }

    public function testInvokeRethrowsUnhandledThrowableToAllowRetry(): void
    {
        $run = $this->createRun(runId: 281, envId: 3);
        $message = new TestRunMessage(281, 3, 'unknown-phase');

        $testRunRepository = $this->createMock(TestRunRepository::class);
        $testRunRepository->expects($this->once())
            ->method('find')
            ->with(281)
            ->willReturn($run);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects($this->once())->method('acquire')->willReturn(true);
        $lock->expects($this->once())->method('release');

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects($this->once())
            ->method('createLock')
            ->with('test_run_281', 3600)
            ->willReturn($lock);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('Test run phase failed', $this->callback(static function (array $context): bool {
                return 281 === $context['runId']
                    && 'unknown-phase' === $context['phase']
                    && str_contains($context['error'], 'Unknown phase');
            }));

        $handler = new TestRunMessageHandler(
            $testRunRepository,
            $this->createStub(UserRepository::class),
            $this->createStub(SettingsRepository::class),
            $this->createStub(TestRunnerService::class),
            $this->createStub(NotificationService::class),
            $this->createStub(MessageBusInterface::class),
            $lockFactory,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(Connection::class),
            $logger,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown phase: unknown-phase');

        $handler($message);
    }

    private function createRun(int $runId, int $envId): TestRun
    {
        $environment = new TestEnvironment();
        $environment->setName('preprod-us');
        $environment->setCode('preprod-us');
        $environment->setRegion('us');
        $environment->setBaseUrl('https://example.test/');
        $this->setEntityId($environment, $envId);

        $run = new TestRun();
        $run->setEnvironment($environment);
        $run->setType(TestRun::TYPE_MFTF);
        $run->setStatus(TestRun::STATUS_PENDING);
        $this->setEntityId($run, $runId);

        return $run;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionClass($entity);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($entity, $id);
    }
}
