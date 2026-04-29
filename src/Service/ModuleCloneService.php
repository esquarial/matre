<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Handles Git operations for cloning test modules.
 */
class ModuleCloneService
{
    private readonly Filesystem $filesystem;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly LockFactory $lockFactory,
        private readonly string $moduleRepo,
        private readonly string $moduleBranch,
        private readonly string $projectDir,
        private readonly ?string $repoUsername = null,
        private readonly ?string $repoPassword = null,
        private readonly ?string $devModulePath = null,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * Check if dev mode is enabled (local module path configured).
     */
    public function isDevModeEnabled(): bool
    {
        return !empty($this->devModulePath);
    }

    /**
     * Get resolved dev module path (absolute).
     */
    public function getDevModulePath(): ?string
    {
        if (empty($this->devModulePath)) {
            return null;
        }

        // Handle relative paths
        if (!str_starts_with($this->devModulePath, '/')) {
            return $this->projectDir . '/' . $this->devModulePath;
        }

        return $this->devModulePath;
    }

    /**
     * Use local module via symlink (dev mode).
     *
     * @throws \RuntimeException if dev module path doesn't exist
     */
    public function useLocalModule(string $targetPath): void
    {
        $sourcePath = $this->getDevModulePath();

        if (!$this->filesystem->exists($sourcePath)) {
            throw new \RuntimeException(sprintf('Dev module path does not exist: %s (DEV_MODULE_PATH=%s)', $sourcePath, $this->devModulePath));
        }

        $this->logger->info('Using local module (dev mode)', [
            'source' => $sourcePath,
            'target' => $targetPath,
        ]);

        // Remove existing target if any
        if ($this->filesystem->exists($targetPath)) {
            $this->filesystem->remove($targetPath);
        }

        // Create parent dir if needed
        $this->filesystem->mkdir(\dirname($targetPath));

        // Create symlink to local module
        $this->filesystem->symlink(
            (string) realpath($sourcePath),
            $targetPath,
        );

        $this->logger->info('Local module symlinked successfully');
    }

    /**
     * Clone module repository to target path (or use local module in dev mode).
     */
    public function cloneModule(string $targetPath): void
    {
        // Dev mode: use local module via symlink
        if ($this->isDevModeEnabled()) {
            $this->useLocalModule($targetPath);

            return;
        }

        $repoUrl = $this->getAuthenticatedRepoUrl();

        $this->logger->info('Cloning module', [
            'repo' => $this->moduleRepo,
            'branch' => $this->moduleBranch,
            'target' => $targetPath,
        ]);

        // Clone into temp directory first, then move contents into target
        // to preserve the target directory's inode (critical for Docker bind mounts)
        $tempPath = \dirname($targetPath) . '/.clone-tmp-' . uniqid();

        $process = new Process([
            'git', 'clone',
            '--branch', $this->moduleBranch,
            '--depth', '1',
            '--single-branch',
            $repoUrl,
            $tempPath,
        ]);
        $process->setTimeout(300);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            $this->filesystem->remove($tempPath);
            $this->logger->error('Failed to clone module', [
                'error' => $e->getMessage(),
                'output' => $this->sanitizeOutput($process->getErrorOutput()),
            ]);

            throw $e;
        }

        // Preserve inode: clear contents of existing dir, or create new dir
        if ($this->filesystem->exists($targetPath)) {
            $this->clearDirectoryContents($targetPath);
        } else {
            $this->filesystem->mkdir($targetPath);
        }

        $this->moveDirectoryContents($tempPath, $targetPath);
        $this->filesystem->remove($tempPath);

