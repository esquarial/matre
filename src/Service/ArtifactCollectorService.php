<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TestResult;
use App\Entity\TestRun;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Lock\LockFactory;

/**
 * Collects test artifacts (screenshots, HTML) from MFTF output and organizes them by run.
 */
class ArtifactCollectorService
{
    private const SCREENSHOT_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif'];
    private const HTML_EXTENSIONS = ['html', 'htm'];
    private const MAX_ARTIFACT_SIZE = 10 * 1024 * 1024; // 10MB

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly LockFactory $lockFactory,
        private readonly string $projectDir,
        private readonly string $artifactsDir = 'var/test-artifacts',
        private readonly string $mftfResultsDir = 'var/mftf-results',
    ) {
    }

    /**
     * Collect artifacts from MFTF output directory for a test run.
     * Uses global lock to prevent artifact contamination between concurrent runs.
     *
     * @return array{screenshots: string[], html: string[]}
     */
    public function collectArtifacts(TestRun $run): array
    {
        $collected = ['screenshots' => [], 'html' => []];

        // Acquire global lock to prevent concurrent runs from mixing artifacts
        $lock = $this->lockFactory->createLock('artifact_collection', 300);
        $lock->acquire(true);

        try {
            $perRunPath = $this->projectDir . '/' . $this->mftfResultsDir . '/run-' . $run->getId();
            $rootPath = $this->projectDir . '/' . $this->mftfResultsDir;
            $targetPath = $this->getRunArtifactsPath($run);

            $sourcePaths = $this->resolveArtifactSourcePaths($perRunPath, $rootPath, $run);

            if (empty($sourcePaths)) {
                $this->logger->warning('MFTF results directories contain no current artifacts', [
                    'paths' => [$perRunPath, $rootPath],
                ]);

                return $collected;
            }

            $this->logger->debug('Collecting artifacts', [
                'runId' => $run->getId(),
                'sources' => $sourcePaths,
            ]);

            $filesystem = new Filesystem();
            $filesystem->mkdir($targetPath);
            $this->clearCollectedArtifactFiles($targetPath, $filesystem);

            foreach ($sourcePaths as $sourcePath) {
                $collected['screenshots'] = array_values(array_unique(array_merge(
                    $collected['screenshots'],
                    $this->collectFilesByExtension(
                        $sourcePath,
                        $targetPath,
                        self::SCREENSHOT_EXTENSIONS,
                        $filesystem,
                        $run,
                    ),
                )));

                $collected['html'] = array_values(array_unique(array_merge(
                    $collected['html'],
                    $this->collectFilesByExtension(
                        $sourcePath,
                        $targetPath,
                        self::HTML_EXTENSIONS,
                        $filesystem,
                        $run,
                    ),
                )));
            }

            $this->logger->info('Artifacts collected', [
                'run_id' => $run->getId(),
                'screenshots' => count($collected['screenshots']),
                'html' => count($collected['html']),
            ]);

            return $collected;
        } finally {
            $lock->release();
        }
    }

    /**
     * Associate collected screenshots with test results based on filename matching.
     *
     * @param TestResult[] $results
     * @param string[]     $screenshotPaths
     */
    public function associateScreenshotsWithResults(array $results, array $screenshotPaths): void
    {
        foreach ($results as $result) {
            // Only associate screenshots with failed/broken tests
            if (!$result->isFailed() && !$result->isBroken()) {
                continue;
            }

            $testName = $result->getTestName();
            $testId = $result->getTestId();

            foreach ($screenshotPaths as $path) {
                $filename = basename($path);

                // Match by test name or test ID with word boundaries
                if ($this->filenameMatchesTest($filename, $testId, $testName)) {
                    $result->setScreenshotPath($filename);
                    $this->logger->debug('Screenshot associated', [
                        'test' => $testName,
                        'screenshot' => $filename,
                    ]);

                    break;
                }
            }
        }
    }

    /**
     * Collect and associate screenshot and HTML for a specific test (incremental during execution).
     */
    public function collectTestScreenshot(TestRun $run, TestResult $result): void
    {
        $perRunPath = $this->projectDir . '/' . $this->mftfResultsDir . '/run-' . $run->getId();
        $rootPath = $this->projectDir . '/' . $this->mftfResultsDir;
        $targetPath = $this->getRunArtifactsPath($run);

        $sourcePaths = $this->resolveArtifactSourcePaths($perRunPath, $rootPath, $run);

        if (empty($sourcePaths)) {
            return;
        }

        $filesystem = new Filesystem();
        $filesystem->mkdir($targetPath);

        $testId = $result->getTestId();
        $testName = $result->getTestName();

        if (!$testId && !$testName) {
            $this->logger->debug('Cannot collect artifacts: TestResult missing identifiers', [
                'resultId' => $result->getId(),
            ]);

            return;
        }

        // Collect all artifact types (screenshots + HTML)
        $allExtensions = array_merge(self::SCREENSHOT_EXTENSIONS, self::HTML_EXTENSIONS);
        $screenshotCollected = false;

        foreach ($sourcePaths as $sourcePath) {
            $finder = new Finder();
            $finder->files()->in($sourcePath)->depth(0);

            // Build pattern for all extensions
            $patterns = array_map(fn ($ext) => '*.' . $ext, $allExtensions);
            $finder->name($patterns);

            foreach ($finder as $file) {
                $filename = $file->getFilename();

                // Match by test ID or test name with word boundaries
                if (!$this->filenameMatchesTest($filename, $testId, $testName)) {
                    continue;
                }

                if (!$this->isArtifactFromRun($file, $run) || $file->getSize() > self::MAX_ARTIFACT_SIZE) {
                    continue;
                }

                $realPath = $file->getRealPath();
                if (false === $realPath) {
                    $this->logger->debug('Skipping file with unresolvable path', [
                        'filename' => $filename,
                        'test' => $testName,
                    ]);

                    continue;
                }

                $targetFile = $targetPath . '/' . $filename;
                $filesystem->copy($realPath, $targetFile, true);

                // Set screenshot path for first matching screenshot (only for failed/broken tests)
                $ext = strtolower($file->getExtension());
                if (!$screenshotCollected && in_array($ext, self::SCREENSHOT_EXTENSIONS, true)
                    && ($result->isFailed() || $result->isBroken())) {
                    $result->setScreenshotPath($filename);
                    $screenshotCollected = true;
                }

                $this->logger->debug('Artifact collected for test', [
                    'test' => $testName,
                    'artifact' => $filename,
                ]);
            }
        }
    }

    /**
     * Get the artifacts directory path for a specific run.
     */
    public function getRunArtifactsPath(TestRun $run): string
    {
        return sprintf('%s/%s/%d', $this->projectDir, $this->artifactsDir, $run->getId());
    }

    /**
     * Clear old per-run directories to prevent disk space buildup.
     * Only removes run-* directories older than the specified number of days.
     *
     * @param int $keepDays Number of days to keep run directories (default: 7)
     */
    public function clearOldRunDirectories(int $keepDays = 7): void
    {
        $filesystem = new Filesystem();
        $baseDir = $this->projectDir . '/' . $this->mftfResultsDir;

        if (!$filesystem->exists($baseDir) || !is_dir($baseDir)) {
            return;
        }

        $finder = new Finder();
        $finder->directories()->in($baseDir)->depth(0)->name('run-*');

        $cutoff = new \DateTimeImmutable("-{$keepDays} days");
        $removed = 0;

        foreach ($finder as $dir) {
            if ($dir->getMTime() < $cutoff->getTimestamp()) {
                $this->logger->info('Removing old run directory', [
                    'path' => $dir->getPathname(),
                    'mtime' => date('Y-m-d H:i:s', $dir->getMTime()),
                ]);
                $filesystem->remove($dir->getPathname());
                ++$removed;
            }
        }

        if ($removed > 0) {
            $this->logger->info('Cleaned up old run directories', ['count' => $removed]);
        }
    }

    /**
     * Clear root-level artifacts (screenshots, HTML) from mftf-results directory.
     * Called before each test run to prevent artifact contamination from previous runs.
     *
     * Only clears files at root level, preserves run-* subdirectories.
     */
    public function clearRootLevelArtifacts(): void
    {
        $filesystem = new Filesystem();
        $baseDir = $this->projectDir . '/' . $this->mftfResultsDir;

        if (!$filesystem->exists($baseDir) || !is_dir($baseDir)) {
            return;
        }

        $allExtensions = array_merge(self::SCREENSHOT_EXTENSIONS, self::HTML_EXTENSIONS);
        $removed = 0;

        $finder = new Finder();
        $finder->files()->in($baseDir)->depth(0);

        // Build pattern for all artifact extensions (array of patterns)
        $patterns = array_map(fn ($ext) => '*.' . $ext, $allExtensions);
        $finder->name($patterns);

        foreach ($finder as $file) {
            $realPath = $file->getRealPath();
            if (false === $realPath) {
                $this->logger->debug('Skipping artifact with unresolvable path during cleanup', [
                    'filename' => $file->getFilename(),
                ]);

                continue;
            }

            try {
                $filesystem->remove($realPath);
                ++$removed;
            } catch (\Symfony\Component\Filesystem\Exception\IOException $e) {
                $this->logger->warning('Failed to remove root-level artifact', [
                    'file' => $realPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($removed > 0) {
            $this->logger->info('Cleared root-level artifacts before test run', ['count' => $removed]);
        }
    }

    /**
     * Get web-accessible path for an artifact.
     */
    public function getArtifactWebPath(TestRun $run, string $filename): string
    {
        return sprintf('/admin/test-runs/%d/artifacts/%s', $run->getId(), $filename);
    }

    /**
     * Get full filesystem path to an artifact file.
     *
     * SECURITY: Validates that the requested file is within the run's artifact directory
     * to prevent path traversal attacks.
     *
     * @throws \InvalidArgumentException if filename contains path traversal attempts
     */
    public function getArtifactFilePath(TestRun $run, string $filename): string
    {
        // SECURITY: Sanitize filename to prevent path traversal
        $sanitizedFilename = $this->sanitizeFilename($filename);

        $basePath = $this->getRunArtifactsPath($run);
        $fullPath = $basePath . '/' . $sanitizedFilename;

        // SECURITY: Resolve real path and verify it's within the allowed directory
        // If file exists, use realpath; otherwise verify parent directory
        if (file_exists($fullPath)) {
            $realPath = realpath($fullPath);
            $realBase = realpath($basePath);

            if (false === $realPath || false === $realBase) {
                throw new \InvalidArgumentException('Invalid artifact path');
            }

            // Ensure the resolved path is within the artifacts directory
            if (!str_starts_with($realPath, $realBase . '/')) {
                throw new \InvalidArgumentException('Path traversal attempt detected');
            }

            return $realPath;
        }

        return $fullPath;
    }

    /**
     * Check if artifact file exists.
     *
     * SECURITY: Uses sanitized path to prevent path traversal attacks.
     */
    public function artifactExists(TestRun $run, string $filename): bool
    {
        try {
            $path = $this->getArtifactFilePath($run, $filename);

            return file_exists($path) && is_file($path);
        } catch (\InvalidArgumentException) {
            // Path traversal attempt or invalid path
            return false;
        }
    }

    /**
     * List all artifacts for a run.
     *
     * @return array{screenshots: string[], html: string[], other: string[]}
     */
    public function listArtifacts(TestRun $run): array
    {
        $path = $this->getRunArtifactsPath($run);
        $artifacts = ['screenshots' => [], 'html' => [], 'other' => []];

        if (!is_dir($path)) {
            return $artifacts;
        }

        $finder = new Finder();
        $finder->files()->in($path)->depth(0);

        foreach ($finder as $file) {
            $ext = strtolower($file->getExtension());
            $filename = $file->getFilename();

            if (in_array($ext, self::SCREENSHOT_EXTENSIONS, true)) {
                $artifacts['screenshots'][] = $filename;
            } elseif (in_array($ext, self::HTML_EXTENSIONS, true)) {
                $artifacts['html'][] = $filename;
            } else {
                $artifacts['other'][] = $filename;
            }
        }

        return $artifacts;
    }

    /**
     * Pair same-basename HTML failure pages with screenshots for display.
     *
     * @param array{screenshots: string[], html: string[], other?: string[]} $artifacts
     *
     * @return array{screenshots: array<int, array{screenshot: string, html: string|null}>, html: string[]}
     */
    public function groupArtifactsForDisplay(array $artifacts): array
    {
        $htmlByBaseName = [];
        foreach ($artifacts['html'] as $html) {
            $htmlByBaseName[strtolower(pathinfo($html, PATHINFO_FILENAME))] = $html;
        }

        $pairedHtml = [];
        $screenshots = [];
        foreach ($artifacts['screenshots'] as $screenshot) {
            $baseName = strtolower(pathinfo($screenshot, PATHINFO_FILENAME));
            $html = $htmlByBaseName[$baseName] ?? null;

            if (null !== $html) {
                $pairedHtml[$html] = true;
            }

            $screenshots[] = [
                'screenshot' => $screenshot,
                'html' => $html,
            ];
        }

        $unpairedHtml = array_values(array_filter(
            $artifacts['html'],
            static fn (string $html): bool => !isset($pairedHtml[$html]),
        ));

        return [
            'screenshots' => $screenshots,
            'html' => $unpairedHtml,
        ];
    }

    /**
     * Clean up artifacts older than specified days.
     */
    public function cleanupOldArtifacts(int $daysOld = 30): int
    {
        $baseDir = $this->projectDir . '/' . $this->artifactsDir;

        if (!is_dir($baseDir)) {
            return 0;
        }

        $filesystem = new Filesystem();
        $cutoff = new \DateTimeImmutable("-{$daysOld} days");
        $removed = 0;

        $finder = new Finder();
        $finder->directories()->in($baseDir)->depth(0);

        foreach ($finder as $dir) {
            if ($dir->getMTime() < $cutoff->getTimestamp()) {
                $realPath = $dir->getRealPath();
                if (false === $realPath) {
                    $this->logger->debug('Skipping directory with unresolvable path during cleanup', [
                        'pathname' => $dir->getPathname(),
                    ]);

                    continue;
                }

                $filesystem->remove($realPath);
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * Check if filename contains test identifier with word boundaries.
     * Prevents MOEC2609 from matching MOEC2609ES.
     */
    private function filenameMatchesTest(string $filename, ?string $testId, ?string $testName): bool
    {
        if ($testId && preg_match('/\b' . preg_quote($testId, '/') . '\b/i', $filename)) {
            return true;
        }

        return (bool) ($testName && preg_match('/\b' . preg_quote($testName, '/') . '\b/i', $filename))

        ;
    }

    /**
     * Sanitize a filename to prevent path traversal attacks.
     *
     * @throws \InvalidArgumentException if filename is invalid
     */
    private function sanitizeFilename(string $filename): string
    {
        // Remove null bytes
        $filename = str_replace("\0", '', $filename);

        // Reject path traversal patterns
        if (str_contains($filename, '..') || str_contains($filename, "\0")) {
            throw new \InvalidArgumentException('Invalid filename: path traversal detected');
        }

        // Reject absolute paths
        if (str_starts_with($filename, '/') || preg_match('/^[a-zA-Z]:/', $filename)) {
            throw new \InvalidArgumentException('Invalid filename: absolute path not allowed');
        }

        // Get just the basename (removes directory components)
        $basename = basename($filename);

        if ('' === $basename || '.' === $basename || '..' === $basename) {
            throw new \InvalidArgumentException('Invalid filename');
        }

        return $basename;
    }

    /**
     * Collect files by extension from source to target directory.
     *
     * @param string[] $extensions
     *
     * @return string[] Collected filenames
     */
    private function collectFilesByExtension(
        string $sourcePath,
        string $targetPath,
        array $extensions,
        Filesystem $filesystem,
        TestRun $run,
    ): array {
        $collected = [];

        $finder = new Finder();
        $finder->files()->in($sourcePath)->depth(0);

        foreach ($extensions as $ext) {
            $finder->name('*.' . $ext);
        }

        foreach ($finder as $file) {
            if (!$this->isArtifactFromRun($file, $run)) {
                continue;
            }

            if ($file->getSize() > self::MAX_ARTIFACT_SIZE) {
                $this->logger->warning('Artifact too large, skipping', [
                    'file' => $file->getFilename(),
                    'size' => $file->getSize(),
                ]);

                continue;
            }

            $realPath = $file->getRealPath();
            if (false === $realPath) {
                $this->logger->debug('Skipping artifact with unresolvable path', [
                    'filename' => $file->getFilename(),
                ]);

                continue;
            }

            $targetFile = $targetPath . '/' . $file->getFilename();
            $filesystem->copy($realPath, $targetFile, true);
            $collected[] = $file->getFilename();
        }

        return $collected;
    }

    private function clearCollectedArtifactFiles(string $targetPath, Filesystem $filesystem): void
    {
        if (!is_dir($targetPath)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($targetPath)->depth(0);

        $extensions = array_merge(self::SCREENSHOT_EXTENSIONS, self::HTML_EXTENSIONS);
        $patterns = array_map(static fn (string $ext): string => '*.' . $ext, $extensions);
        $finder->name($patterns);

        foreach ($finder as $file) {
            $realPath = $file->getRealPath();
            if (false !== $realPath) {
                $filesystem->remove($realPath);
            }
        }
    }

    /**
     * @return string[]
     */
    private function resolveArtifactSourcePaths(string $perRunPath, string $rootPath, TestRun $run): array
    {
        $paths = [];
        foreach ([$perRunPath, $rootPath] as $path) {
            if (is_dir($path) && $this->hasCollectableArtifacts($path, $run)) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    private function hasCollectableArtifacts(string $path, TestRun $run): bool
    {
        if (!is_dir($path)) {
            return false;
        }

        $finder = new Finder();
        $finder->files()->in($path)->depth(0);

        $extensions = array_merge(self::SCREENSHOT_EXTENSIONS, self::HTML_EXTENSIONS);
        $patterns = array_map(static fn (string $ext): string => '*.' . $ext, $extensions);
        $finder->name($patterns);

        foreach ($finder as $file) {
            if ($this->isArtifactFromRun($file, $run)) {
                return true;
            }
        }

        return false;
    }

    private function isArtifactFromRun(\SplFileInfo $file, TestRun $run): bool
    {
        $startedAt = $run->getStartedAt() ?? $run->getCreatedAt();
        $earliestArtifactTime = $startedAt->getTimestamp() - 60;

        return $file->getMTime() >= $earliestArtifactTime;
    }
}
