<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TestEnvironment;
use App\Entity\TestReport;
use App\Entity\TestResult;
use App\Entity\TestRun;
use App\Entity\TestSuite;
use App\Entity\User;
use App\Repository\SettingsRepository;
use App\Repository\TestRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Orchestrates test execution workflow.
 */
class TestRunnerService
{
    private const RETRYABLE_ERROR_PATTERNS = [
        'TimeoutException',
        'ElementNotInteractableException',
        'ElementClickInterceptedException',
        'JavascriptErrorException',
        'StaleElementReferenceException',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TestRunRepository $testRunRepository,
        private readonly SettingsRepository $settingsRepository,
        private readonly ModuleCloneService $moduleCloneService,
        private readonly MftfExecutorService $mftfExecutor,
        private readonly PlaywrightExecutorService $playwrightExecutor,
        private readonly AllureReportService $allureReportService,
        private readonly ArtifactCollectorService $artifactCollector,
        private readonly AllureStepParserService $allureStepParser,
        private readonly LoggerInterface $logger,
        private readonly TestDiscoveryService $testDiscovery,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /**
     * Create a new test run.
     */
    public function createRun(
        TestEnvironment $environment,
        string $type,
        ?string $testFilter = null,
        ?TestSuite $suite = null,
        string $triggeredBy = TestRun::TRIGGER_MANUAL,
        bool $sendNotifications = true,
        ?User $executedBy = null,
    ): TestRun {
        $run = new TestRun();
        $run->setEnvironment($environment);
        $run->setType($type);
        $run->setTestFilter($testFilter);
        $run->setSuite($suite);
        $run->setTriggeredBy($triggeredBy);
        $run->setSendNotifications($sendNotifications);
        $run->setExecutedBy($executedBy);
        $run->setStatus(TestRun::STATUS_PENDING);

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->logger->info('Test run created', [
            'id' => $run->getId(),
            'type' => $type,
            'environment' => $environment->getName(),
        ]);

        return $run;
    }

    /**
     * Prepare the test run (clone module, set up environment).
     */
    public function prepareRun(TestRun $run): void
    {
        $this->logger->info('Preparing test run', ['id' => $run->getId()]);

        $run->setStatus(TestRun::STATUS_PREPARING);
        $this->entityManager->flush();

        try {
            // Clear root-level artifacts to prevent contamination from previous runs
            $this->artifactCollector->clearRootLevelArtifacts();

            // Prepare shared module (with locking for concurrent access)
            $run->setStatus(TestRun::STATUS_CLONING);
            $this->entityManager->flush();

            $this->moduleCloneService->prepareModule();

            $this->logger->info('Test run prepared', ['id' => $run->getId()]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to prepare test run', [
                'id' => $run->getId(),
                'error' => $e->getMessage(),
            ]);
            $run->markFailed($e->getMessage());
            $this->entityManager->flush();

            throw $e;
        }
    }

