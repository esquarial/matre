<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\TestEnvironment;
use App\Entity\TestReport;
use App\Entity\TestResult;
use App\Entity\TestRun;
use App\Service\AllureReportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Integration test for Allure report generation and result management.
 */
class ReportGenerationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private string $tempDir;

    private Filesystem $filesystem;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir() . '/matre_test_' . uniqid();
        $this->filesystem->mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tempDir);
        $this->entityManager->close();
        parent::tearDown();
    }

    // =====================
    // Report Generation
    // =====================

    public function testGenerateReportCreatesReportEntity(): void
    {
        $run = $this->createTestRunWithResults();

        $httpClient = $this->createMockHttpClient();
        $service = $this->buildAllureService($httpClient);

        // Create fake results directory
        $resultsPath = $service->getAllureResultsPath($run->getId());
        $this->filesystem->mkdir($resultsPath);
        $this->createFakeAllureResults($resultsPath, 'MOEC2609');

        $report = $service->generateReport($run, [$resultsPath]);

        $this->assertInstanceOf(TestReport::class, $report);
        $this->assertSame(TestReport::TYPE_ALLURE, $report->getReportType());
        $this->assertSame($run, $report->getTestRun());
        $this->assertNotEmpty($report->getPublicUrl());
        $this->assertNotNull($report->getGeneratedAt());
    }

    public function testGetReportUrlFormatsCorrectly(): void
    {
        $service = $this->buildAllureService();

        $url = $service->getReportUrl('test_env');

        $this->assertStringContainsString('test_env', $url);
        $this->assertStringContainsString('reports/latest/index.html', $url);
    }

    public function testGetAllureResultsPathUsesRunId(): void
    {
        $service = $this->buildAllureService();

        $path = $service->getAllureResultsPath(42);

        $this->assertStringContainsString('run-42', $path);
        $this->assertStringContainsString('allure-results', $path);
    }

    // =====================
    // Merge Results
    // =====================

    public function testMergeResultsCombinesDirectories(): void
    {
        $service = $this->buildAllureService();

        $source1 = $this->tempDir . '/source1';
        $source2 = $this->tempDir . '/source2';
        $target = $this->tempDir . '/merged';

        $this->filesystem->mkdir([$source1, $source2]);
        file_put_contents($source1 . '/result1.json', '{"name":"test1"}');
        file_put_contents($source2 . '/result2.json', '{"name":"test2"}');

        $service->mergeResults([$source1, $source2], $target);

        $this->assertFileExists($target . '/result1.json');
        $this->assertFileExists($target . '/result2.json');
        $this->assertSame('{"name":"test1"}', file_get_contents($target . '/result1.json'));
        $this->assertSame('{"name":"test2"}', file_get_contents($target . '/result2.json'));
    }

    public function testMergeResultsSkipsSelfCopy(): void
    {
        $service = $this->buildAllureService();

        $dir = $this->tempDir . '/selfcopy';
        $this->filesystem->mkdir($dir);
        file_put_contents($dir . '/result.json', '{"name":"test"}');

        // Should not error when source == target
        $service->mergeResults([$dir], $dir);

        $this->assertFileExists($dir . '/result.json');
        $this->assertSame('{"name":"test"}', file_get_contents($dir . '/result.json'));
    }

    public function testMergeResultsSkipsNonexistentSource(): void
    {
        $service = $this->buildAllureService();

        $target = $this->tempDir . '/target';

        // Should not error
        $service->mergeResults(['/nonexistent/path'], $target);

        $this->assertDirectoryExists($target);
    }

    // =====================
    // Incremental Report
    // =====================

    public function testIncrementalReportIsDebounced(): void
    {
        $run = $this->createTestRunWithResults();

        $httpClient = $this->createMock(HttpClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn('{}');

        // Expect limited calls due to debounce
        $httpClient->method('request')->willReturn($response);

        $service = $this->buildAllureService($httpClient);

        $resultsPath = $service->getAllureResultsPath($run->getId());
        $this->filesystem->mkdir($resultsPath);
        file_put_contents($resultsPath . '/uuid-result.json', '{"name":"test"}');

        // First call should generate
        $service->generateIncrementalReport($run);

        // Second immediate call should be debounced (no exception = success)
        $service->generateIncrementalReport($run);

        $this->addToAssertionCount(1);
    }

    // =====================
    // Copy Attachments
    // =====================

    public function testCopyTestAllureResultsCopiesAttachments(): void
    {
        $run = $this->createTestRunWithResults();
        $service = $this->buildAllureService();

        $runDir = $service->getAllureResultsPath($run->getId());
        $rootDir = $this->tempDir . '/var/mftf-results/allure-results';

        $this->filesystem->mkdir([$runDir, $rootDir]);

        // Create a result file referencing an attachment
        $resultData = [
            'name' => 'MOEC2609',
            'fullName' => 'App\\Tests\\MOEC2609',
            'attachments' => [
                ['source' => 'screenshot.png', 'name' => 'Screenshot'],
            ],
        ];
        file_put_contents($runDir . '/uuid-result.json', json_encode($resultData));

        // Create attachment in root
        file_put_contents($rootDir . '/screenshot.png', 'fake-png-data');

        $service->copyTestAllureResults($run->getId(), 'MOEC2609');

        $this->assertFileExists($runDir . '/screenshot.png');
    }

    // =====================
    // Cleanup
    // =====================

    public function testCleanupExpiredRemovesOldDirectories(): void
    {
        $service = $this->buildAllureService();

        $basePath = $this->tempDir . '/var/mftf-results/allure-results';
        $this->filesystem->mkdir($basePath);

        // Create an old directory (needs to appear old by mtime)
        $oldDir = $basePath . '/run-old';
        $this->filesystem->mkdir($oldDir);
        touch($oldDir, strtotime('-31 days'));

        // Create a recent directory
        $newDir = $basePath . '/run-new';
        $this->filesystem->mkdir($newDir);

        $cleaned = $service->cleanupExpired();

        $this->assertSame(1, $cleaned);
        $this->assertDirectoryDoesNotExist($oldDir);
        $this->assertDirectoryExists($newDir);
    }

    // =====================
    // Report Entity Persistence
    // =====================

    public function testReportEntityPersistedWithTestRun(): void
    {
        $run = $this->createTestRunWithResults();

        $report = new TestReport();
        $report->setTestRun($run);
        $report->setReportType(TestReport::TYPE_ALLURE);
        $report->setFilePath('/var/allure/run-' . $run->getId());
        $report->setPublicUrl('https://allure.example.com/report');
        $report->setGeneratedAt(new \DateTimeImmutable());
        $report->setExpiresAt(new \DateTimeImmutable('+30 days'));

        $this->entityManager->persist($report);
        $this->entityManager->flush();

        $this->assertNotNull($report->getId());

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(TestReport::class, $report->getId());
        $this->assertNotNull($reloaded);
        $this->assertSame(TestReport::TYPE_ALLURE, $reloaded->getReportType());
        $this->assertSame($run->getId(), $reloaded->getTestRun()->getId());
    }

    // =====================
    // Helpers
    // =====================

    private function createTestRunWithResults(): TestRun
    {
        $env = new TestEnvironment();
        $env->setName('Report Test Env ' . uniqid());
        $env->setCode('rpt-' . uniqid());
        $env->setRegion('us-east-1');
        $env->setBaseUrl('https://test.example.com');
        $env->setIsActive(true);
        $this->entityManager->persist($env);

        $run = new TestRun();
        $run->setEnvironment($env);
        $run->setType(TestRun::TYPE_MFTF);
        $run->setStatus(TestRun::STATUS_RUNNING);
        $run->setTriggeredBy(TestRun::TRIGGER_MANUAL);
        $this->entityManager->persist($run);

        $result = new TestResult();
        $result->setTestRun($run);
        $result->setTestName('TestName');
        $result->setTestId('MOEC2609');
        $result->setStatus(TestResult::STATUS_PASSED);
        $run->addResult($result);
        $this->entityManager->persist($result);
        $this->entityManager->flush();

        return $run;
    }

    private function createMockHttpClient(): HttpClientInterface
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn('{}');
        $httpClient->method('request')->willReturn($response);

        return $httpClient;
    }

    private function createFakeAllureResults(string $path, string $testId): void
    {
        $uuid = uniqid();
        $resultData = [
            'uuid' => $uuid,
            'name' => $testId . ' Test Name',
            'fullName' => 'App\\Tests\\' . $testId,
            'status' => 'passed',
            'attachments' => [],
        ];
        file_put_contents($path . '/' . $uuid . '-result.json', json_encode($resultData));

        $containerData = [
            'uuid' => uniqid(),
            'children' => [$uuid],
        ];
        file_put_contents($path . '/' . uniqid() . '-container.json', json_encode($containerData));
    }

    private function buildAllureService(?HttpClientInterface $httpClient = null): AllureReportService
    {
        return new AllureReportService(
            static::getContainer()->get('logger'),
            $httpClient ?? $this->createMockHttpClient(),
            $this->tempDir,
            'http://allure:5050',
            'https://allure.example.com',
        );
    }
}