        $this->logger->info('Module cloned successfully');
    }

    /**
     * Pull latest changes from repository.
     * Resets local changes first to ensure clean state.
     */
    public function pullLatest(string $targetPath): void
    {
        if (!$this->filesystem->exists($targetPath . '/.git')) {
            throw new \RuntimeException('Target path is not a git repository');
        }

        $this->logger->info('Pulling latest changes', ['path' => $targetPath]);

        // Reset any local changes to ensure clean pull
        $resetProcess = new Process(['git', 'checkout', '.'], $targetPath);
        $resetProcess->setTimeout(30);
        $resetProcess->run();

        // Clean untracked files
        $cleanProcess = new Process(['git', 'clean', '-fd'], $targetPath);
        $cleanProcess->setTimeout(30);
        $cleanProcess->run();

        $process = new Process(['git', 'pull', 'origin', $this->moduleBranch], $targetPath);
        $process->setTimeout(120);

        try {
            $process->mustRun();
            $this->logger->info('Pull completed successfully');
        } catch (ProcessFailedException $e) {
            $this->logger->error('Failed to pull changes', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get the default module target path.
     * In dev mode, returns the dev module path; otherwise var/test-modules/current.
     */
    public function getDefaultTargetPath(): string
    {
        if ($this->isDevModeEnabled()) {
            return $this->getDevModulePath();
        }

        return $this->projectDir . '/var/test-modules/current';
    }

    /**
     * Prepare module for test execution (with locking for concurrent access).
     *
     * Uses lock to prevent race conditions when multiple workers start simultaneously.
     * First run clones the module, subsequent runs do git pull to update.
     */
    public function prepareModule(): string
    {
        $targetPath = $this->getDefaultTargetPath();

        // Dev mode: module is mounted directly via docker-compose, no preparation needed
        if ($this->isDevModeEnabled()) {
            if (!$this->filesystem->exists($targetPath)) {
                throw new \RuntimeException(sprintf('Dev module path does not exist: %s (DEV_MODULE_PATH=%s)', $targetPath, $this->devModulePath));
            }
            $this->logger->info('Using local module (dev mode)', ['path' => $targetPath]);

            return $targetPath;
        }

        // Lock to prevent race conditions when multiple workers try to clone/pull simultaneously
        $lock = $this->lockFactory->createLock('module_clone', 300);
        $lock->acquire(true);

        try {
            if ($this->filesystem->exists($targetPath . '/.git')) {
                // Existing clone: skip pull if already at latest remote commit
                if ($this->isAtLatestCommit($targetPath)) {
                    $this->logger->info('Module already at latest commit, skipping pull');
                } else {
                    $this->pullLatest($targetPath);
                }
            } else {
                // Fresh clone
                $this->cloneModule($targetPath);
            }
        } finally {
            $lock->release();
        }

        return $targetPath;
    }

    /**
     * Clean up module directory.
     */
    public function cleanup(string $path): void
    {
        if ($this->filesystem->exists($path)) {
            $this->logger->info('Cleaning up module directory', ['path' => $path]);
            $this->filesystem->remove($path);
        }
    }

    /**
     * Get current commit hash.
     */
    public function getCommitHash(string $targetPath): ?string
    {
        if (!$this->filesystem->exists($targetPath . '/.git')) {
            return null;
        }

        $process = new Process(['git', 'rev-parse', 'HEAD'], $targetPath);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    public function getModuleRepo(): string
    {
        return $this->moduleRepo;
    }

    public function getModuleBranch(): string
    {
        return $this->moduleBranch;
    }

    /**
     * Check if local clone is already at the latest remote commit.
     */
    private function isAtLatestCommit(string $targetPath): bool
    {
        $localHash = $this->getCommitHash($targetPath);
        if (null === $localHash) {
            return false;
        }

        $repoUrl = $this->getAuthenticatedRepoUrl();
        $process = new Process(['git', 'ls-remote', $repoUrl, $this->moduleBranch]);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->warning('Failed to query remote commit, will pull', [
                'error' => $this->sanitizeOutput($process->getErrorOutput()),
            ]);

            return false;
        }

        // Output format: "<hash>\trefs/heads/<branch>"
        $output = trim($process->getOutput());
        $remoteHash = explode("\t", $output)[0] ?? '';

        return $localHash === $remoteHash;
    }

    /**
     * Remove all contents of a directory without removing the directory itself.
     */
    private function clearDirectoryContents(string $directory): void
    {
        $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
        $items = [];
        foreach ($iterator as $item) {
            $items[] = $item->getPathname();
        }
        if ($items) {
            $this->filesystem->remove($items);
        }
    }

    /**
     * Move all contents from source directory into target directory.
     */
    private function moveDirectoryContents(string $source, string $target): void
    {
        $iterator = new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            $this->filesystem->rename($item->getPathname(), $target . '/' . $item->getFilename());
        }
    }

    /**
     * Get repository URL with embedded credentials for HTTPS repos.
     */
    private function getAuthenticatedRepoUrl(): string
    {
        // If not HTTPS or no credentials, return original URL
        if (!str_starts_with($this->moduleRepo, 'https://') || empty($this->repoUsername) || empty($this->repoPassword)) {
            return $this->moduleRepo;
        }

        // Inject credentials into HTTPS URL
        // https://repo.example.com/path.git -> https://user:pass@repo.example.com/path.git
        $parsed = parse_url($this->moduleRepo);
        if (!$parsed || !isset($parsed['host'])) {
            return $this->moduleRepo;
        }

        $credentials = urlencode($this->repoUsername) . ':' . urlencode($this->repoPassword);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'];
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = $parsed['path'] ?? '';

        return sprintf('%s://%s@%s%s%s', $scheme, $credentials, $host, $port, $path);
    }

    /**
     * Sanitize output to remove credentials from error messages.
     */
    private function sanitizeOutput(string $output): string
    {
        if (!empty($this->repoUsername) && !empty($this->repoPassword)) {
            // Remove credentials from output
            $output = str_replace(
                urlencode($this->repoUsername) . ':' . urlencode($this->repoPassword) . '@',
                '***:***@',
                $output,
            );
            $output = str_replace($this->repoPassword, '***', $output);
        }

        return $output;
    }
}