    /**
     * Execute the test run.
     */
    public function executeRun(TestRun $run, ?\Closure $receiverLockRefreshCallback = null, ?\Closure $heartbeatCallback = null): void
    {
        $this->logger->info('Executing test run', ['id' => $run->getId()]);
        $this->allureReportService->resetSentFiles();

        // Set WAITING status before blocking lock acquisition
        $run->setStatus(TestRun::STATUS_WAITING);
        $this->entityManager->flush();

        // Per-env lock - serializes same-env runs to prevent artifact contamination
        // Different-env runs can still be parallel (per-run dirs isolate artifacts)
        $envLock = $this->lockFactory->createLock(
            'mftf_execution_env_' . $run->getEnvironment()->getId(),
            1800, // 30 min timeout
        );
        $envLock->acquire(true); // blocking

        // Create lock refresh callback to prevent expiration during long-running tests
        // Combines: receiver lock (from message queue) + execution lock (per-environment)
        $lockRefreshCallback = static function () use ($envLock, $receiverLockRefreshCallback): void {
            $envLock->refresh();
            if ($receiverLockRefreshCallback) {
                $receiverLockRefreshCallback();
            }
        };

        try {
            // Mark execution started (sets RUNNING status and startedAt timestamp)
            $run->markExecutionStarted();
            $type = $run->getType();

            // Set output file path so live streaming can find it
            if (TestRun::TYPE_MFTF === $type || TestRun::TYPE_BOTH === $type) {
                $run->setOutputFilePath($this->mftfExecutor->getOutputFilePath($run));
            } elseif (TestRun::TYPE_PLAYWRIGHT === $type) {
                $run->setOutputFilePath($this->playwrightExecutor->getOutputFilePath($run));
            }
            $this->entityManager->flush();

            // Wrap heartbeat to refresh updatedAt during execution (prevents watchdog false positives)
            $wrappedHeartbeat = function () use ($run, $heartbeatCallback): void {
                try {
                    $run->setUpdatedAt(new \DateTimeImmutable());
                    $this->entityManager->flush();
                } catch (\Throwable $e) {
                    $this->logger->warning('Heartbeat DB flush failed, continuing execution', [
                        'runId' => $run->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($heartbeatCallback) {
                    $heartbeatCallback();
                }
            };

            // Check if this is a retry-failed run (specific failed tests)
            $retryTestIds = $run->getRetryTestIdsArray();
            if (!empty($retryTestIds)) {
                $this->executeRetryRun($run, $retryTestIds, $lockRefreshCallback, $wrappedHeartbeat);

                return;
            }

            // Check if this is a group run that should use sequential execution
            $suite = $run->getSuite();
            $isGroupRun = null !== $suite && TestSuite::TYPE_MFTF_GROUP === $suite->getType();

            if ($isGroupRun) {
                // Sequential execution - one test at a time
                // Note: Report generation and notifications are handled by the message handler
                // (PHASE_REPORT and PHASE_NOTIFY) after executeRun returns
                $this->executeGroupRun($run, $lockRefreshCallback, $wrappedHeartbeat);

                return;
            }

            $allResults = [];
            $allurePaths = [];
            $output = '';
            $fatalError = false;
            $fatalErrorReason = '';

            try {
                // Execute MFTF tests
                if (TestRun::TYPE_MFTF === $type || TestRun::TYPE_BOTH === $type) {
                    $mftfResult = $this->mftfExecutor->execute($run, $lockRefreshCallback, $wrappedHeartbeat);
                    $output .= "=== MFTF Output ===\n" . $this->sanitizeMftfOutput($mftfResult['output']) . "\n\n";

                    // Check for fatal errors that prevent test execution
                    $isFatalError = preg_match('/ERROR: \d+ Test\(s\) failed to generate/i', $mftfResult['output'])
                        || preg_match('/is not available under/i', $mftfResult['output']);

                    // ALWAYS parse results (even on failure) to capture partial test data
                    $mftfResults = $this->mftfExecutor->parseResults(
                        $run,
                        $mftfResult['output'],
                        $run->getOutputFilePath(),
                    );
                    foreach ($mftfResults as $result) {
                        $run->addResult($result);
                        $this->entityManager->persist($result);
                        $allResults[] = $result;
                    }

                    $allurePaths[] = $this->mftfExecutor->getAllureResultsPath($run->getId());

                    // Track fatal errors only - normal test failures handled in final status
                    if (0 !== $mftfResult['exitCode'] && $isFatalError) {
                        $fatalError = true;
                        if (preg_match('/ERROR: \d+ Test\(s\) failed to generate/i', $mftfResult['output'])) {
                            $fatalErrorReason = 'MFTF test generation failed - see output log';
                        } else {
                            $fatalErrorReason = 'Generated test file not found - see output log';
                        }
                    }
                }

                // Execute Playwright tests
                if (TestRun::TYPE_PLAYWRIGHT === $type || TestRun::TYPE_BOTH === $type) {
                    $playwrightResult = $this->playwrightExecutor->execute($run, null, $wrappedHeartbeat);
                    $output .= "=== Playwright Output ===\n" . $playwrightResult['output'] . "\n\n";

                    // ALWAYS parse results (even on failure) to capture partial test data
                    $playwrightResults = $this->playwrightExecutor->parseResults($run, $playwrightResult['output']);
                    foreach ($playwrightResults as $result) {
                        $run->addResult($result);
                        $this->entityManager->persist($result);
                        $allResults[] = $result;
                    }

                    $allurePaths[] = $this->playwrightExecutor->getAllureResultsPath();
                    // Note: Normal test failures handled in final status logic
                }

                $run->setOutput($output);

                // ALWAYS collect artifacts (even on failure) - screenshots/HTML are valuable for debugging
                $artifacts = $this->artifactCollector->collectArtifacts($run);
                if (!empty($artifacts['screenshots'])) {
                    $this->artifactCollector->associateScreenshotsWithResults($allResults, $artifacts['screenshots']);
                }

                $this->entityManager->flush();

                // Determine final status - FAILED only for fatal errors or complete failure
                $totalCount = count($allResults);
                $brokenCount = count(array_filter($allResults, fn ($r) => $r->isBroken()));
                $passedCount = count(array_filter($allResults, fn ($r) => $r->isPassed()));
                $failedTestCount = count(array_filter($allResults, fn ($r) => $r->isFailed() || $r->isBroken()));

                if ($fatalError) {
                    // Fatal execution error (generation failed, file not found)
                    $run->markFailed($fatalErrorReason);
                } elseif (0 === $totalCount) {
                    $run->markFailed('No test results collected');
                } elseif ($brokenCount === $totalCount) {
                    $run->markFailed('All tests broken - possible infrastructure error');
                } elseif (0 === $passedCount && $failedTestCount > 0) {
                    $run->markFailed(sprintf('All %d test(s) failed', $failedTestCount));
                } else {
                    // Some tests passed - mark completed (even with failures)
                    $run->markCompleted();
                }
                $this->entityManager->flush();

                $this->logger->info('Test execution completed', [
                    'id' => $run->getId(),
                    'resultCount' => $totalCount,
                    'passedCount' => $passedCount,
                    'failedCount' => $failedTestCount,
                    'status' => $run->getStatus(),
                    'artifacts' => [
                        'screenshots' => count($artifacts['screenshots']),
                        'html' => count($artifacts['html']),
                    ],
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Test execution failed', [
                    'id' => $run->getId(),
                    'error' => $e->getMessage(),
                ]);

                $this->entityManager->refresh($run);
                if (TestRun::STATUS_CANCELLED === $run->getStatus()) {
                    $this->logger->info('Test run cancelled during execution', [
                        'id' => $run->getId(),
                    ]);
                    $run->setOutput($output . "\n\nCANCELLED: " . $e->getMessage());
                    $this->entityManager->flush();

                    return;
                }
                $run->setOutput($output . "\n\nERROR: " . $e->getMessage());
                $run->markFailed($e->getMessage());
                $this->entityManager->flush();

                throw $e;
            }
        } finally {
            $envLock->release();
        }
    }

    /**
     * Generate reports for the test run.
     */
    public function generateReports(TestRun $run): TestReport
    {
        $this->logger->info('Generating reports', ['id' => $run->getId()]);

        // Remember if run was already failed (so we can preserve that status)
        $wasAlreadyFailed = TestRun::STATUS_FAILED === $run->getStatus();

        $run->setStatus(TestRun::STATUS_REPORTING);
        $this->entityManager->flush();

        try {
            $allurePaths = [];
            $type = $run->getType();

            if (TestRun::TYPE_MFTF === $type || TestRun::TYPE_BOTH === $type) {
                $allurePaths[] = $this->mftfExecutor->getAllureResultsPath($run->getId());
            }

            if (TestRun::TYPE_PLAYWRIGHT === $type || TestRun::TYPE_BOTH === $type) {
                $allurePaths[] = $this->playwrightExecutor->getAllureResultsPath();
            }

            try {
                $report = $this->allureReportService->generateReport($run, $allurePaths);
            } catch (\Throwable $e) {
                $this->logger->warning('Allure report generation failed, creating placeholder', [
                    'id' => $run->getId(),
                    'error' => $e->getMessage(),
                ]);

                // Create placeholder report - run still completes
                $report = new TestReport();
                $report->setTestRun($run);
                $report->setReportType(TestReport::TYPE_ALLURE);
                $report->setFilePath('');
                $report->setPublicUrl('');
                $report->setGeneratedAt(new \DateTimeImmutable());

                // Add warning to run output so user knows why report is missing
                $run->setOutput(
                    ($run->getOutput() ?? '') .
                    "\n\n⚠️ Allure report generation failed: " . $e->getMessage(),
                );
            }
            $this->entityManager->persist($report);

            // Only mark as completed if not already failed (preserve failure status)
            if ($wasAlreadyFailed) {
                $run->markFailed($run->getErrorMessage() ?? 'Test execution failed');
            } else {
                $run->markCompleted();
            }
            $this->entityManager->flush();

            // Per-run directories are cleaned up by clearOldRunDirectories() (scheduled task)
            // No need to clear after each run since artifacts are isolated per-run

            $this->logger->info('Reports generated', [
                'id' => $run->getId(),
                'reportUrl' => $report->getPublicUrl(),
            ]);

            return $report;
        } catch (\Throwable $e) {
            $this->logger->error('Report generation failed', [
                'id' => $run->getId(),
                'error' => $e->getMessage(),
            ]);
            $run->markFailed('Report generation failed: ' . $e->getMessage());
            $this->entityManager->flush();

            throw $e;
        }
    }

    /**
     * Cancel a running or pending test run.
     */
    public function cancelRun(TestRun $run): void
    {
        if (!$run->canBeCancelled()) {
            throw new \RuntimeException('Test run cannot be cancelled in current state');
        }

        $this->logger->info('Cancelling test run', ['id' => $run->getId()]);

        $run->markCancelled();
        $this->entityManager->flush();

        if (TestRun::TYPE_MFTF === $run->getType() || TestRun::TYPE_BOTH === $run->getType()) {
            $this->mftfExecutor->stopRun($run);
        }

        // Note: Module is shared, no per-run cleanup needed
    }

    /**
     * Retry a failed test run (full retry of all tests).
     */
    public function retryRun(TestRun $originalRun, ?User $executedBy = null): TestRun
    {
        $newRun = $this->createRun(
            $originalRun->getEnvironment(),
            $originalRun->getType(),
            $originalRun->getTestFilter(),
            $originalRun->getSuite(),
            TestRun::TRIGGER_MANUAL,
            $originalRun->isSendNotifications(),
            $executedBy,
        );

        $newRun->setOriginalRun($originalRun->getRootRun());
        $newRun->setRetryAttempt($originalRun->getRetryAttempt() + 1);
        $this->entityManager->flush();

        return $newRun;
    }

    /**
     * Retry only the failed tests from a completed run.
     *
     * Analyzes each failed result to determine if it's a retryable infrastructure error.
     * Returns null if no retryable failures are found.
     */
    public function retryFailedRun(TestRun $originalRun, ?User $executedBy = null, int $retryAttempt = 1): ?TestRun
    {
        // Atomic guard: prevent duplicate retry spawns on message redelivery.
        // UPDATE only if retry_spawned_at IS NULL — same pattern as notification_sent_at.
        $affectedRows = $this->entityManager->getConnection()->executeStatement(
            'UPDATE matre_test_runs SET retry_spawned_at = NOW() WHERE id = ? AND retry_spawned_at IS NULL',
            [$originalRun->getId()],
        );

        if (0 === $affectedRows) {
            $this->logger->info('Retry already spawned for this run (redelivery guard)', [
                'runId' => $originalRun->getId(),
            ]);

            return null;
        }

        // Refresh entity to reflect the DB change
        $this->entityManager->refresh($originalRun);

        $retryableTestIds = [];

        foreach ($originalRun->getResults() as $result) {
            if ($this->isRetryableFailure($result)) {
                $testId = $result->getTestId() ?: $result->getTestName();
                $retryableTestIds[] = $testId;
            }
        }

        if (empty($retryableTestIds)) {
            $this->logger->info('No retryable failures found', [
                'runId' => $originalRun->getId(),
            ]);

            // Reset the flag so a manual retry can still be triggered
            $originalRun->setRetrySpawnedAt(null);
            $this->entityManager->flush();

            return null;
        }

        $this->logger->info('Creating retry run for failed tests', [
            'originalRunId' => $originalRun->getId(),
            'retryableCount' => \count($retryableTestIds),
            'retryableTests' => $retryableTestIds,
            'attempt' => $retryAttempt,
        ]);

        $newRun = $this->createRun(
            $originalRun->getEnvironment(),
            $originalRun->getType(),
            1 === \count($retryableTestIds) ? $retryableTestIds[0] : null,
            $originalRun->getSuite(),
            $executedBy ? TestRun::TRIGGER_MANUAL : $originalRun->getTriggeredBy(),
            $originalRun->isSendNotifications(),
            $executedBy,
        );

        $newRun->setOriginalRun($originalRun->getRootRun());
        $newRun->setRetryAttempt($retryAttempt);
        $newRun->setRetryTestIdsFromArray($retryableTestIds);
        $this->entityManager->flush();

        return $newRun;
    }

    /**
     * Clean up resources for a completed test run.
     */
    public function cleanupRun(TestRun $run): void
    {
        // Module is shared across runs, no per-run cleanup needed
        // Per-run artifacts (mftf-results/run-{id}, allure-results/run-{id}) are cleaned by CleanupTestsCommand
        $this->logger->info('Test run cleanup completed', ['id' => $run->getId()]);
    }

    /**
     * Get test runs currently running for an environment.
     */
    public function hasRunningForEnvironment(TestEnvironment $environment): bool
    {
        return $this->testRunRepository->hasRunningForEnvironment($environment);
    }

    /**
     * Execute a retry run with specific failed test IDs sequentially.
     *
     * @param string[] $testIds Test IDs to retry
     */
    private function executeRetryRun(TestRun $run, array $testIds, callable $lockRefreshCallback, ?\Closure $heartbeatCallback = null): void
    {
        $this->logger->info('Starting retry execution for failed tests', [
            'runId' => $run->getId(),
            'testIds' => $testIds,
            'attempt' => $run->getRetryAttempt(),
        ]);

        $maxRetries = $this->settingsRepository->getSettings()->getMaxRetryCount();
        $totalTests = \count($testIds);
        $completedTests = $run->getResults()->count(); // Redelivery protection

        $run->setProgress($completedTests, $totalTests);
        $run->setStatus(TestRun::STATUS_RUNNING);
        $this->entityManager->flush();

        foreach ($testIds as $testId) {
            // Skip already-executed tests (redelivery protection)
            $existingResult = $run->getResults()->filter(
                fn ($r) => $r->getTestName() === $testId || $r->getTestId() === $testId,
            )->first();

            if ($existingResult) {
                $this->logger->info('Skipping already-executed test (redelivery protection)', [
                    'runId' => $run->getId(),
                    'testId' => $testId,
                ]);
                ++$completedTests;

                continue;
            }

            // Check for cancellation
            $this->entityManager->refresh($run);
            if (TestRun::STATUS_CANCELLED === $run->getStatus()) {
                $this->logger->info('Retry run cancelled', ['runId' => $run->getId()]);

                break;
            }

            $run->setCurrentTestName($testId);
            $run->setProgress($completedTests, $totalTests);
            $run->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->logger->info('Retrying failed test', [
                'runId' => $run->getId(),
                'testId' => $testId,
                'progress' => ($completedTests + 1) . '/' . $totalTests,
            ]);

            $cancelled = $this->executeSingleTestWithRetry($run, $testId, $maxRetries, $lockRefreshCallback, $heartbeatCallback);

            if ($cancelled) {
                break;
            }

            ++$completedTests;
            $run->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        }

        // Clear current test
        $run->setCurrentTestName(null);
        $run->setProgress($completedTests, $totalTests);
        $this->entityManager->flush();

        // Collect artifacts
        $allResults = $run->getResults()->toArray();
        $artifacts = $this->artifactCollector->collectArtifacts($run);
        if (!empty($artifacts['screenshots'])) {
            $this->artifactCollector->associateScreenshotsWithResults($allResults, $artifacts['screenshots']);
        }

        $this->entityManager->flush();

        // Determine final status
        $this->entityManager->refresh($run);
        if (TestRun::STATUS_CANCELLED !== $run->getStatus()) {
            $this->finalizeGroupRunStatus($run, $allResults);
        }

        $this->entityManager->flush();

        $this->logger->info('Retry execution completed', [
            'runId' => $run->getId(),
            'completedTests' => $completedTests,
            'totalTests' => $totalTests,
            'status' => $run->getStatus(),
        ]);
    }

    /**
     * Check if a test failure is caused by a retryable infrastructure/WebDriver error.
     *
     * Only inspects the structured errorMessage field — never the raw output file,
     * which can contain unrelated driver-log noise and trigger false positives.
     */
    public function isRetryableFailure(TestResult $result): bool
    {
        if (!$result->isFailed() && !$result->isBroken()) {
            return false;
        }

        $errorMessage = $result->getErrorMessage();

        return null !== $errorMessage && $this->matchesRetryablePattern($errorMessage);
    }

    private function matchesRetryablePattern(string $text): bool
    {
        foreach (self::RETRYABLE_ERROR_PATTERNS as $pattern) {
            if (stripos($text, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Execute a group test run sequentially (one test at a time).
     */
    private function executeGroupRun(TestRun $run, callable $lockRefreshCallback, ?\Closure $heartbeatCallback = null): void
    {
        $groupName = $run->getTestFilter();

        $this->logger->info('Starting sequential group execution', [
            'runId' => $run->getId(),
            'groupName' => $groupName,
        ]);

        // Get shared module path (prepared during cloning phase)
        $modulePath = $this->moduleCloneService->getDefaultTargetPath();

        // Resolve group to individual tests from cloned module
        $testNames = $this->testDiscovery->resolveGroupToTests($groupName, $modulePath);

        if (empty($testNames)) {
            $this->logger->error('No tests found in group', [
                'groupName' => $groupName,
                'modulePath' => $modulePath,
            ]);
            $run->markFailed("No tests found in group: {$groupName}");
            $this->entityManager->flush();

            return;
        }

        // Count already-executed tests (for redelivery protection - skip all existing results)
        $completedTests = $run->getResults()->count();

        $suite = $run->getSuite();
        $excludedTests = $suite?->getExcludedTestsList() ?? [];
        $excludedLookup = [];
        if (!empty($excludedTests)) {
            $excludedLookup = array_fill_keys($excludedTests, true);
            $matchedExcluded = [];
            $originalCount = count($testNames);

            $testNames = array_values(array_filter(
                $testNames,
                static function (string $testName) use ($excludedLookup, &$matchedExcluded): bool {
                    if (isset($excludedLookup[$testName])) {
                        $matchedExcluded[] = $testName;

                        return false;
                    }

                    return true;
                },
            ));

            $this->logger->info('Filtered excluded tests from group', [
                'runId' => $run->getId(),
                'groupName' => $groupName,
                'configuredExcluded' => $excludedTests,
                'matchedExcluded' => $matchedExcluded,
                'unmatchedExcluded' => array_values(array_diff($excludedTests, $matchedExcluded)),
                'originalCount' => $originalCount,
                'remainingCount' => count($testNames),
            ]);
        }

        if (empty($testNames)) {
            if ($completedTests > 0) {
                $this->logger->info('All remaining tests excluded, finalizing from existing results', [
                    'runId' => $run->getId(),
                    'groupName' => $groupName,
                    'completedTests' => $completedTests,
                ]);
                $run->setCurrentTestName(null);
                $run->setProgress($completedTests, $completedTests);
                $existingResults = array_values(array_filter(
                    $run->getResults()->toArray(),
                    fn (TestResult $result): bool => !$this->isResultExcluded($result, $excludedLookup),
                ));
                if (empty($existingResults)) {
                    $run->markFailed('All tests in group excluded by suite configuration');
                    $this->entityManager->flush();

                    return;
                }
                $failedCount = $this->finalizeGroupRunStatus($run, $existingResults);
                $this->entityManager->flush();

                $this->logger->info('Sequential group execution completed', [
                    'runId' => $run->getId(),
                    'completedTests' => $completedTests,
                    'totalTests' => $completedTests,
                    'failedCount' => $failedCount,
                ]);

                return;
            }

            $run->markFailed('All tests in group excluded by suite configuration');
            $this->entityManager->flush();

            return;
        }

        $totalTests = count($testNames);

        $this->logger->info('Resolved group tests', [
            'groupName' => $groupName,
            'totalTests' => $totalTests,
            'tests' => $testNames,
        ]);

        if ($completedTests > 0) {
            $this->logger->info('Resuming group execution after restart', [
                'runId' => $run->getId(),
                'alreadyCompleted' => $completedTests,
                'totalTests' => $totalTests,
            ]);
        }

        // Get max retry count for inline retry of infrastructure failures
        $maxRetries = $this->settingsRepository->getSettings()->getMaxRetryCount();

        // Set initial progress (may be non-zero on worker restart)
        $run->setProgress($completedTests, $totalTests);
        $run->setStatus(TestRun::STATUS_RUNNING);
        $this->entityManager->flush();

        foreach ($testNames as $testName) {
            // Skip tests that already have ANY result (prevents duplicates on redelivery)
            $existingResult = $run->getResults()->filter(
                fn ($r) => $r->getTestName() === $testName || $r->getTestId() === $testName,
            )->first();

            if ($existingResult) {
                $this->logger->info('Skipping already-executed test (redelivery protection)', [
                    'runId' => $run->getId(),
                    'testName' => $testName,
                    'existingStatus' => $existingResult->getStatus(),
                ]);
                ++$completedTests;

                continue;
            }

            // Check for cancellation between tests
            $this->entityManager->refresh($run);
            if (TestRun::STATUS_CANCELLED === $run->getStatus()) {
                $this->logger->info('Run cancelled, stopping sequential execution', [
                    'runId' => $run->getId(),
                    'completedTests' => $completedTests,
                    'totalTests' => $totalTests,
                ]);

                break;
            }

            // Update progress - mark current test (heartbeat to prove liveness)
            $run->setCurrentTestName($testName);
            $run->setProgress($completedTests, $totalTests);
            $run->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->logger->info('Executing test in group', [
                'runId' => $run->getId(),
                'testName' => $testName,
                'progress' => ($completedTests + 1) . '/' . $totalTests,
            ]);

            $cancelled = $this->executeSingleTestWithRetry($run, $testName, $maxRetries, $lockRefreshCallback, $heartbeatCallback);

            if ($cancelled) {
                break;
            }

            ++$completedTests;
            $run->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->logger->info('Test iteration completed', [
                'runId' => $run->getId(),
                'testName' => $testName,
                'progress' => $completedTests . '/' . $totalTests,
                'memoryMB' => round(memory_get_usage(true) / 1048576, 1),
                'peakMemoryMB' => round(memory_get_peak_usage(true) / 1048576, 1),
                'entityCount' => $this->entityManager->getUnitOfWork()->size(),
                'emOpen' => $this->entityManager->isOpen(),
            ]);
        }

        // Clear current test
        $run->setCurrentTestName(null);
        $run->setProgress($completedTests, $totalTests);
        $this->entityManager->flush();

        // Collect ALL artifacts at end (batch) - screenshot names unique per test
        $this->logger->info('Collecting artifacts for group run', ['runId' => $run->getId()]);
        $allResults = array_values(array_filter(
            $run->getResults()->toArray(),
            fn (TestResult $result): bool => !$this->isResultExcluded($result, $excludedLookup),
        ));
        $artifacts = $this->artifactCollector->collectArtifacts($run);
        if (!empty($artifacts['screenshots'])) {
            $this->artifactCollector->associateScreenshotsWithResults($allResults, $artifacts['screenshots']);
        }

        $this->entityManager->flush();

        // Determine overall run status (only if not cancelled)
        $this->entityManager->refresh($run);
        if (TestRun::STATUS_CANCELLED !== $run->getStatus()) {
            $failedCount = $this->finalizeGroupRunStatus($run, $allResults);
        } else {
            $failedCount = 0; // For logging
        }

        $this->entityManager->flush();

        $this->logger->info('Sequential group execution completed', [
            'runId' => $run->getId(),
            'completedTests' => $completedTests,
            'totalTests' => $totalTests,
            'failedCount' => $failedCount,
        ]);
    }

    /**
     * Execute a single test with inline retry for retryable infrastructure failures.
     *
     * @return bool True if the run was cancelled during execution
     */
    private function executeSingleTestWithRetry(
        TestRun $run,
        string $testName,
        int $maxRetries,
        callable $lockRefreshCallback,
        ?\Closure $heartbeatCallback = null,
    ): bool {
        $maxAttempts = $maxRetries + 1; // 1 initial + N retries

        for ($attempt = 0; $attempt < $maxAttempts; ++$attempt) {
            try {
                $result = $this->mftfExecutor->executeSingleTest($run, $testName, $lockRefreshCallback, $heartbeatCallback);

                $testResults = $this->mftfExecutor->parseResults(
                    $run,
                    $result['output'],
                    $result['outputFilePath'] ?? null,
                );

                if (empty($testResults)) {
                    $this->logger->warning('No results parsed for test, creating broken result', [
                        'testName' => $testName,
                    ]);
                    $errorMessage = $this->mftfExecutor->extractErrorSummary($result['output']);
                    $testResult = new TestResult();
                    $testResult->setTestRun($run);
                    $testResult->setTestName($testName);
                    $testResult->setStatus(TestResult::STATUS_BROKEN);
                    $testResult->setErrorMessage($errorMessage);
                    $testResult->setOutputFilePath($result['outputFilePath'] ?? null);
                    $testResults = [$testResult];
                } else {
                    foreach ($testResults as $testResult) {
                        $testResult->setOutputFilePath($result['outputFilePath'] ?? null);
                    }
                }
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'cancelled')) {
                    $this->logger->info('Test run cancelled during execution', [
                        'runId' => $run->getId(),
                        'testName' => $testName,
                    ]);
                    $this->entityManager->flush();

                    return true;
                }

                $this->logger->error('Test execution crashed, continuing with next test', [
                    'runId' => $run->getId(),
                    'testName' => $testName,
                    'error' => $e->getMessage(),
                ]);

                $testResult = new TestResult();
                $testResult->setTestRun($run);
                $testResult->setTestName($testName);
                $testResult->setStatus(TestResult::STATUS_BROKEN);
                $testResult->setErrorMessage('Test crashed: ' . $e->getMessage());
                $testResults = [$testResult];
            }

            // Check if any result is a retryable failure and we have retries left
            $isLastAttempt = $attempt >= $maxAttempts - 1;
            $hasRetryableFailure = false;

            if (!$isLastAttempt) {
                foreach ($testResults as $tr) {
                    if (($tr->isFailed() || $tr->isBroken()) && $this->isRetryableFailure($tr)) {
                        $hasRetryableFailure = true;

                        break;
                    }
                }
            }

            if ($hasRetryableFailure) {
                // Preserve the failed attempt's log before it gets overwritten
                $failedOutputPath = $testResults[0]->getOutputFilePath();
                if ($failedOutputPath && file_exists($failedOutputPath)) {
                    $attemptPath = preg_replace('/\.log$/', '.attempt-' . ($attempt + 1) . '.log', $failedOutputPath);
                    rename($failedOutputPath, $attemptPath);
                }

                $this->logger->info('Retrying test inline due to infrastructure failure', [
                    'runId' => $run->getId(),
                    'testName' => $testName,
                    'attempt' => $attempt + 1,
                    'maxRetries' => $maxRetries,
                    'errorMessage' => $testResults[0]->getErrorMessage(),
                ]);

                // Don't persist the failed results — retry on next iteration
                continue;
            }

            // No retry needed (passed, non-retryable failure, or retries exhausted)
            // Persist the final results
            foreach ($testResults as $tr) {
                $run->addResult($tr);
                $this->entityManager->persist($tr);
            }

            if ($attempt > 0) {
                $this->logger->info('Test retry completed', [
                    'runId' => $run->getId(),
                    'testName' => $testName,
                    'totalAttempts' => $attempt + 1,
                    'finalStatus' => $testResults[0]->getStatus(),
                ]);
            }

            break;
        }

        // Copy Allure results + generate report
        try {
            $this->allureReportService->copyTestAllureResults($run->getId(), $testName);
            $this->allureReportService->generateIncrementalReport($run);
        } catch (\Exception $e) {
            $this->logger->warning('Allure result processing failed, continuing', [
                'runId' => $run->getId(),
                'testName' => $testName,
                'error' => $e->getMessage(),
            ]);
        }

        // Collect screenshot immediately so it's visible in UI during execution
        $latestResults = array_filter($run->getResults()->toArray(), fn ($r) => $r->getTestName() === $testName || $r->getTestId() === $testName);
        foreach ($latestResults as $latestResult) {
            $this->artifactCollector->collectTestScreenshot($run, $latestResult);

            if (null === $latestResult->getDuration()) {
                $allureDuration = $this->allureStepParser->getDurationForResult($latestResult);
                if (null !== $allureDuration) {
                    $latestResult->setDuration($allureDuration);
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, TestResult> $results
     */
    private function finalizeGroupRunStatus(TestRun $run, array $results): int
    {
        $totalCount = count($results);
        $brokenCount = count(array_filter($results, fn ($r) => $r->isBroken()));
        $passedCount = count(array_filter($results, fn ($r) => $r->isPassed()));
        $failedCount = count(array_filter($results, fn ($r) => $r->isFailed() || $r->isBroken()));

        // FAILED only if: no results, all broken, or all failed (0 passed)
        if (0 === $totalCount) {
            $run->markFailed('No test results collected');
        } elseif ($brokenCount === $totalCount) {
            $run->markFailed('All tests broken - possible infrastructure error');
        } elseif (0 === $passedCount && $failedCount > 0) {
            $run->markFailed(sprintf('All %d test(s) failed', $failedCount));
        } else {
            // Some tests passed - mark completed (even with failures)
            $run->markCompleted();
        }

        return $failedCount;
    }

    /**
     * @param array<string, bool> $excludedLookup
     */
    private function isResultExcluded(TestResult $result, array $excludedLookup): bool
    {
        if (empty($excludedLookup)) {
            return false;
        }

        $testName = $result->getTestName();
        if (isset($excludedLookup[$testName])) {
            return true;
        }

        $testId = $result->getTestId();

        return null !== $testId && isset($excludedLookup[$testId]);
    }

    /**
     * Remove noisy JS console warnings from stored output while keeping raw logs intact.
     */
    private function sanitizeMftfOutput(string $output): string
    {
        if ('' === $output) {
            return $output;
        }

        $lines = preg_split("/\r\n|\n|\r/", $output);
        if (false === $lines) {
            return $output;
        }

        $filtered = [];
        foreach ($lines as $line) {
            if (preg_match('/^javascript ERROR\\(WARNING\\).*preloaded using link preload but not used/i', $line)) {
                continue;
            }
            $filtered[] = $line;
        }

        return implode("\n", $filtered);
    }
}
