<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\TestEnvironment;
use App\Entity\TestResult;
use App\Entity\TestRun;
use App\Service\ArtifactCollectorService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Integration test for artifact collection: execute → collect → associate.
 */
class ArtifactCollectionTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private string $tempDir;

    private Filesystem $filesystem;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir() . '/matre_artifact_test_' . uniqid();
        $this->filesystem->mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tempDir);
        $this->entityManager->close();
        parent::tearDown();
    }

    // =====================
    // Collect Artifacts
    // =====================

    public function testCollectArtifactsFromPerRunDirectory(): void
    {
        $run = $this->createTestRun();
        $service = $this->buildService();

        $perRunPath = $this->tempDir . '/var/mftf-results/run-' . $run->getId();
        $this->filesystem->mkdir($perRunPath);
        file_put_contents($perRunPath . '/MOEC2609_screenshot.png', 'fake-png');
        file_put_contents($perRunPath . '/error_page.html', '<html>error</html>');

        $collected = $service->collectArtifacts($run);

        $this->assertCount(1, $collected['screenshots']);
        $this->assertCount(1, $collected['html']);
        $this->assertContains('MOEC2609_screenshot.png', $collected['screenshots']);
        $this->assertContains('error_page.html', $collected['html']);

        $targetPath = $service->getRunArtifactsPath($run);
        $this->assertFileExists($targetPath . '/MOEC2609_screenshot.png');
        $this->assertFileExists($targetPath . '/error_page.html');
    }

    public function testCollectArtifactsFallsBackToRoot(): void
    {
        $run = $this->createTestRun();
        $service = $this->buildService();

        $rootPath = $this->tempDir . '/var/mftf-results';
        $this->filesystem->mkdir($rootPath);
        file_put_contents($rootPath . '/test_screenshot.jpg', 'fake-jpg');

        $collected = $service->collectArtifacts($run);

        $this->assertCount(1, $collected['screenshots']);
        $this->assertContains('test_screenshot.jpg', $collected['screenshots']);
    }

    public function testCollectArtifactsReturnsEmptyWhenNoDirectory(): void
    {
        $run = $this->createTestRun();
        $service = $this->buildService();

        $collected = $service->collectArtifacts($run);

        $this->assertEmpty($collected['screenshots']);
        $this->assertEmpty($collected['html']);
    }

    public function testCollectArtifactsSkipsLargeFiles(): void
    {
        $run = $this->createTestRun();
        $service = $this->buildService();

        $perRunPath = $this->tempDir . '/var/mftf-results/run-' . $run->getId();
        $this->filesystem->mkdir($perRunPath);
        file_put_contents($perRunPath . '/small.png', str_repeat('a', 100));
        file_put_contents($perRunPath . '/huge.png', str_repeat('a', 11 * 1024 * 1024));

        $collected = $service->collectArtifacts($run);

        $this->assertCount(1, $collected['screenshots']);
        $this->assertContains('small.png', $collected['screenshots']);
    }

    // =====================
    // Associate Screenshots
    // =====================

    public function testAssociateScreenshotsWithResultsByTestId(): void
    {
        $run = $this->createTestRun();
        $service = $this->buildService();

        $result1 = new TestResult();
        $result1->setTestRun($run);
        $result1->setTestName('CheckoutTest');
        $result1->setTestId('MOEC2609');
        $result1->setStatus(TestResult::STATUS_FAILED);

        $result2 = new TestResult();
        $result2->setTestRun($run);
        $result2->setTestName('LoginTest');
        $result2->setTestId('MOEC2610');
        $result2->setStatus(TestResult::STATUS_FAILED);

        $screenshots = [
            '/path/to/MOEC2609-fail-screenshot.png',
            '/path/to/MOEC2610-error.png',
        ];

        $service->associateScreenshotsWithResults([$result1, $result2], $screenshots);

        $this->assertSame('MOEC2609-fail-screenshot.png', $result1->getScreenshotPath());
        $this->assertSame('MOEC2610-error.png', $result2->getScreenshotPath());
    }

    public function testAssociateScreenshotsNoMatchLeavesNull(): void
    {
        $run = $this->createTestRun();
        $service = $this->buildService();

        $result = new TestResult();
        $result->setTestRun($run);
        $result->setTestName('UnrelatedTest');
        $result->setTestId('MOEC9999');
        $result->setStatus(TestResult::STATUS_PASSED);

        $screenshots = ['/path/to/MOEC2609_screenshot.png'];
        $service->associateScreenshotsWithResults([$result], $screenshots);

        $this->assertNull($result->getScreenshotPath());
    }

    public function testAssociateScreenshotsWordBoundaryPreventsPartialMatch(): void
    {
        $run = $this->createTestRun();
        $service = $this->buildService();

        $result = new TestResult();
        $result->setTestRun($run);
        $result->setTestName('Test');
        $result->setTestId('MOEC2609');
        $result->setStatus(TestResult::STATUS_FAILED);

        // MOEC2609ES should NOT match MOEC2609
        $screenshots = ['/path/to/MOEC2609ES_screenshot.png'];
        $service->associateScreenshotsWithResults([$result], $screenshots);

        $this->assertNull($result->getScreenshotPath());
    }

    // =====================
    // Collect Test Screenshot (Incremental)
    // =====================

    public function testCollectTestScreenshotCopiesMatchingFiles(): void
    {
        $run = $this->createTestRun();
        $service = $this->buildService();

        $perRunPath = $this->tempDir . '/var/mftf-results/run-' . $run->getId();
        $this->filesystem->mkdir($perRunPath);
        file_put_contents($perRunPath . '/MOEC2609-fail.png', 'fake-png');
        file_put_contents($perRunPath . '/MOEC2609-page.html', '<html></html>');
        file_put_contents($perRunPath . '/MOEC2610-other.png', 'other-png');

        $result = new TestResult();
        $result->setTestRun($run);
        $result->setTestName('CheckoutTest');
        $result->setTestId('MOEC2609');
        $result->setStatus(TestResult::STATUS_FAILED);

        $service->collectTestScreenshot($run, $result);

        $targetPath = $service->getRunArtifactsPath($run);
        $this->assertFileExists($targetPath . '/MOEC2609-fail.png');
        $this->assertFileExists($targetPath . '/MOEC2609-page.html');
        $this->assertFileDoesNotExist($targetPath . '/MOEC2610-other.png');
        $this->assertSame('MOEC2609-fail.png', $result->getScreenshotPath());
    }

    // =====================
    // Security: Path Traversal
    // =====================

    public function testGetArtifactFilePathRejectsTraversal(): void
    {
        $run = $this->createTestRun();
        $service = $this->buildService();

        $this->expectException(\InvalidArgumentException::class);
        $service->getArtifactFilePath($run, '../../../etc/passwd');
    }

    public function testGetArtifactFilePathRejectsAbsolutePath(): void
    {
        $run = $this->createTestRun();
        $service = $this->buildService();

        $this->expectException(\InvalidArgumentException::class);
        $service->getArtifactFilePath($run, '/etc/passwd');
    }

    public function testArtifactExistsReturnsFalseForTraversal(): void
    {
        $run = $this->createTestRun();
        $service = $this->buildService();

        $this->assertFalse($service->artifactExists($run, '../../../etc/passwd'));
    }

    // =====================
    // List Artifacts
    // =====================

    public function testListArtifactsCategorizes(): void
    {
        $run = $this->createTestRun();
        $service = $this->buildService();

        $targetPath = $service->getRunArtifactsPath($run);
        $this->filesystem->mkdir($targetPath);
        file_put_contents($targetPath . '/screenshot.png', 'png');
        file_put_contents($targetPath . '/photo.jpg', 'jpg');
        file_put_contents($targetPath . '/page.html', 'html');
        file_put_contents($targetPath . '/data.json', 'json');

        $artifacts = $service->listArtifacts($run);

        $this->assertCount(2, $artifacts['screenshots']);
        $this->assertCount(1, $artifacts['html']);
        $this->assertCount(1, $artifacts['other']);
    }

    public function testListArtifactsReturnsEmptyForMissingDir(): void
    {
        $run = $this->createTestRun();
        $service = $this->buildService();

        $artifacts = $service->listArtifacts($run);

        $this->assertEmpty($artifacts['screenshots']);
        $this->assertEmpty($artifacts['html']);
        $this->assertEmpty($artifacts['other']);
    }

    // =====================
    // Cleanup
    // =====================

    public function testClearRootLevelArtifacts(): void
    {
        $service = $this->buildService();

        $rootPath = $this->tempDir . '/var/mftf-results';
        $this->filesystem->mkdir($rootPath);
        file_put_contents($rootPath . '/screenshot.png', 'png');
        file_put_contents($rootPath . '/page.html', 'html');
        file_put_contents($rootPath . '/keep.json', 'json');

        $service->clearRootLevelArtifacts();

        $this->assertFileDoesNotExist($rootPath . '/screenshot.png');
        $this->assertFileDoesNotExist($rootPath . '/page.html');
        $this->assertFileExists($rootPath . '/keep.json');
    }

    public function testCleanupOldArtifactsRemovesExpired(): void
    {
        $service = $this->buildService();

        $basePath = $this->tempDir . '/var/test-artifacts';
        $this->filesystem->mkdir($basePath);

        $oldDir = $basePath . '/999';
        $this->filesystem->mkdir($oldDir);
        file_put_contents($oldDir . '/file.png', 'png');
        touch($oldDir, strtotime('-31 days'));

        $newDir = $basePath . '/1000';
        $this->filesystem->mkdir($newDir);
        file_put_contents($newDir . '/file.png', 'png');

        $removed = $service->cleanupOldArtifacts(30);

        $this->assertGreaterThanOrEqual(1, $removed);
        $this->assertDirectoryDoesNotExist($oldDir);
        $this->assertDirectoryExists($newDir);
    }

    // =====================
    // Web Path
    // =====================

    public function testGetArtifactWebPath(): void
    {
        $run = $this->createTestRun();
        $service = $this->buildService();

        $path = $service->getArtifactWebPath($run, 'screenshot.png');

        $this->assertStringContainsString('/admin/test-runs/', $path);
        $this->assertStringContainsString('/artifacts/screenshot.png', $path);
    }

    // =====================
    // Helpers
    // =====================

    private function createTestRun(): TestRun
    {
        $env = new TestEnvironment();
        $env->setName('Artifact Test Env ' . uniqid());
        $env->setCode('art-' . uniqid());
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
        $this->entityManager->flush();

        return $run;
    }

    private function buildService(): ArtifactCollectorService
    {
        $lockFactory = new LockFactory(new InMemoryStore());

        return new ArtifactCollectorService(
            new NullLogger(),
            $lockFactory,
            $this->tempDir,
        );
    }
}
