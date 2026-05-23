<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\TestRun;
use App\Entity\User;
use App\Message\TestRunMessage;
use App\Repository\SettingsRepository;
use App\Repository\TestRunRepository;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use App\Service\TestRunnerService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class TestRunMessageHandler
{
    public function __construct(
        private readonly TestRunRepository $testRunRepository,
        private readonly UserRepository $userRepository,
        private readonly SettingsRepository $settingsRepository,
        private readonly TestRunnerService $testRunnerService,
        private readonly NotificationService $notificationService,
        private readonly MessageBusInterface $messageBus,
        private readonly LockFactory $lockFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        TestRunMessage $message,
        ?\Closure $receiverLockRefreshCallback = null,
        ?\Closure $heartbeatCallback = null,
    ): void {
        $runId = $message->testRunId;
        $phase = $message->phase;

        $this->logger->info('Processing test run message', [
            'runId' => $runId,
            'phase' => $phase,
        ]);

        $run = $this->testRunRepository->find($runId);
        if (!$run) {
            $this->logger->error('Test run not found', ['runId' => $runId]);

            return;
        }

        if (null === $receiverLockRefreshCallback || null === $heartbeatCallback) {
            $this->logger->debug('Receiver callbacks missing, transport heartbeat/lock refresh partially disabled', [
                'runId' => $runId,
                'phase' => $phase,
            ]);
        }

        // Skip only early phases for failed/cancelled runs (REPORT must run to dispatch NOTIFY)
        $skipForFailedStatuses = [TestRun::STATUS_CANCELLED, TestRun::STATUS_FAILED];
        $phasesToSkipWhenFailed = [
            TestRunMessage::PHASE_PREPARE,
            TestRunMessage::PHASE_EXECUTE,
        ];

        if (in_array($run->getStatus(), $skipForFailedStatuses, true)
            && in_array($phase, $phasesToSkipWhenFailed, true)) {
            $this->logger->info('Skipping phase for cancelled/failed run, jumping to REPORT', [
                'runId' => $runId,
                'phase' => $phase,
            ]);

            // Dispatch REPORT to continue pipeline toward NOTIFY (for failure notification)
            $this->messageBus->dispatch(new TestRunMessage(
                $runId,
                $run->getEnvironment()->getId(),
                TestRunMessage::PHASE_REPORT,
            ));

            return;
        }

        // Acquire lock to prevent concurrent execution
        $lock = $this->lockFactory->createLock('test_run_' . $runId, 3600);
        if (!$lock->acquire()) {
            $this->logger->warning('Could not acquire lock for test run', ['runId' => $runId]);

            return;
        }

        try {
            match ($phase) {
                TestRunMessage::PHASE_PREPARE => $this->handlePrepare($run),
                TestRunMessage::PHASE_EXECUTE => $this->handleExecute($run, $receiverLockRefreshCallback, $heartbeatCallback),
                TestRunMessage::PHASE_REPORT => $this->handleReport($run),
                TestRunMessage::PHASE_NOTIFY => $this->handleNotify($run),
                TestRunMessage::PHASE_CLEANUP => $this->handleCleanup($run),
                default => throw new \InvalidArgumentException('Unknown phase: ' . $phase),
            };
        } catch (\Throwable $e) {
            $this->logger->error('Test run phase failed', [
                'runId' => $runId,
                'phase' => $phase,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function handlePrepare(TestRun $run): void
    {
        $nextPhase = TestRunMessage::PHASE_EXECUTE;

        try {
            $this->testRunnerService->prepareRun($run);
        } catch (\Throwable $e) {
            $this->logger->error('Prepare phase failed, skipping to REPORT', [
                'runId' => $run->getId(),
                'error' => $e->getMessage(),
            ]);
            // Skip EXECUTE, go straight to REPORT (which will dispatch NOTIFY)
            $nextPhase = TestRunMessage::PHASE_REPORT;
        }

        $this->messageBus->dispatch(new TestRunMessage(
            $run->getId(),
            $run->getEnvironment()->getId(),
            $nextPhase,
        ));
    }

    private function handleExecute(TestRun $run, ?\Closure $receiverLockRefreshCallback = null, ?\Closure $heartbeatCallback = null): void
    {
        try {
            $this->testRunnerService->executeRun($run, $receiverLockRefreshCallback, $heartbeatCallback);
        } catch (LockAcquiringException $e) {
            // Environment lock timeout (another worker is executing this environment)
            // Still proceed to REPORT - idempotency guards in NOTIFY prevent duplicates
            $this->logger->warning('Execute phase lock timeout - another worker may be running', [
                'runId' => $run->getId(),
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Execute phase failed, continuing to REPORT', [
                'runId' => $run->getId(),
                'error' => $e->getMessage(),
            ]);
            // Continue to REPORT - service should have marked run as failed
        }

        // Always dispatch REPORT (idempotency in NOTIFY prevents duplicate notifications)
        $this->messageBus->dispatch(new TestRunMessage(
            $run->getId(),
            $run->getEnvironment()->getId(),
            TestRunMessage::PHASE_REPORT,
        ));
    }

    private function handleReport(TestRun $run): void
    {
        // Generate reports for all runs except cancelled (we need reports to see what failed!)
        if (TestRun::STATUS_CANCELLED !== $run->getStatus()) {
            $shouldGenerateReport = true;

            // Skip report for individual (non-suite) runs when setting is disabled
            if (null === $run->getSuite()) {
                $settings = $this->settingsRepository->getSettings();
                if (!$settings->isAutoReportForIndividualRuns()) {
                    $shouldGenerateReport = false;
                    $this->logger->info('Skipping report for individual run (disabled in settings)', [
                        'runId' => $run->getId(),
                    ]);
                    // Mark run as completed (preserve failed status if already failed)
                    if (TestRun::STATUS_FAILED !== $run->getStatus()) {
                        $run->markCompleted();
                    }
                    $this->entityManager->flush();
                }
            }

            if ($shouldGenerateReport) {
                try {
                    $this->testRunnerService->generateReports($run);
                } catch (\Throwable $e) {
                    $this->logger->error('Report generation failed, continuing to NOTIFY', [
                        'runId' => $run->getId(),
                        'error' => $e->getMessage(),
                    ]);
                    // Continue to NOTIFY regardless
                }
            }
        } else {
            $this->logger->info('Skipping report generation for cancelled run', ['id' => $run->getId()]);
        }

        // Always dispatch NOTIFY (even for failed runs - they need failure notifications!)
        $this->messageBus->dispatch(new TestRunMessage(
            $run->getId(),
            $run->getEnvironment()->getId(),
            TestRunMessage::PHASE_NOTIFY,
        ));
    }

    private function handleNotify(TestRun $run): void
    {
        // Skip notifications if disabled for this run
        if (!$run->isSendNotifications()) {
            $this->logger->info('Notifications disabled for this run', ['runId' => $run->getId()]);
            $this->messageBus->dispatch(new TestRunMessage(
                $run->getId(),
                $run->getEnvironment()->getId(),
                TestRunMessage::PHASE_CLEANUP,
            ));

            return;
        }

        // Atomic idempotency guard - UPDATE only if notification_sent_at IS NULL
        // This prevents race conditions between concurrent NOTIFY messages
        try {
            $affectedRows = $this->connection->executeStatement(
                'UPDATE matre_test_runs SET notification_sent_at = NOW() WHERE id = ? AND notification_sent_at IS NULL',
                [$run->getId()],
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to set notification flag atomically, aborting to prevent duplicates', [
                'runId' => $run->getId(),
                'error' => $e->getMessage(),
            ]);

            // Don't send - safer to miss than duplicate. Retry will attempt again.
            throw $e;
        }

        if (0 === $affectedRows) {
            // Another worker already sent notifications
            $this->logger->info('Notification already sent by another worker, skipping', ['runId' => $run->getId()]);
            $this->messageBus->dispatch(new TestRunMessage(
                $run->getId(),
                $run->getEnvironment()->getId(),
                TestRunMessage::PHASE_CLEANUP,
            ));

            return;
        }

        // Refresh entity to reflect the DB change
        $this->entityManager->refresh($run);

        try {
            // Slack: send if ANY subscribed user has Slack enabled
            if ($this->userRepository->shouldSendSlackNotification($run)) {
                $this->notificationService->sendSlackNotification($run);
            }

            // Email: individual emails to each subscribed user
            $usersToEmail = $this->userRepository->findUsersToNotifyByEmail($run);
            $recipients = array_map(static fn (User $u) => $u->getEmail(), $usersToEmail);

            if (!empty($recipients)) {
                $this->notificationService->sendEmailNotification($run, $recipients);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Notification phase failed, continuing to CLEANUP', [
                'runId' => $run->getId(),
                'error' => $e->getMessage(),
            ]);
            // Flag already set - better to miss than duplicate
        }

        // Always dispatch CLEANUP
        $this->messageBus->dispatch(new TestRunMessage(
            $run->getId(),
            $run->getEnvironment()->getId(),
            TestRunMessage::PHASE_CLEANUP,
        ));
    }

    private function handleCleanup(TestRun $run): void
    {
        try {
            $this->testRunnerService->cleanupRun($run);
        } catch (\Throwable $e) {
            $this->logger->error('Cleanup phase failed', [
                'runId' => $run->getId(),
                'error' => $e->getMessage(),
            ]);
            // No next phase - just log and continue
        }
    }
}
