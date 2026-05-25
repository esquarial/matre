<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Settings;
use App\Entity\TestEnvironment;
use App\Entity\TestResult;
use App\Entity\TestRun;
use App\Entity\TestSuite;
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

    /**
     * shouldAutoRetry returns false for group/suite runs (they use inline retry).
     */
    public function testShouldAutoRetrySkipsGroupSuiteRuns(): void
    {
        $suite = new TestSuite();
        $suite->setName('Group Suite');
        $suite->setType(TestSuite::TYPE_MFTF_GROUP);

        $run = $this->createRunWithFailedResult(runId: 300, envId: 1);
        $run->setSuite($suite);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (TestRunMessage $msg): bool {
                return TestRunMessage::PHASE_REPORT === $msg->phase;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $testRunnerService = $this->createMock(TestRunnerService::class);
        $testRunnerService->expects($this->once())->method('executeRun');
        $testRunnerService->expects($this->never())->method('retryFailedRun');

        $handler = $this->buildHandler(
            run: $run,
            testRunnerService: $testRunnerService,
            messageBus: $messageBus,
            settingsRepository: $this->createSettingsRepository(maxRetryCount: 3),
        );

        $handler(new TestRunMessage(300, 1, TestRunMessage::PHASE_EXECUTE));
    }

    /**
     * shouldAutoRetry returns false when there are no failed/broken results.
     */
    public function testShouldAutoRetrySkipsWhenNoFailures(): void
    {
        $run = $this->createRun(runId: 301, envId: 1);
        // No results added — getResultCounts() returns all zeros

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (TestRunMessage $msg): bool {
                return TestRunMessage::PHASE_REPORT === $msg->phase;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $testRunnerService = $this->createMock(TestRunnerService::class);
        $testRunnerService->expects($this->once())->method('executeRun');
        $testRunnerService->expects($this->never())->method('retryFailedRun');

        $handler = $this->buildHandler(
            run: $run,
            testRunnerService: $testRunnerService,
            messageBus: $messageBus,
            settingsRepository: $this->createSettingsRepository(maxRetryCount: 3),
        );

        $handler(new TestRunMessage(301, 1, TestRunMessage::PHASE_EXECUTE));
    }

    /**
     * shouldAutoRetry returns false when maxRetryCount is 0 (disabled).
     */
    public function testShouldAutoRetrySkipsWhenRetryDisabled(): void
    {
        $run = $this->createRunWithFailedResult(runId: 302, envId: 1);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (TestRunMessage $msg): bool {
                return TestRunMessage::PHASE_REPORT === $msg->phase;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $testRunnerService = $this->createMock(TestRunnerService::class);
        $testRunnerService->expects($this->once())->method('executeRun');
        $testRunnerService->expects($this->never())->method('retryFailedRun');

        $handler = $this->buildHandler(
            run: $run,
            testRunnerService: $testRunnerService,
            messageBus: $messageBus,
            settingsRepository: $this->createSettingsRepository(maxRetryCount: 0),
        );

        $handler(new TestRunMessage(302, 1, TestRunMessage::PHASE_EXECUTE));
    }

    /**
     * shouldAutoRetry returns false when retryAttempt == maxRetries (boundary).
     */
    public function testShouldAutoRetryStopsAtMaxRetries(): void
    {
        $run = $this->createRunWithFailedResult(runId: 303, envId: 1);
        $run->setRetryAttempt(3); // Equal to maxRetryCount

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (TestRunMessage $msg): bool {
                return TestRunMessage::PHASE_REPORT === $msg->phase;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $testRunnerService = $this->createMock(TestRunnerService::class);
        $testRunnerService->expects($this->once())->method('executeRun');
        $testRunnerService->expects($this->never())->method('retryFailedRun');

        $handler = $this->buildHandler(
            run: $run,
            testRunnerService: $testRunnerService,
            messageBus: $messageBus,
            settingsRepository: $this->createSettingsRepository(maxRetryCount: 3),
        );

        $handler(new TestRunMessage(303, 1, TestRunMessage::PHASE_EXECUTE));
    }

    /**
     * shouldAutoRetry returns false when retryAttempt > maxRetries (overflow guard).
     */
    public function testShouldAutoRetryStopsAboveMaxRetries(): void
    {
        $run = $this->createRunWithFailedResult(runId: 304, envId: 1);
        $run->setRetryAttempt(5); // Above maxRetryCount

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (TestRunMessage $msg): bool {
                return TestRunMessage::PHASE_REPORT === $msg->phase;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $testRunnerService = $this->createMock(TestRunnerService::class);
        $testRunnerService->expects($this->once())->method('executeRun');
        $testRunnerService->expects($this->never())->method('retryFailedRun');

        $handler = $this->buildHandler(
            run: $run,
            testRunnerService: $testRunnerService,
            messageBus: $messageBus,
            settingsRepository: $this->createSettingsRepository(maxRetryCount: 3),
        );

        $handler(new TestRunMessage(304, 1, TestRunMessage::PHASE_EXECUTE));
    }

    /**
     * shouldAutoRetry returns true when retryAttempt == maxRetries - 1 (one retry left).
     * Verifies dispatchAutoRetry is called and PHASE_CLEANUP is dispatched for the original run.
     */
    public function testShouldAutoRetryProceedsWhenBelowMaxRetries(): void
    {
        $run = $this->createRunWithFailedResult(runId: 305, envId: 1);
        $run->setRetryAttempt(2); // maxRetries - 1

        $retryRun = $this->createRun(runId: 400, envId: 1);

        $testRunnerService = $this->createMock(TestRunnerService::class);
        $testRunnerService->expects($this->once())->method('executeRun');
        $testRunnerService->expects($this->once())
            ->method('retryFailedRun')
            ->with($run, null, 3)
            ->willReturn($retryRun);

        // Expect two dispatches: PHASE_PREPARE for retry run, then PHASE_CLEANUP for original
        $dispatched = [];
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (TestRunMessage $msg) use (&$dispatched): Envelope {
                $dispatched[] = ['runId' => $msg->testRunId, 'phase' => $msg->phase];

                return new Envelope(new \stdClass());
            });

        $handler = $this->buildHandler(
            run: $run,
            testRunnerService: $testRunnerService,
            messageBus: $messageBus,
            settingsRepository: $this->createSettingsRepository(maxRetryCount: 3),
        );

        $handler(new TestRunMessage(305, 1, TestRunMessage::PHASE_EXECUTE));

        $this->assertCount(2, $dispatched);
        // First: retry run PREPARE
        $this->assertEquals(400, $dispatched[0]['runId']);
        $this->assertEquals(TestRunMessage::PHASE_PREPARE, $dispatched[0]['phase']);
        // Second: original run CLEANUP
        $this->assertEquals(305, $dispatched[1]['runId']);
        $this->assertEquals(TestRunMessage::PHASE_CLEANUP, $dispatched[1]['phase']);
    }

    /**
     * When retryFailedRun returns null (no retryable failures), falls through to REPORT.
     */
    public function testAutoRetryFallsToReportWhenNoRetryableFailures(): void
    {
        $run = $this->createRunWithFailedResult(runId: 306, envId: 1);
        $run->setRetryAttempt(0);

        $testRunnerService = $this->createMock(TestRunnerService::class);
        $testRunnerService->expects($this->once())->method('executeRun');
        $testRunnerService->expects($this->once())
            ->method('retryFailedRun')
            ->willReturn(null); // No retryable failures

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (TestRunMessage $msg): bool {
                return TestRunMessage::PHASE_REPORT === $msg->phase && 306 === $msg->testRunId;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $handler = $this->buildHandler(
            run: $run,
            testRunnerService: $testRunnerService,
            messageBus: $messageBus,
            settingsRepository: $this->createSettingsRepository(maxRetryCount: 3),
        );

        $handler(new TestRunMessage(306, 1, TestRunMessage::PHASE_EXECUTE));
    }

    private function buildHandler(
        TestRun $run,
        TestRunnerService $testRunnerService,
        MessageBusInterface $messageBus,
        SettingsRepository $settingsRepository,
    ): TestRunMessageHandler {
        $testRunRepository = $this->createMock(TestRunRepository::class);
        $testRunRepository->method('find')->with($run->getId())->willReturn($run);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        return new TestRunMessageHandler(
            $testRunRepository,
            $this->createStub(UserRepository::class),
            $settingsRepository,
            $testRunnerService,
            $this->createStub(NotificationService::class),
            $messageBus,
            $lockFactory,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(Connection::class),
            $this->createStub(LoggerInterface::class),
        );
    }

    private function createSettingsRepository(int $maxRetryCount): SettingsRepository
    {
        $settings = new Settings();
        $settings->setMaxRetryCount($maxRetryCount);

        $repo = $this->createStub(SettingsRepository::class);
        $repo->method('getSettings')->willReturn($settings);

        return $repo;
    }

    private function createRunWithFailedResult(int $runId, int $envId): TestRun
    {
        $run = $this->createRun($runId, $envId);

        $result = new TestResult();
        $result->setTestName('FailingTest');
        $result->setStatus(TestResult::STATUS_FAILED);
        $result->setTestRun($run);
        $run->addResult($result);

        return $run;
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
