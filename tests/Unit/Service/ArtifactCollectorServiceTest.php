<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\TestResult;
use App\Entity\TestRun;
use App\Service\ArtifactCollectorService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

class ArtifactCollectorServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/artifact_test_' . uniqid();
        mkdir($this->tempDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testGetRunArtifactsPathReturnsCorrectPath(): void
    {
        $service = $this->createService();
        $run = $this->createTestRun(42);

        $this->assertSame($this->tempDir . '/var/test-artifacts/42', $service->getRunArtifactsPath($run));
    }

    public function testGetArtifactWebPathReturnsCorrectPath(): void
    {
        $service = $this->createService();
        $run = $this->createTestRun(42);

        $this->assertSame('/admin/test-runs/42/artifacts/screenshot.png', $service->getArtifactWebPath($run, 'screenshot.png'));
    }

    public function testGetArtifactFilePathRejectsPathTraversal(): void
    {
        $service = $this->createService();
        $run = $this->createTestRun(42);

        $this->expectException(\InvalidArgumentException::class);
        $service->getArtifactFilePath($run, '../../../etc/passwd');
    }

    public function testGetArtifactFilePathRejectsDirectoryTraversalInSubdir(): void
    {
        $service = $this->createService();
        $run = $this->createTestRun(42);

        $this->expectException(\InvalidArgumentException::class);
        $service->getArtifactFilePath($run, 'subdir/../../../etc/passwd');
    }

    public function testGetArtifactFilePathRejectsAbsolutePaths(): void
    {
        $service = $this->createService();
        $run = $this->createTestRun(42);

        $this->expectException(\InvalidArgumentException::class);
        $service->getArtifactFilePath($run, '/etc/passwd');
    }

    public function testGetArtifactFilePathReturnsValidPath(): void
    {
        $service = $this->createService();
        $run = $this->createTestRun(42);

        $path = $service->getArtifactFilePath($run, 'screenshot.png');

        $this->assertSame($this->tempDir . '/var/test-artifacts/42/screenshot.png', $path);
    }

    public function testArtifactExistsReturnsFalseForPathTraversal(): void
    {
        $service = $this->createService();
        $run = $this->createTestRun(42);

        $this->assertFalse($service->artifactExists($run, '../../../etc/passwd'));
    }

    public function testArtifactExistsReturnsTrueForExistingFile(): void
    {
        $service = $this->createService();
        $run = $this->createTestRun(42);

        $artifactsDir = $this->tempDir . '/var/test-artifacts/42';
        mkdir($artifactsDir, 0o777, true);
        file_put_contents($artifactsDir . '/screenshot.png', 'fake-png');

        $this->assertTrue($service->artifactExists($run, 'screenshot.png'));
    }

    public function testListArtifactsCategorizesFiles(): void
    {
        $service = $this->createService();
        $run = $this->createTestRun(42);

        $artifactsDir = $this->tempDir . '/var/test-artifacts/42';
        mkdir($artifactsDir, 0o777, true);
        file_put_contents($artifactsDir . '/shot.png', 'png');
        file_put_contents($artifactsDir . '/page.html', 'html');
        file_put_contents($artifactsDir . '/log.txt', 'txt');

        $artifacts = $service->listArtifacts($run);

        $this->assertContains('shot.png', $artifacts['screenshots']);
        $this->assertContains('page.html', $artifacts['html']);
        $this->assertContains('log.txt', $artifacts['other']);
    }

    public function testListArtifactsReturnsEmptyForNonexistentDir(): void
    {
        $service = $this->createService();
        $run = $this->createTestRun(999);

        $artifacts = $service->listArtifacts($run);

        $this->assertSame([], $artifacts['screenshots']);
        $this->assertSame([], $artifacts['html']);
        $this->assertSame([], $artifacts['other']);
    }

    public function testGroupArtifactsForDisplayPairsHtmlByBaseName(): void
    {
        $service = $this->createService();

        $grouped = $service->groupArtifactsForDisplay([
            'screenshots' => [
                'Magento.AcceptanceTest._default.Backend.MOEC13447Cest.MOEC13447.fail.png',
                'MOEC2609-fail.jpg',
            ],
            'html' => [
                'Magento.AcceptanceTest._default.Backend.MOEC13447Cest.MOEC13447.fail.html',
                'unpaired-report.html',
            ],
        ]);

        $this->assertSame([
            [
                'screenshot' => 'Magento.AcceptanceTest._default.Backend.MOEC13447Cest.MOEC13447.fail.png',
                'html' => 'Magento.AcceptanceTest._default.Backend.MOEC13447Cest.MOEC13447.fail.html',
            ],
            [
                'screenshot' => 'MOEC2609-fail.jpg',
                'html' => null,
            ],
        ], $grouped['screenshots']);
        $this->assertSame(['unpaired-report.html'], $grouped['html']);
    }

    public function testAssociateScreenshotsMatchesByTestIdWithWordBoundary(): void
    {
        $service = $this->createService();

        $result = new TestResult();
        $result->setTestId('MOEC2609');
        $result->setTestName('SomeTest');
        $result->setStatus(TestResult::STATUS_FAILED);

        // Use a separator that creates a word boundary (- is not a word char)
        $service->associateScreenshotsWithResults(
            [$result],
            ['/artifacts/MOEC2609-failed-screenshot.png'],
        );

        $this->assertSame('MOEC2609-failed-screenshot.png', $result->getScreenshotPath());
    }

    public function testAssociateScreenshotsDoesNotMatchPartialId(): void
    {
        $service = $this->createService();

        $result = new TestResult();
        $result->setTestId('MOEC2609');
        $result->setTestName('SomeTest');
        $result->setStatus(TestResult::STATUS_FAILED);

        // MOEC2609ES contains MOEC2609 but \b prevents partial match
        // since 'S' is a word char, \bMOEC2609\b won't match inside MOEC2609ES
        $service->associateScreenshotsWithResults(
            [$result],
            ['/artifacts/MOEC2609ES-failed-screenshot.png'],
        );

        $this->assertNull($result->getScreenshotPath());
    }

    public function testAssociateScreenshotsSkipsPassedResults(): void
    {
        $service = $this->createService();

        $result = new TestResult();
        $result->setTestId('MOEC2609');
        $result->setTestName('SomeTest');
        $result->setStatus(TestResult::STATUS_PASSED);

        $service->associateScreenshotsWithResults(
            [$result],
            ['/artifacts/MOEC2609-screenshot.png'],
        );

        $this->assertNull($result->getScreenshotPath());
    }

    public function testAssociateScreenshotsSkipsSkippedResults(): void
    {
        $service = $this->createService();

        $result = new TestResult();
        $result->setTestId('MOEC2609');
        $result->setTestName('SomeTest');
        $result->setStatus(TestResult::STATUS_SKIPPED);

        $service->associateScreenshotsWithResults(
            [$result],
            ['/artifacts/MOEC2609-screenshot.png'],
        );

        $this->assertNull($result->getScreenshotPath());
    }

    public function testAssociateScreenshotsMatchesBrokenResults(): void
    {
        $service = $this->createService();

        $result = new TestResult();
        $result->setTestId('MOEC2609');
        $result->setTestName('SomeTest');
        $result->setStatus(TestResult::STATUS_BROKEN);

        $service->associateScreenshotsWithResults(
            [$result],
            ['/artifacts/MOEC2609-screenshot.png'],
        );

        $this->assertSame('MOEC2609-screenshot.png', $result->getScreenshotPath());
    }

    public function testClearRootLevelArtifactsRemovesFilesButNotSubdirs(): void
    {
        $service = $this->createService();

        $mftfDir = $this->tempDir . '/var/mftf-results';
        mkdir($mftfDir . '/run-1', 0o777, true);
        file_put_contents($mftfDir . '/stray.png', 'png');
        file_put_contents($mftfDir . '/stray.html', 'html');
        file_put_contents($mftfDir . '/run-1/keep.png', 'png');

        $service->clearRootLevelArtifacts();

        $this->assertFileDoesNotExist($mftfDir . '/stray.png');
        $this->assertFileDoesNotExist($mftfDir . '/stray.html');
        $this->assertDirectoryExists($mftfDir . '/run-1');
        $this->assertFileExists($mftfDir . '/run-1/keep.png');
    }

    private function createService(): ArtifactCollectorService
    {
        $logger = $this->createStub(LoggerInterface::class);
        $lock = $this->createStub(SharedLockInterface::class);
        $lockFactory = $this->createStub(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        return new ArtifactCollectorService(
            $logger,
            $lockFactory,
            $this->tempDir,
        );
    }

    private function createTestRun(int $id): TestRun
    {
        $run = new TestRun();
        $ref = new \ReflectionClass($run);
        $idProp = $ref->getProperty('id');
        $idProp->setValue($run, $id);

        return $run;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
