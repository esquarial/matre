<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\TestEnvironment;
use App\Entity\TestReport;
use App\Entity\TestResult;
use App\Entity\TestRun;
use App\Entity\TestSuite;
use App\Entity\User;
use App\Repository\SettingsRepository;
use App\Repository\TestRunRepository;
use App\Service\AllureReportService;
use App\Service\AllureStepParserService;
use App\Service\ArtifactCollectorService;
use App\Service\MftfExecutorService;
use App\Service\ModuleCloneService;
use App\Service\PlaywrightExecutorService;
use App\Service\TestDiscoveryService;
use App\Service\TestRunnerService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * Unit tests for TestRunnerService.
 *
 * Tests the 5-phase orchestration pipeline: create, prepare, execute, report, cleanup.
 */
class TestRunnerServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    private TestRunRepository $testRunRepository;

    private SettingsRepository $settingsRepository;

    private ModuleCloneService $moduleCloneService;

    private MftfExecutorService $mftfExecutor;

    private PlaywrightExecutorService $playwrightExecutor;

    private AllureReportService $allureReportService;

    private ArtifactCollectorService $artifactCollector;

    private AllureStepParserService $allureStepParser;

    private LoggerInterface $logger;

    private TestDiscoveryService $testDiscovery;

    private LockFactory $lockFactory;

    private SharedLockInterface $lock;

    private TestRunnerService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->testRunRepository = $this->createStub(TestRunRepository::class);
        $this->settingsRepository = $this->createStub(SettingsRepository::class);
        $this->moduleCloneService = $this->createStub(ModuleCloneService::class);
        $this->mftfExecutor = $this->createStub(MftfExecutorService::class);
        $this->playwrightExecutor = $this->createStub(PlaywrightExecutorService::class);
        $this->allureReportService = $this->createStub(AllureReportService::class);
        $this->artifactCollector = $this->createStub(ArtifactCollectorService::class);
        $this->allureStepParser = $this->createStub(AllureStepParserService::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->testDiscovery = $this->createStub(TestDiscoveryService::class);
        $this->lockFactory = $this->createStub(LockFactory::class);
        $this->lock = $this->createStub(SharedLockInterface::class);

        $this->rebuildService();
    }

    // =====================
    // createRun() Tests
    // =====================

    public function testCreateRunCreatesNewTestRun(): void
    {
        $env = $this->createTestEnvironment();
        $em = $this->mockEntityManager();

        $em->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(TestRun::class));

        $em->expects($this->once())
            ->method('flush');

        $result = $this->service->createRun($env, TestRun::TYPE_MFTF);

        $this->assertInstanceOf(TestRun::class, $result);
        $this->assertSame($env, $result->getEnvironment());
        $this->assertEquals(TestRun::TYPE_MFTF, $result->getType());
        $this->assertEquals(TestRun::STATUS_PENDING, $result->getStatus());
        $this->assertEquals(TestRun::TRIGGER_MANUAL, $result->getTriggeredBy());
    }

    public function testCreateRunWithTestFilter(): void
    {
        $env = $this->createTestEnvironment();
        $em = $this->mockEntityManager();

        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $result = $this->service->createRun($env, TestRun::TYPE_MFTF, 'AdminLoginTest');

        $this->assertEquals('AdminLoginTest', $result->getTestFilter());
    }

    public function testCreateRunWithSuite(): void
    {
        $env = $this->createTestEnvironment();
        $suite = $this->createTestSuite('Smoke Tests');
        $em = $this->mockEntityManager();

        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $result = $this->service->createRun($env, TestRun::TYPE_MFTF, null, $suite);

        $this->assertSame($suite, $result->getSuite());
    }

    public function testCreateRunWithSchedulerTrigger(): void
    {
        $env = $this->createTestEnvironment();
        $em = $this->mockEntityManager();

        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $result = $this->service->createRun(
            $env,
            TestRun::TYPE_BOTH,
            null,
            null,
            TestRun::TRIGGER_SCHEDULER,
        );

        $this->assertEquals(TestRun::TRIGGER_SCHEDULER, $result->getTriggeredBy());
    }

    public function testCreateRunLogsCreation(): void
    {
        $env = $this->createTestEnvironment();
        $em = $this->mockEntityManager();
        $logger = $this->mockLogger();

        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $logger->expects($this->once())
            ->method('info')
            ->with('Test run created', $this->callback(function ($context) {
                return isset($context['type']) && TestRun::TYPE_MFTF === $context['type']
                    && isset($context['environment']) && 'test-env' === $context['environment'];
            }));

        $this->service->createRun($env, TestRun::TYPE_MFTF);
    }

    // =====================
    // prepareRun() Tests
    // =====================

    public function testPrepareRunPreparesModule(): void
    {
        $run = $this->createTestRun();
        $em = $this->mockEntityManager();
        $clone = $this->mockModuleCloneService();

        $clone->expects($this->once())
            ->method('prepareModule')
            ->willReturn('/var/test-modules/current');

        $em->expects($this->atLeastOnce())->method('flush');

        $this->service->prepareRun($run);

        $this->assertEquals(TestRun::STATUS_CLONING, $run->getStatus());
    }

    public function testPrepareRunTransitionsThroughStatuses(): void
    {
        $run = $this->createTestRun();
        $statusTransitions = [];
        $em = $this->mockEntityManager();
        $clone = $this->mockModuleCloneService();

        $em->expects($this->atLeastOnce())
            ->method('flush')
            ->willReturnCallback(function () use ($run, &$statusTransitions) {
                $statusTransitions[] = $run->getStatus();
            });

        $clone->expects($this->once())
            ->method('prepareModule')
            ->willReturn('/var/test-modules/current');

        $this->service->prepareRun($run);

        $this->assertContains(TestRun::STATUS_PREPARING, $statusTransitions);
        $this->assertContains(TestRun::STATUS_CLONING, $statusTransitions);
    }

    public function testPrepareRunMarksFailedOnException(): void
    {
        $run = $this->createTestRun();
        $em = $this->mockEntityManager();
        $clone = $this->mockModuleCloneService();

        $clone->expects($this->once())
            ->method('prepareModule')
            ->willThrowException(new \RuntimeException('Git clone failed'));

        $em->expects($this->atLeastOnce())->method('flush');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Git clone failed');

        try {
            $this->service->prepareRun($run);
        } catch (\RuntimeException $e) {
            $this->assertEquals(TestRun::STATUS_FAILED, $run->getStatus());
            $this->assertEquals('Git clone failed', $run->getErrorMessage());

            throw $e;
        }
    }

    public function testPrepareRunLogsError(): void
    {
        $run = $this->createTestRun();
        $clone = $this->mockModuleCloneService();
        $logger = $this->mockLogger();

        $clone->expects($this->once())
            ->method('prepareModule')
            ->willThrowException(new \RuntimeException('Clone failed'));

        $logger->expects($this->atLeastOnce())
            ->method($this->logicalOr($this->equalTo('info'), $this->equalTo('error')));

        $this->expectException(\RuntimeException::class);
        $this->service->prepareRun($run);
    }

    // =====================
    // executeRun() Tests - MFTF Only
    // =====================

    public function testExecuteRunMftfOnlySuccess(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_MFTF);
        $mftf = $this->mockMftfExecutor();
        $pw = $this->mockPlaywrightExecutor();
        $artifacts = $this->mockArtifactCollector();
        $em = $this->mockEntityManager();
        $this->setupLockMock();

        $mftf->expects($this->once())
            ->method('getOutputFilePath')
            ->with($run)
            ->willReturn('/var/test-output/run-1.txt');

        $mftf->expects($this->once())
            ->method('execute')
            ->with($run, $this->isCallable())
            ->willReturn(['output' => 'MFTF test output', 'exitCode' => 0]);

        $mftf->expects($this->once())
            ->method('parseResults')
            ->with($run, 'MFTF test output')
            ->willReturn([]);

        $mftf->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure-results');

        $pw->expects($this->never())->method('execute');

        $artifacts->expects($this->once())
            ->method('collectArtifacts')
            ->with($run)
            ->willReturn(['screenshots' => [], 'html' => []]);

        $em->expects($this->atLeast(2))->method('flush');

        $this->service->executeRun($run);

        // No results returned = FAILED (no test results collected)
        $this->assertEquals(TestRun::STATUS_FAILED, $run->getStatus());
        $this->assertStringContainsString('No test results collected', $run->getErrorMessage());
        $this->assertStringContainsString('MFTF test output', $run->getOutput());
        $this->assertEquals('/var/test-output/run-1.txt', $run->getOutputFilePath());
    }

    public function testExecuteRunMftfWithResults(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_MFTF);
        $mftf = $this->mockMftfExecutor();
        $artifacts = $this->mockArtifactCollector();
        $em = $this->mockEntityManager();
        $this->setupLockMock();

        $result1 = new TestResult();
        $result1->setTestName('Test1');
        $result1->setStatus(TestResult::STATUS_PASSED);

        $result2 = new TestResult();
        $result2->setTestName('Test2');
        $result2->setStatus(TestResult::STATUS_PASSED);

        $mftf->expects($this->once())
            ->method('getOutputFilePath')
            ->willReturn('/var/output.txt');

        $mftf->expects($this->once())
            ->method('execute')
            ->willReturn(['output' => 'test output', 'exitCode' => 0]);

        $mftf->expects($this->once())
            ->method('parseResults')
            ->willReturn([$result1, $result2]);

        $mftf->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure');

        $em->expects($this->exactly(2))
            ->method('persist')
            ->with($this->isInstanceOf(TestResult::class));

        $artifacts->expects($this->once())
            ->method('collectArtifacts')
            ->willReturn(['screenshots' => [], 'html' => []]);

        $this->service->executeRun($run);

        $this->assertCount(2, $run->getResults());
    }

    public function testExecuteRunMftfWithFailedTests(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_MFTF);
        $mftf = $this->mockMftfExecutor();
        $artifacts = $this->mockArtifactCollector();
        $this->setupLockMock();

        $passedResult = new TestResult();
        $passedResult->setTestName('PassedTest');
        $passedResult->setStatus(TestResult::STATUS_PASSED);

        $failedResult = new TestResult();
        $failedResult->setTestName('FailedTest');
        $failedResult->setStatus(TestResult::STATUS_FAILED);

        $mftf->expects($this->once())
            ->method('getOutputFilePath')
            ->willReturn('/var/output.txt');

        $mftf->expects($this->once())
            ->method('execute')
            ->willReturn(['output' => 'test output', 'exitCode' => 1]);

        $mftf->expects($this->once())
            ->method('parseResults')
            ->willReturn([$passedResult, $failedResult]);

        $mftf->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure');

        $artifacts->expects($this->once())
            ->method('collectArtifacts')
            ->willReturn(['screenshots' => [], 'html' => []]);

        $this->service->executeRun($run);

        // 1 passed + 1 failed = COMPLETED (some tests passed)
        $this->assertEquals(TestRun::STATUS_COMPLETED, $run->getStatus());
    }

    public function testExecuteRunMftfGenerationFailure(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_MFTF);
        $mftf = $this->mockMftfExecutor();
        $artifacts = $this->mockArtifactCollector();
        $this->setupLockMock();

        $mftf->expects($this->once())
            ->method('getOutputFilePath')
            ->willReturn('/var/output.txt');

        $mftf->expects($this->once())
            ->method('execute')
            ->willReturn([
                'output' => 'ERROR: 2 Test(s) failed to generate',
                'exitCode' => 1,
            ]);

        $mftf->expects($this->once())
            ->method('parseResults')
            ->willReturn([]);

        $mftf->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure');

        $artifacts->expects($this->once())
            ->method('collectArtifacts')
            ->willReturn(['screenshots' => [], 'html' => []]);

        $this->service->executeRun($run);

        $this->assertEquals(TestRun::STATUS_FAILED, $run->getStatus());
        $this->assertEquals('MFTF test generation failed - see output log', $run->getErrorMessage());
    }

    public function testExecuteRunMftfModuleNotFound(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_MFTF);
        $mftf = $this->mockMftfExecutor();
        $artifacts = $this->mockArtifactCollector();
        $this->setupLockMock();

        $mftf->expects($this->once())
            ->method('getOutputFilePath')
            ->willReturn('/var/output.txt');

        $mftf->expects($this->once())
            ->method('execute')
            ->willReturn([
                'output' => 'Module_Something is not available under Magento/FunctionalTest',
                'exitCode' => 1,
            ]);

        $mftf->expects($this->once())
            ->method('parseResults')
            ->willReturn([]);

        $mftf->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure');

        $artifacts->expects($this->once())
            ->method('collectArtifacts')
            ->willReturn(['screenshots' => [], 'html' => []]);

        $this->service->executeRun($run);

        $this->assertEquals(TestRun::STATUS_FAILED, $run->getStatus());
        $this->assertEquals('Generated test file not found - see output log', $run->getErrorMessage());
    }

    public function testExecuteRunGroupFailsWhenAllTestsExcluded(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_MFTF);
        $run->setTestFilter('us');

        $suite = $this->createTestSuite('US Group Suite');
        $suite->setType(TestSuite::TYPE_MFTF_GROUP);
        $suite->setExcludedTests('MOEC11676, MOEC2609ES');
        $run->setSuite($suite);

        $this->setupLockMock();
        $em = $this->mockEntityManager();
        $mftf = $this->mockMftfExecutor();
        $clone = $this->mockModuleCloneService();
        $discovery = $this->mockTestDiscovery();
        $artifacts = $this->mockArtifactCollector();

        $mftf->expects($this->once())
            ->method('getOutputFilePath')
            ->willReturn('/var/output.txt');

        $clone->expects($this->once())
            ->method('getDefaultTargetPath')
            ->willReturn('/var/test-modules/current');

        $discovery->expects($this->once())
            ->method('resolveGroupToTests')
            ->with('us', '/var/test-modules/current')
            ->willReturn(['MOEC11676', 'MOEC2609ES']);

        $mftf->expects($this->never())->method('executeSingleTest');
        $artifacts->expects($this->never())->method('collectArtifacts');
        $em->expects($this->atLeast(3))->method('flush');

        $this->service->executeRun($run);

        $this->assertEquals(TestRun::STATUS_FAILED, $run->getStatus());
        $this->assertEquals('All tests in group excluded by suite configuration', $run->getErrorMessage());
    }

    public function testExecuteRunGroupExecutesOnlyNonExcludedTests(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_MFTF);
        $run->setTestFilter('us');

        $suite = $this->createTestSuite('US Group Suite');
        $suite->setType(TestSuite::TYPE_MFTF_GROUP);
        $suite->setExcludedTests('MOEC11676');
        $run->setSuite($suite);

        $this->setupLockMock();
        $em = $this->mockEntityManager();
        $mftf = $this->mockMftfExecutor();
        $clone = $this->mockModuleCloneService();
        $discovery = $this->mockTestDiscovery();
        $allure = $this->mockAllureReportService();
        $artifacts = $this->mockArtifactCollector();
        $stepParser = $this->mockAllureStepParser();

        $mftf->expects($this->once())
            ->method('getOutputFilePath')
            ->willReturn('/var/output.txt');

        $clone->expects($this->once())
            ->method('getDefaultTargetPath')
            ->willReturn('/var/test-modules/current');

        $discovery->expects($this->once())
            ->method('resolveGroupToTests')
            ->with('us', '/var/test-modules/current')
            ->willReturn(['MOEC11676', 'MOEC2609ES']);

        $mftf->expects($this->once())
            ->method('executeSingleTest')
            ->with(
                $run,
                'MOEC2609ES',
                $this->callback(static fn (mixed $arg): bool => is_callable($arg)),
                $this->callback(static fn (mixed $arg): bool => is_callable($arg)),
            )
            ->willReturn([
                'output' => 'single test output',
                'exitCode' => 0,
                'outputFilePath' => '/var/test-output/run-1/MOEC2609ES.log',
            ]);

        $parsedResult = new TestResult();
        $parsedResult->setTestName('MOEC2609ES');
        $parsedResult->setStatus(TestResult::STATUS_PASSED);

        $mftf->expects($this->once())
            ->method('parseResults')
            ->with($run, 'single test output', '/var/test-output/run-1/MOEC2609ES.log')
            ->willReturn([$parsedResult]);

        $em->expects($this->once())
            ->method('persist')
            ->with($parsedResult);

        $allure->expects($this->once())
            ->method('copyTestAllureResults')
            ->with(1, 'MOEC2609ES');

        $allure->expects($this->once())
            ->method('generateIncrementalReport')
            ->with($run);

        $artifacts->expects($this->once())
            ->method('collectTestScreenshot')
            ->with($run, $parsedResult);

        $stepParser->expects($this->once())
            ->method('getDurationForResult')
            ->with($parsedResult)
            ->willReturn(null);

        $artifacts->expects($this->once())
            ->method('collectArtifacts')
            ->with($run)
            ->willReturn(['screenshots' => [], 'html' => []]);

        $em->expects($this->atLeast(4))->method('flush');

        $this->service->executeRun($run);

        $this->assertEquals(TestRun::STATUS_COMPLETED, $run->getStatus());
        $this->assertCount(1, $run->getResults());
    }

    public function testExecuteRunGroupPreservesInfrastructureErrorWhenParsingFails(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_MFTF);
        $run->setTestFilter('us');

        $suite = $this->createTestSuite('US Group Suite');
        $suite->setType(TestSuite::TYPE_MFTF_GROUP);
        $run->setSuite($suite);

        $this->setupLockMock();
        $em = $this->mockEntityManager();
        $mftf = $this->mockMftfExecutor();
        $clone = $this->mockModuleCloneService();
        $discovery = $this->mockTestDiscovery();
        $allure = $this->mockAllureReportService();
        $artifacts = $this->mockArtifactCollector();

        $rawOutput = <<<'OUTPUT'
            ERROR: Magento vendor/autoload.php not found at /var/www/html/vendor/autoload.php
            Missing module environment file: /var/www/html/app/code/TestModule/Cron/data/.env.preprod-es
            OUTPUT;

        $mftf->expects($this->once())
            ->method('getOutputFilePath')
            ->willReturn('/var/output.txt');

        $clone->expects($this->once())
            ->method('getDefaultTargetPath')
            ->willReturn('/var/test-modules/current');

        $discovery->expects($this->once())
            ->method('resolveGroupToTests')
            ->with('us', '/var/test-modules/current')
            ->willReturn(['MOEC13338']);

        $mftf->expects($this->once())
            ->method('executeSingleTest')
            ->willReturn([
                'output' => $rawOutput,
                'exitCode' => 1,
                'outputFilePath' => '/var/test-output/run-1/MOEC13338.log',
            ]);

        $mftf->expects($this->once())
            ->method('parseResults')
            ->with($run, $rawOutput, '/var/test-output/run-1/MOEC13338.log')
            ->willReturn([]);

        $mftf->expects($this->once())
            ->method('extractErrorSummary')
            ->with($rawOutput)
            ->willReturn("ERROR: Magento vendor/autoload.php not found at /var/www/html/vendor/autoload.php\nMissing module environment file: /var/www/html/app/code/TestModule/Cron/data/.env.preprod-es");

        $allure->expects($this->once())
            ->method('copyTestAllureResults')
            ->with(1, 'MOEC13338');

        $allure->expects($this->once())
            ->method('generateIncrementalReport')
            ->with($run);

        $artifacts->expects($this->once())
            ->method('collectArtifacts')
            ->with($run)
            ->willReturn(['screenshots' => [], 'html' => []]);

        $em->expects($this->atLeast(4))->method('flush');

        $this->service->executeRun($run);

        $this->assertCount(1, $run->getResults());
        $result = $run->getResults()->first();
        $this->assertInstanceOf(TestResult::class, $result);
        $this->assertSame('MOEC13338', $result->getTestName());
        $this->assertSame(TestResult::STATUS_BROKEN, $result->getStatus());
        $this->assertStringContainsString('Magento vendor/autoload.php not found', (string) $result->getErrorMessage());
        $this->assertStringContainsString('Missing module environment file', (string) $result->getErrorMessage());
    }

    // =====================
    // executeRun() Tests - Playwright Only
    // =====================

    public function testExecuteRunPlaywrightOnlySuccess(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_PLAYWRIGHT);
        $mftf = $this->mockMftfExecutor();
        $pw = $this->mockPlaywrightExecutor();
        $artifacts = $this->mockArtifactCollector();
        $this->setupLockMock();

        $pw->expects($this->once())
            ->method('getOutputFilePath')
            ->with($run)
            ->willReturn('/var/playwright-output.txt');

        $pw->expects($this->once())
            ->method('execute')
            ->with($run)
            ->willReturn(['output' => 'Playwright test output', 'exitCode' => 0]);

        $pw->expects($this->once())
            ->method('parseResults')
            ->willReturn([]);

        $pw->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure-pw');

        $mftf->expects($this->never())->method('execute');

        $artifacts->expects($this->once())
            ->method('collectArtifacts')
            ->willReturn(['screenshots' => [], 'html' => []]);

        $this->service->executeRun($run);

        $this->assertStringContainsString('Playwright test output', $run->getOutput());
        $this->assertEquals('/var/playwright-output.txt', $run->getOutputFilePath());
    }

    public function testExecuteRunPlaywrightWithFailedTests(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_PLAYWRIGHT);
        $pw = $this->mockPlaywrightExecutor();
        $artifacts = $this->mockArtifactCollector();
        $this->setupLockMock();

        $failedResult = new TestResult();
        $failedResult->setTestName('FailedPWTest');
        $failedResult->setStatus(TestResult::STATUS_FAILED);

        $pw->expects($this->once())
            ->method('getOutputFilePath')
            ->willReturn('/var/output.txt');

        $pw->expects($this->once())
            ->method('execute')
            ->willReturn(['output' => 'PW output', 'exitCode' => 1]);

        $pw->expects($this->once())
            ->method('parseResults')
            ->willReturn([$failedResult]);

        $pw->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure');

        $artifacts->expects($this->once())
            ->method('collectArtifacts')
            ->willReturn(['screenshots' => [], 'html' => []]);

        $this->service->executeRun($run);

        $this->assertEquals(TestRun::STATUS_FAILED, $run->getStatus());
        $this->assertStringContainsString('1 test(s) failed', $run->getErrorMessage());
    }

    // =====================
    // executeRun() Tests - Both Types
    // =====================

    public function testExecuteRunBothTypesSuccess(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_BOTH);
        $mftf = $this->mockMftfExecutor();
        $pw = $this->mockPlaywrightExecutor();
        $artifacts = $this->mockArtifactCollector();
        $this->setupLockMock();

        $mftf->expects($this->once())
            ->method('getOutputFilePath')
            ->willReturn('/var/mftf-output.txt');

        $mftf->expects($this->once())
            ->method('execute')
            ->willReturn(['output' => 'MFTF output', 'exitCode' => 0]);

        $mftf->expects($this->once())
            ->method('parseResults')
            ->willReturn([]);

        $mftf->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure-mftf');

        $pw->expects($this->once())
            ->method('execute')
            ->willReturn(['output' => 'Playwright output', 'exitCode' => 0]);

        $pw->expects($this->once())
            ->method('parseResults')
            ->willReturn([]);

        $pw->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure-pw');

        $artifacts->expects($this->once())
            ->method('collectArtifacts')
            ->willReturn(['screenshots' => [], 'html' => []]);

        $this->service->executeRun($run);

        $this->assertStringContainsString('=== MFTF Output ===', $run->getOutput());
        $this->assertStringContainsString('=== Playwright Output ===', $run->getOutput());
    }

    public function testExecuteRunBothTypesMftfFailsPlaywrightSucceeds(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_BOTH);
        $mftf = $this->mockMftfExecutor();
        $pw = $this->mockPlaywrightExecutor();
        $artifacts = $this->mockArtifactCollector();
        $this->setupLockMock();

        $mftfFailed = new TestResult();
        $mftfFailed->setTestName('MftfFailed');
        $mftfFailed->setStatus(TestResult::STATUS_FAILED);

        $mftf->expects($this->once())
            ->method('getOutputFilePath')
            ->willReturn('/var/output.txt');

        $mftf->expects($this->once())
            ->method('execute')
            ->willReturn(['output' => 'MFTF output', 'exitCode' => 1]);

        $mftf->expects($this->once())
            ->method('parseResults')
            ->willReturn([$mftfFailed]);

        $mftf->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure');

        $pwPassed = new TestResult();
        $pwPassed->setTestName('PwPassed');
        $pwPassed->setStatus(TestResult::STATUS_PASSED);

        $pw->expects($this->once())
            ->method('execute')
            ->willReturn(['output' => 'PW output', 'exitCode' => 0]);

        $pw->expects($this->once())
            ->method('parseResults')
            ->willReturn([$pwPassed]);

        $pw->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure');

        $artifacts->expects($this->once())
            ->method('collectArtifacts')
            ->willReturn(['screenshots' => [], 'html' => []]);

        $this->service->executeRun($run);

        // 1 failed (MFTF) + 1 passed (PW) = COMPLETED (some tests passed)
        $this->assertEquals(TestRun::STATUS_COMPLETED, $run->getStatus());
    }

    public function testExecuteRunBothTypesBothFail(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_BOTH);
        $mftf = $this->mockMftfExecutor();
        $pw = $this->mockPlaywrightExecutor();
        $artifacts = $this->mockArtifactCollector();
        $this->setupLockMock();

        $mftfFailed = new TestResult();
        $mftfFailed->setTestName('MftfFailed');
        $mftfFailed->setStatus(TestResult::STATUS_FAILED);

        $mftf->expects($this->once())
            ->method('getOutputFilePath')
            ->willReturn('/var/output.txt');

        $mftf->expects($this->once())
            ->method('execute')
            ->willReturn(['output' => 'MFTF output', 'exitCode' => 1]);

        $mftf->expects($this->once())
            ->method('parseResults')
            ->willReturn([$mftfFailed]);

        $mftf->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure');

        $pwFailed = new TestResult();
        $pwFailed->setTestName('PwFailed');
        $pwFailed->setStatus(TestResult::STATUS_FAILED);

        $pw->expects($this->once())
            ->method('execute')
            ->willReturn(['output' => 'PW output', 'exitCode' => 1]);

        $pw->expects($this->once())
            ->method('parseResults')
            ->willReturn([$pwFailed]);

        $pw->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure');

        $artifacts->expects($this->once())
            ->method('collectArtifacts')
            ->willReturn(['screenshots' => [], 'html' => []]);

        $this->service->executeRun($run);

        // 2 failed tests, 0 passed = FAILED (all tests failed)
        $this->assertEquals(TestRun::STATUS_FAILED, $run->getStatus());
        $this->assertStringContainsString('All 2 test(s) failed', $run->getErrorMessage());
    }

    // =====================
    // executeRun() Tests - Lock Management
    // =====================

    public function testExecuteRunAcquiresLockWithCorrectKey(): void
    {
        $env = $this->createTestEnvironment(42, 'test-env');
        $run = $this->createTestRun(TestRun::TYPE_MFTF, TestRun::STATUS_PENDING, $env);
        $mftf = $this->mockMftfExecutor();
        $artifacts = $this->mockArtifactCollector();

        $lockFactory = $this->createMock(LockFactory::class);
        $lock = $this->createMock(SharedLockInterface::class);
        $this->lockFactory = $lockFactory;
        $this->lock = $lock;
        $this->rebuildService();

        $lockFactory->expects($this->once())
            ->method('createLock')
            ->with('mftf_execution_env_42', 1800)
            ->willReturn($lock);

        $lock->expects($this->once())
            ->method('acquire')
            ->with(true);

        $lock->expects($this->once())
            ->method('release');

        $mftf->expects($this->once())
            ->method('getOutputFilePath')
            ->willReturn('/var/output.txt');

        $mftf->expects($this->once())
            ->method('execute')
            ->willReturn(['output' => 'test', 'exitCode' => 0]);

        $mftf->expects($this->once())
            ->method('parseResults')
            ->willReturn([]);

        $mftf->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure');

        $artifacts->expects($this->once())
            ->method('collectArtifacts')
            ->willReturn(['screenshots' => [], 'html' => []]);

        $this->service->executeRun($run);
    }

    public function testExecuteRunReleasesLockOnException(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_MFTF);
        $mftf = $this->mockMftfExecutor();

        $lockFactory = $this->createMock(LockFactory::class);
        $lock = $this->createMock(SharedLockInterface::class);
        $this->lockFactory = $lockFactory;
        $this->lock = $lock;
        $this->rebuildService();

        $lockFactory->expects($this->once())
            ->method('createLock')
            ->willReturn($lock);

        $lock->expects($this->once())->method('acquire');
        $lock->expects($this->once())->method('release');

        $mftf->expects($this->once())
            ->method('getOutputFilePath')
            ->willReturn('/var/output.txt');

        $mftf->expects($this->once())
            ->method('execute')
            ->willThrowException(new \RuntimeException('Execution failed'));

        $this->expectException(\RuntimeException::class);
        $this->service->executeRun($run);
    }

    // =====================
    // executeRun() Tests - Output Callback
    // =====================

    public function testExecuteRunCreatesLockRefreshCallback(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_MFTF);
        $mftf = $this->mockMftfExecutor();
        $artifacts = $this->mockArtifactCollector();
        $this->setupLockMock();

        $mftf->expects($this->once())
            ->method('getOutputFilePath')
            ->willReturn('/var/output.txt');

        // Verify that callables (lock refresh + heartbeat callback) are passed to the executor
        $mftf->expects($this->once())
            ->method('execute')
            ->with($run, $this->isCallable(), $this->isCallable())
            ->willReturn(['output' => 'test', 'exitCode' => 0]);

        $mftf->expects($this->once())
            ->method('parseResults')
            ->willReturn([]);

        $mftf->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure');

        $artifacts->expects($this->once())
            ->method('collectArtifacts')
            ->willReturn(['screenshots' => [], 'html' => []]);

        $this->service->executeRun($run);
    }

    // =====================
    // executeRun() Tests - Artifact Collection
    // =====================

    public function testExecuteRunCollectsArtifactsEvenOnFailure(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_MFTF);
        $mftf = $this->mockMftfExecutor();
        $artifacts = $this->mockArtifactCollector();
        $this->setupLockMock();

        $failedResult = new TestResult();
        $failedResult->setTestName('FailedTest');
        $failedResult->setStatus(TestResult::STATUS_FAILED);

        $mftf->expects($this->once())
            ->method('getOutputFilePath')
            ->willReturn('/var/output.txt');

        $mftf->expects($this->once())
            ->method('execute')
            ->willReturn(['output' => 'failed', 'exitCode' => 1]);

        $mftf->expects($this->once())
            ->method('parseResults')
            ->willReturn([$failedResult]);

        $mftf->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure');

        $artifacts->expects($this->once())
            ->method('collectArtifacts')
            ->with($run)
            ->willReturn([
                'screenshots' => ['/var/screenshots/1.png'],
                'html' => ['/var/html/1.html'],
            ]);

        $artifacts->expects($this->once())
            ->method('associateScreenshotsWithResults')
            ->with([$failedResult], ['/var/screenshots/1.png']);

        $this->service->executeRun($run);

        $this->assertEquals(TestRun::STATUS_FAILED, $run->getStatus());
    }

    public function testExecuteRunExceptionMarksFailedWithMessage(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_MFTF);
        $mftf = $this->mockMftfExecutor();
        $this->setupLockMock();

        $mftf->expects($this->once())
            ->method('getOutputFilePath')
            ->willReturn('/var/output.txt');

        $mftf->expects($this->once())
            ->method('execute')
            ->willThrowException(new \RuntimeException('Docker connection failed'));

        $this->expectException(\RuntimeException::class);

        try {
            $this->service->executeRun($run);
        } catch (\RuntimeException $e) {
            $this->assertEquals(TestRun::STATUS_FAILED, $run->getStatus());
            $this->assertStringContainsString('Docker connection failed', $run->getOutput());
            $this->assertEquals('Docker connection failed', $run->getErrorMessage());

            throw $e;
        }
    }

    // =====================
    // generateReports() Tests
    // =====================

    public function testGenerateReportsCreatesReport(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_MFTF, TestRun::STATUS_RUNNING);
        $mftf = $this->mockMftfExecutor();
        $allure = $this->mockAllureReportService();
        $em = $this->mockEntityManager();

        $report = new TestReport();
        $report->setTestRun($run);
        $report->setReportType(TestReport::TYPE_ALLURE);
        $report->setFilePath('/var/reports/run-1');
        $report->setPublicUrl('https://example.com/reports/run-1');

        $mftf->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure-results');

        $allure->expects($this->once())
            ->method('generateReport')
            ->with($run, ['/var/allure-results'])
            ->willReturn($report);

        $em->expects($this->once())
            ->method('persist')
            ->with($report);

        $em->expects($this->exactly(2))->method('flush');

        $result = $this->service->generateReports($run);

        $this->assertSame($report, $result);
        $this->assertEquals(TestRun::STATUS_COMPLETED, $run->getStatus());
    }

    public function testGenerateReportsSetsStatusReporting(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_MFTF, TestRun::STATUS_RUNNING);
        $statusDuringGeneration = null;
        $mftf = $this->mockMftfExecutor();
        $allure = $this->mockAllureReportService();

        $mftf->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure');

        $allure->expects($this->once())
            ->method('generateReport')
            ->willReturnCallback(function () use ($run, &$statusDuringGeneration) {
                $statusDuringGeneration = $run->getStatus();

                $report = new TestReport();
                $report->setTestRun($run);
                $report->setReportType(TestReport::TYPE_ALLURE);

                return $report;
            });

        $this->service->generateReports($run);

        $this->assertEquals(TestRun::STATUS_REPORTING, $statusDuringGeneration);
    }

    public function testGenerateReportsCreatesPlaceholderOnAllureFailure(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_MFTF, TestRun::STATUS_RUNNING);
        $mftf = $this->mockMftfExecutor();
        $allure = $this->mockAllureReportService();
        $em = $this->mockEntityManager();
        $logger = $this->mockLogger();

        $mftf->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure');

        $allure->expects($this->once())
            ->method('generateReport')
            ->willThrowException(new \RuntimeException('Allure service unavailable'));

        $em->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (TestReport $report) {
                return '' === $report->getFilePath()
                    && '' === $report->getPublicUrl()
                    && TestReport::TYPE_ALLURE === $report->getReportType();
            }));

        $logger->expects($this->once())
            ->method('warning')
            ->with('Allure report generation failed, creating placeholder', $this->anything());

        $result = $this->service->generateReports($run);

        $this->assertEquals('', $result->getFilePath());
        $this->assertEquals(TestRun::STATUS_COMPLETED, $run->getStatus());
    }

    public function testGenerateReportsBothTypesCollectsAllPaths(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_BOTH, TestRun::STATUS_RUNNING);
        $mftf = $this->mockMftfExecutor();
        $pw = $this->mockPlaywrightExecutor();
        $allure = $this->mockAllureReportService();

        $mftf->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure-mftf');

        $pw->expects($this->once())
            ->method('getAllureResultsPath')
            ->willReturn('/var/allure-pw');

        $allure->expects($this->once())
            ->method('generateReport')
            ->with($run, ['/var/allure-mftf', '/var/allure-pw'])
            ->willReturn((new TestReport())->setTestRun($run)->setReportType(TestReport::TYPE_ALLURE));

        $this->service->generateReports($run);
    }

    public function testGenerateReportsMarksFailedOnException(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_MFTF, TestRun::STATUS_RUNNING);
        $mftf = $this->mockMftfExecutor();

        $mftf->expects($this->once())
            ->method('getAllureResultsPath')
            ->willThrowException(new \RuntimeException('Fatal error'));

        $this->expectException(\RuntimeException::class);

        try {
            $this->service->generateReports($run);
        } catch (\RuntimeException $e) {
            $this->assertEquals(TestRun::STATUS_FAILED, $run->getStatus());
            $this->assertStringContainsString('Report generation failed', $run->getErrorMessage());

            throw $e;
        }
    }

    // =====================
    // cancelRun() Tests
    // =====================

    public function testCancelRunCancelsAndCleansUp(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_MFTF, TestRun::STATUS_PENDING);
        $em = $this->mockEntityManager();
        $logger = $this->mockLogger();

        // Make it cancellable
        $reflection = new \ReflectionClass($run);
        $method = $reflection->getMethod('canBeCancelled');

        // Module is shared, no per-run cleanup expectations

        $em->expects($this->once())->method('flush');

        $logger->expects($this->once())
            ->method('info')
            ->with('Cancelling test run', $this->anything());

        $this->service->cancelRun($run);

        $this->assertEquals(TestRun::STATUS_CANCELLED, $run->getStatus());
    }

    public function testCancelRunThrowsIfNotCancellable(): void
    {
        $run = $this->createTestRun(TestRun::TYPE_MFTF, TestRun::STATUS_COMPLETED);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test run cannot be cancelled in current state');

        $this->service->cancelRun($run);
    }

    // =====================
    // retryRun() Tests
    // =====================

    public function testRetryRunCreatesNewRun(): void
    {
        $env = $this->createTestEnvironment();
        $suite = $this->createTestSuite();
        $user = $this->createTestUser();
        $em = $this->mockEntityManager();

        $originalRun = new TestRun();
        $originalRun->setEnvironment($env);
        $originalRun->setType(TestRun::TYPE_BOTH);
        $originalRun->setTestFilter('SomeTest');
        $originalRun->setSuite($suite);
        $originalRun->setTriggeredBy(TestRun::TRIGGER_SCHEDULER);

        $em->expects($this->once())->method('persist');
        $em->expects($this->exactly(2))->method('flush'); // createRun + lineage tracking

        $newRun = $this->service->retryRun($originalRun, $user);

        $this->assertSame($env, $newRun->getEnvironment());
        $this->assertEquals(TestRun::TYPE_BOTH, $newRun->getType());
        $this->assertEquals('SomeTest', $newRun->getTestFilter());
        $this->assertSame($suite, $newRun->getSuite());
        $this->assertEquals(TestRun::TRIGGER_MANUAL, $newRun->getTriggeredBy());
        $this->assertSame($user, $newRun->getExecutedBy());
        $this->assertSame($originalRun, $newRun->getOriginalRun());
        $this->assertEquals(1, $newRun->getRetryAttempt());
    }

    public function testCreateRunWithExecutedBy(): void
    {
        $env = $this->createTestEnvironment();
        $user = $this->createTestUser();
        $em = $this->mockEntityManager();

        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $result = $this->service->createRun(
            $env,
            TestRun::TYPE_MFTF,
            null,
            null,
            TestRun::TRIGGER_MANUAL,
            true,
            $user,
        );

        $this->assertSame($user, $result->getExecutedBy());
    }

    public function testCreateRunWithoutExecutedByDefaultsToNull(): void
    {
        $env = $this->createTestEnvironment();
        $em = $this->mockEntityManager();

        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $result = $this->service->createRun($env, TestRun::TYPE_MFTF);

        $this->assertNull($result->getExecutedBy());
    }

    // =====================
    // cleanupRun() Tests
    // =====================

    public function testCleanupRunLogsCompletion(): void
    {
        $run = $this->createTestRun();
        $logger = $this->mockLogger();

        // Module is shared, no per-run cleanup - just logging
        $logger->expects($this->once())
            ->method('info')
            ->with('Test run cleanup completed', $this->anything());

        $this->service->cleanupRun($run);
    }

    // =====================
    // hasRunningForEnvironment() Tests
    // =====================

    public function testHasRunningForEnvironmentDelegatesToRepository(): void
    {
        $env = $this->createTestEnvironment();
        $repo = $this->mockTestRunRepository();

        $repo->expects($this->once())
            ->method('hasRunningForEnvironment')
            ->with($env)
            ->willReturn(true);

        $result = $this->service->hasRunningForEnvironment($env);

        $this->assertTrue($result);
    }

    public function testHasRunningForEnvironmentReturnsFalse(): void
    {
        $env = $this->createTestEnvironment();
        $repo = $this->mockTestRunRepository();

        $repo->expects($this->once())
            ->method('hasRunningForEnvironment')
            ->with($env)
            ->willReturn(false);

        $result = $this->service->hasRunningForEnvironment($env);

        $this->assertFalse($result);
    }

    private function rebuildService(): void
    {
        $this->service = new TestRunnerService(
            $this->entityManager,
            $this->testRunRepository,
            $this->settingsRepository,
            $this->moduleCloneService,
            $this->mftfExecutor,
            $this->playwrightExecutor,
            $this->allureReportService,
            $this->artifactCollector,
            $this->allureStepParser,
            $this->logger,
            $this->testDiscovery,
            $this->lockFactory,
        );
    }

    private function mockEntityManager(): MockObject&EntityManagerInterface
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->rebuildService();

        return $this->entityManager;
    }

    private function mockTestRunRepository(): MockObject&TestRunRepository
    {
        $this->testRunRepository = $this->createMock(TestRunRepository::class);
        $this->rebuildService();

        return $this->testRunRepository;
    }

    private function mockModuleCloneService(): MockObject&ModuleCloneService
    {
        $this->moduleCloneService = $this->createMock(ModuleCloneService::class);
        $this->rebuildService();

        return $this->moduleCloneService;
    }

    private function mockMftfExecutor(): MockObject&MftfExecutorService
    {
        $this->mftfExecutor = $this->createMock(MftfExecutorService::class);
        $this->rebuildService();

        return $this->mftfExecutor;
    }

    private function mockPlaywrightExecutor(): MockObject&PlaywrightExecutorService
    {
        $this->playwrightExecutor = $this->createMock(PlaywrightExecutorService::class);
        $this->rebuildService();

        return $this->playwrightExecutor;
    }

    private function mockAllureReportService(): MockObject&AllureReportService
    {
        $this->allureReportService = $this->createMock(AllureReportService::class);
        $this->rebuildService();

        return $this->allureReportService;
    }

    private function mockLogger(): MockObject&LoggerInterface
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->rebuildService();

        return $this->logger;
    }

    private function mockArtifactCollector(): MockObject&ArtifactCollectorService
    {
        $this->artifactCollector = $this->createMock(ArtifactCollectorService::class);
        $this->rebuildService();

        return $this->artifactCollector;
    }

    private function mockTestDiscovery(): MockObject&TestDiscoveryService
    {
        $this->testDiscovery = $this->createMock(TestDiscoveryService::class);
        $this->rebuildService();

        return $this->testDiscovery;
    }

    private function mockAllureStepParser(): MockObject&AllureStepParserService
    {
        $this->allureStepParser = $this->createMock(AllureStepParserService::class);
        $this->rebuildService();

        return $this->allureStepParser;
    }

    private function createTestEnvironment(int $id = 1, string $name = 'test-env'): TestEnvironment
    {
        $env = new TestEnvironment();
        $reflection = new \ReflectionClass($env);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($env, $id);
        $env->setName($name);

        return $env;
    }

    private function createTestSuite(string $name = 'test-suite'): TestSuite
    {
        $suite = new TestSuite();
        $suite->setName($name);

        return $suite;
    }

    private function createTestUser(int $id = 1, string $username = 'testuser'): User
    {
        $user = new User();
        $reflection = new \ReflectionClass($user);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($user, $id);
        $user->setUsername($username);
        $user->setEmail($username . '@test.com');

        return $user;
    }

    private function createTestRun(
        string $type = TestRun::TYPE_MFTF,
        string $status = TestRun::STATUS_PENDING,
        ?TestEnvironment $env = null,
        int $id = 1,
    ): TestRun {
        $run = new TestRun();
        $run->setEnvironment($env ?? $this->createTestEnvironment());
        $run->setType($type);
        $run->setStatus($status);

        // Set ID via reflection (normally set by Doctrine)
        $reflection = new \ReflectionClass($run);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($run, $id);

        return $run;
    }

    private function setupLockMock(): void
    {
        $lockFactory = $this->createMock(LockFactory::class);
        $lock = $this->createMock(SharedLockInterface::class);
        $this->lockFactory = $lockFactory;
        $this->lock = $lock;
        $this->rebuildService();

        $lockFactory->expects($this->once())
            ->method('createLock')
            ->willReturn($lock);

        $lock->expects($this->once())
            ->method('acquire')
            ->with(true);

        $lock->expects($this->once())
            ->method('release');
    }
}
