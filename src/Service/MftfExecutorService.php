<?php

declare(strict_types=1);

namespace App\Service;

use App\Constants\ErrorIds;
use App\Entity\TestResult;
use App\Entity\TestRun;
use App\Repository\GlobalEnvVariableRepository;
use App\Service\Security\ShellEscapeService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Executes MFTF tests via Magento container.
 */
class MftfExecutorService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly GlobalEnvVariableRepository $globalEnvVariableRepository,
        private readonly ShellEscapeService $shellEscapeService,
        private readonly MagentoContainerPoolService $containerPool,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $projectDir,
        private readonly string $seleniumHost,
        private readonly int $seleniumPort,
        private readonly string $magentoRoot,
        private readonly string $testModulePath = 'app/code/SiiPoland/Catalog',
        private readonly int $webDriverConnectionTimeout = 30,
        private readonly int $webDriverRequestTimeout = 120,
        private readonly int $webDriverPageLoadTimeout = 60,
        private readonly int $webDriverWaitTimeout = 90,
    ) {
    }

    /**
     * Stop a running MFTF process inside the Magento container for this run.
     */
    public function stopRun(TestRun $run): void
    {
        $this->terminateRunProcess($run);
    }

    /**
     * Execute MFTF tests for a test run.
     * Output is streamed to a file to prevent memory bloat on long-running tests.
     *
     * @param callable|null $lockRefreshCallback Optional callback to refresh environment lock during execution
     * @param callable|null $heartbeatCallback   Optional callback to extend message redelivery window
     *
     * @return array{output: string, exitCode: int}
     */
    public function execute(TestRun $run, ?callable $lockRefreshCallback = null, ?callable $heartbeatCallback = null): array
    {
        $this->logger->info('Executing MFTF tests', [
            'runId' => $run->getId(),
            'filter' => $run->getTestFilter(),
        ]);

        // Build the MFTF command
        $mftfCommand = $this->buildCommand($run);

        // Create output file for streaming (prevents memory bloat)
        $outputFile = $this->getOutputFilePath($run);
        $outputDir = dirname($outputFile);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0o755, true);
        }

        // Store output path on run for async tracking
        $run->setOutputFilePath($outputFile);

        // Get per-environment container (enables parallel execution)
        $container = $this->containerPool->getContainerForEnvironment($run->getEnvironment());

        // Execute via docker
        // Force ANSI color output for better styled logs
        $process = new Process([
            'docker', 'exec',
            '-e', 'TERM=xterm-256color',
            $container,
            'bash', '-c',
            $mftfCommand,
        ]);
        $process->setTimeout(3600); // 1 hour timeout

        // Stream output to file with cancellation and lock refresh support
        $handle = fopen($outputFile, 'w');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Failed to open output file for writing: %s', $outputFile));
        }
        $process->start(function ($type, $buffer) use ($handle) {
            fwrite($handle, $buffer);
        });

        // Poll for completion with cancellation check, lock refresh, and heartbeat
        $this->pollProcessWithHeartbeat($process, $run, $handle, $lockRefreshCallback, $heartbeatCallback);

        // Read final output (truncated for entity storage)
        $output = $this->readOutputFile($outputFile);

        $this->logger->info('MFTF execution completed', [
            'runId' => $run->getId(),
            'exitCode' => $process->getExitCode(),
            'outputFile' => $outputFile,
        ]);

        return [
            'output' => $output,
            'exitCode' => $process->getExitCode() ?? 1,
        ];
    }

    /**
     * Execute a single MFTF test with dedicated output file.
     *
     * Used for sequential group execution where each test gets its own output.
     *
     * @param callable|null $lockRefreshCallback Optional callback to refresh environment lock during execution
     * @param callable|null $heartbeatCallback   Optional callback to extend message redelivery window
     *
     * @return array{output: string, exitCode: int, outputFilePath: string}
     */
    public function executeSingleTest(TestRun $run, string $testName, ?callable $lockRefreshCallback = null, ?callable $heartbeatCallback = null): array
    {
        $this->logger->info('Executing single MFTF test', [
            'runId' => $run->getId(),
            'testName' => $testName,
        ]);

        // Use full testName for unique file (avoid collisions)
        $safeFileName = $this->shellEscapeService->sanitizeFilename($testName);
        $outputFile = sprintf(
            '%s/var/test-output/run-%d/%s.log',
            $this->projectDir,
            $run->getId(),
            $safeFileName,
        );

        // Ensure directory exists
        $dir = dirname($outputFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        // Build command with test name override
        $mftfCommand = $this->buildCommand($run, $testName);

        // Get per-environment container (enables parallel execution)
        $container = $this->containerPool->getContainerForEnvironment($run->getEnvironment());

        // Execute via Docker (same pattern as execute())
        $process = new Process([
            'docker', 'exec',
            '-e', 'TERM=xterm-256color',
            $container,
            'bash', '-c',
            $mftfCommand,
        ]);
        $process->setTimeout(3600); // 1 hour timeout per test

        // Stream to per-test file with cancellation and lock refresh support
        $handle = fopen($outputFile, 'w');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Failed to open output file for writing: %s', $outputFile));
        }
        $process->start(function ($type, $buffer) use ($handle) {
            fwrite($handle, $buffer);
        });

        // Poll for completion with cancellation check, lock refresh, and heartbeat
        $this->pollProcessWithHeartbeat($process, $run, $handle, $lockRefreshCallback, $heartbeatCallback, $testName);

        $this->logger->info('Single test execution completed', [
            'runId' => $run->getId(),
            'testName' => $testName,
            'exitCode' => $process->getExitCode(),
            'outputFile' => $outputFile,
        ]);

        return [
            'output' => $this->readOutputFile($outputFile),
            'exitCode' => $process->getExitCode() ?? 1,
            'outputFilePath' => $outputFile,
        ];
    }

    /**
     * Get output file path for a test run.
     */
    public function getOutputFilePath(TestRun $run): string
    {
        return $this->projectDir . '/var/test-output/mftf-run-' . $run->getId() . '.log';
    }

    /**
     * Build MFTF command string.
     *
     * SECURITY: All environment variable names and values are validated and escaped
     * to prevent command injection attacks.
     */
    public function buildCommand(TestRun $run, ?string $testNameOverride = null): string
    {
        // Use override for sequential execution, otherwise from run
        $filter = $testNameOverride ?? $run->getTestFilter();
        if (empty($filter)) {
            throw new \InvalidArgumentException('MFTF requires a test filter (test name or group). Please specify --filter option.');
        }

        $runId = $run->getId();
        $parts = [];

        // Create symlink from mounted TestModule to expected Magento path
        // Docker mounts var/test-modules/current -> /var/www/html/app/code/TestModule
        // We symlink TestModule -> app/code/SiiPoland/Catalog (as defined by TEST_MODULE_PATH)
        $modulePath = $this->magentoRoot . '/' . $this->testModulePath;
        $mountedModulePath = $this->magentoRoot . '/app/code/TestModule';

        // Create parent directory and symlink
        $parts[] = sprintf('mkdir -p %s', escapeshellarg(dirname($modulePath)));
        $parts[] = sprintf('rm -rf %s', escapeshellarg($modulePath)); // Remove existing
        $parts[] = sprintf('ln -sf %s %s', escapeshellarg($mountedModulePath), escapeshellarg($modulePath));

        // Copy module _data files (CSV fixtures etc.) to central MFTF _data directory
        $acceptanceDir = $this->magentoRoot . '/dev/tests/acceptance';
        $parts[] = sprintf('if [ -d %s/Test/Mftf/_data ]; then cp -n %s/Test/Mftf/_data/* %s/tests/_data/; fi', escapeshellarg($mountedModulePath), escapeshellarg($mountedModulePath), escapeshellarg($acceptanceDir));

        // Change to acceptance test directory
        $parts[] = 'cd ' . escapeshellarg($acceptanceDir);

        // Bootstrap: copy clean codeception.yml to per-container tmpfs (isolated per environment)
        // 1. Create .base from the real file on first run (works in all modes: pool, local dev, reused containers)
        // 2. Copy .base to per-container config dir for isolated patching
        $parts[] = 'mkdir -p codeception-config';
        $parts[] = 'if [ ! -f codeception.yml.base ] && [ -f codeception.yml ] && [ ! -L codeception.yml ]; then'
            . ' cp codeception.yml codeception.yml.base; fi';
        $parts[] = 'if [ -f codeception.yml.base ]; then cp codeception.yml.base codeception-config/codeception.yml'
            . '; else echo "ERROR: No clean codeception.yml found (no .base and no real codeception.yml)" >&2; exit 1; fi';

        // Ensure WebDriver timeouts are applied in codeception.yml (per-run, safe defaults)
        $perRunOutputDir = 'allure-results/run-' . $runId;
        $codeceptionPatch = file_get_contents(__DIR__ . '/Resources/codeception-patch.php.tpl');
        $parts[] = sprintf(
            'php -r %s',
            escapeshellarg(sprintf(
                $codeceptionPatch,
                $this->webDriverConnectionTimeout,
                $this->webDriverRequestTimeout,
                $this->webDriverPageLoadTimeout,
                $this->webDriverWaitTimeout,
                $perRunOutputDir,
            )),
        );

        // Symlink shared-volume codeception.yml to per-container tmpfs copy
        $parts[] = 'ln -sf codeception-config/codeception.yml codeception.yml';

        // Build .env file with layered configuration:
        // 1. Global variables (shared across all environments)
        // 2. Module's Cron/data/.env.{env-name} (test-specific data, ~200 vars)
        // 3. TestEnvironment entity values (can override)
        // 4. Infrastructure overrides (Selenium)
        $env = $run->getEnvironment();
        $envFileName = '.env.' . $env->getCode(); // e.g., .env.stage-us
        $moduleEnvFile = $mountedModulePath . '/Cron/data/' . $envFileName;

        // Per-environment config directory (tmpfs mount - isolated per container)
        $envConfigDir = $acceptanceDir . '/env-config';
        $mftfEnvFile = $envConfigDir . '/.env';

        // Create env-config dir and symlink to expected .env location
        $parts[] = sprintf('mkdir -p %s', escapeshellarg($envConfigDir));
        $parts[] = sprintf('ln -sf %s %s', escapeshellarg($mftfEnvFile), escapeshellarg($acceptanceDir . '/.env'));
        $parts[] = sprintf(
            'test -s %1$s || (echo %2$s >&2; exit 1)',
            escapeshellarg($moduleEnvFile),
            escapeshellarg(sprintf('Missing module environment file: %s', $moduleEnvFile)),
        );

        // Layer 1: Start with global + environment-specific variables from database
        // SECURITY: Validate and escape all variables to prevent command injection
        $globalVars = $this->globalEnvVariableRepository->getAllAsKeyValue($env->getCode());
        if (!empty($globalVars)) {
            $globalContent = "# Global variables (from ATR database)\n";
            foreach ($globalVars as $key => $value) {
                try {
                    // SECURITY: Validate variable name and build safe env file line
                    $globalContent .= $this->shellEscapeService->buildEnvFileLine($key, $value) . "\n";
                } catch (\InvalidArgumentException $e) {
                    $this->logger->error('Invalid environment variable detected', [
                        'key' => $key,
                        'error' => $e->getMessage(),
                        'runId' => $run->getId(),
                        'errorId' => ErrorIds::MFTF_ENV_VAR_INVALID,
                    ]);

                    throw new \RuntimeException(sprintf('Test run aborted: invalid environment variable "%s": %s', $key, $e->getMessage()));
                }
            }
            $parts[] = sprintf('echo %s > %s', escapeshellarg($globalContent), escapeshellarg($mftfEnvFile));
            $parts[] = sprintf('cat %s >> %s', escapeshellarg($moduleEnvFile), escapeshellarg($mftfEnvFile));
        } else {
            $parts[] = sprintf('cp %s %s', escapeshellarg($moduleEnvFile), escapeshellarg($mftfEnvFile));
        }

        // Layer 2: Override Selenium configuration (ATR infra)
        // SECURITY: Use secure building for selenium configuration
        $seleniumHostLine = $this->shellEscapeService->buildEnvFileLine('SELENIUM_HOST', $this->seleniumHost);
        $seleniumPortLine = $this->shellEscapeService->buildEnvFileLine('SELENIUM_PORT', (string) $this->seleniumPort);
        $parts[] = sprintf('echo %s >> %s', escapeshellarg($seleniumHostLine), escapeshellarg($mftfEnvFile));
        $parts[] = sprintf('echo %s >> %s', escapeshellarg($seleniumPortLine), escapeshellarg($mftfEnvFile));

        // Layer 3: Override MFTF wait timeout (align with WebDriver wait)
        $waitTimeoutLine = $this->shellEscapeService->buildEnvFileLine('WAIT_TIMEOUT', (string) $this->webDriverWaitTimeout);
        $parts[] = sprintf('echo %s >> %s', escapeshellarg($waitTimeoutLine), escapeshellarg($mftfEnvFile));

        // Create per-run Allure output directory before MFTF runs
        // (codeception.yml outputDirectory is set via PHP patch earlier in this command)
        $allureOutputPath = $this->magentoRoot . '/dev/tests/acceptance/allure-results/run-' . $runId;
        $parts[] = sprintf('mkdir -p %s', escapeshellarg($allureOutputPath));

        // Clean downloads directory before each run (shared volume with Chrome nodes)
        $downloadsPath = $this->magentoRoot . '/dev/tests/acceptance/tests/_data/downloads';
        $parts[] = sprintf('rm -rf %s/* && mkdir -p %s && chmod 777 %s', escapeshellarg($downloadsPath), escapeshellarg($downloadsPath), escapeshellarg($downloadsPath));

        // Generate credentials file from env variables (MFTF requires this for _CREDS references)
        $credentialsFile = $envConfigDir . '/.credentials';
        $parts[] = $this->buildCredentialsCommand($mftfEnvFile, $credentialsFile);
        // Symlink credentials to expected location
        $parts[] = sprintf('ln -sf %s %s', escapeshellarg($credentialsFile), escapeshellarg($acceptanceDir . '/.credentials'));

        // Purge _generated contents before MFTF generation.
        // Stale processes from previous runs can lock this dir ("Resource busy" on rmdir),
        // preventing MFTF from regenerating PHP test files from XML — causes stale code to execute.
        // Use find -delete to remove contents only (preserves the directory itself, which may be a tmpfs mount).
        $generatedDir = $acceptanceDir . '/tests/functional/Magento/_generated';
        $parts[] = sprintf('find %1$s -mindepth 1 -depth -delete 2>/dev/null; mkdir -p %1$s', escapeshellarg($generatedDir));

        // MFTF binary path
        $mftfBin = $this->magentoRoot . '/vendor/bin/mftf';

        // Always use run:test - sequential group execution replaces run:group
        $mftfCommand = 'run:test';

        $mftfParts = [$mftfBin . ' ' . $mftfCommand];
        $mftfParts[] = escapeshellarg($filter);
        $mftfParts[] = '-f'; // force flag (skip -r/remove to avoid rmdir EBUSY on tmpfs _generated mount)

        $mftfParts[] = '--ansi'; // Force colored output

        $pidFile = $this->getPidFilePath($runId);
        $parts[] = sprintf('rm -f %s', escapeshellarg($pidFile));
        $parts[] = sprintf('(%s) & echo $! > %s; wait $!', implode(' ', $mftfParts), escapeshellarg($pidFile));

        // Build command chain with && for dependencies
        $mainCommand = implode(' && ', $parts);

        // After MFTF execution, move screenshots to per-run directory to prevent
        // cross-run contamination when multiple runs execute in parallel.
        // Use ; to run regardless of MFTF exit code (we want artifacts even on failure)
        // NOTE: Allure results are NOT moved here - they're in a separate Docker mount
        // and AllureReportService handles copying them to per-run directory.
        $runOutputDir = 'tests/_output/run-' . $runId;
        $moveCommands = [
            'mkdir -p ' . escapeshellarg($runOutputDir),
            'find tests/_output -maxdepth 1 -type f \( -name "*.png" -o -name "*.jpg" -o -name "*.gif" -o -name "*.html" \) -exec mv {} ' . escapeshellarg($runOutputDir) . '/ \; || true',
            'rm -f ' . escapeshellarg($pidFile) . ' || true',
        ];

        // Run cleanup regardless of MFTF result, but preserve original command exit code.
        return sprintf('(%s); main_rc=$?; %s; exit $main_rc', $mainCommand, implode(' && ', $moveCommands));
    }

    /**
     * Parse MFTF output to extract test results.
     *
     * Handles Codeception output format where test results appear as:
     * - Test header: "MOEC5157Cest: Moec5157"
     * - Test steps
     * - Result: "PASSED" or "FAIL" on its own line
     *
     * @return TestResult[]
     */
    public function parseResults(TestRun $run, string $output, ?string $outputFilePath = null): array
    {
        $results = [];

        // Strip ANSI escape codes for clean parsing
        // Handles both \x1b[...m (ESC sequences) and [...m (raw brackets from logs)
        $parseOutput = $this->getOutputForParsing($output, $outputFilePath);
        $cleanOutput = preg_replace('/\x1b\[[0-9;]*m|\[[0-9;]*m/', '', $parseOutput);

        // Pattern to match test blocks in Codeception output
        // Format: "TestCest: MethodName" ... (steps) ... "PASSED/FAIL"
        $pattern = '/^([A-Z][A-Za-z0-9]+Cest):\s*(\w+).*?^\s*(PASSED|FAIL|ERROR|SKIP)\s*$/ms';

        if (preg_match_all($pattern, $cleanOutput, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $result = new TestResult();
                $result->setTestRun($run);
                $result->setTestName($match[1] . ':' . $match[2]); // e.g., "MOEC5157Cest:Moec5157"
                $result->setStatus($this->mapStatus($match[3]));

                // Extract test ID from Cest name (e.g., MOEC5157Cest -> MOEC5157)
                $testId = $this->extractStrictTestIdFromCest($match[1]);
                if ($testId) {
                    $result->setTestId($testId);
                }

                // For failed/error tests, extract error details from the matched block
                if (\in_array($match[3], ['FAIL', 'ERROR'], true)) {
                    $errorMsg = $this->extractErrorFromBlock($match[0]);
                    if ($errorMsg) {
                        $result->setErrorMessage($errorMsg);
                    }
                }

                $results[] = $result;
            }
        }

        // Fallback: Parse from Signature lines if block matching fails
        if (empty($results)) {
            $results = $this->parseResultsFallback($run, $cleanOutput);
        }

        // If no results parsed, check for explicit success/failure indicators
        if (empty($results)) {
            $testId = null;

            // Extract test ID from output or use filter
            $testId = $this->extractStrictTestIdFromCest($cleanOutput);
            if (!$testId && ($filter = $run->getTestFilter())) {
                $testId = $this->extractStrictTestId($filter);
            }
            // Don't use raw filter as testId - it could be a group name like "us"

            // Check for Codeception definitive failure FIRST — FAIL/Failures/ERRORS means
            // the test ran but assertions/actions failed (STATUS_FAILED).
            // Only fall through to hasErrorIndicators for infrastructure issues (STATUS_BROKEN).
            $hasDefinitiveFailure = preg_match('/^\s*FAIL\s*$/m', $cleanOutput)
                || preg_match('/Failures:\s*([1-9]\d*)/i', $cleanOutput)
                || preg_match('/ERRORS!\s*$/m', $cleanOutput);

            if ($hasDefinitiveFailure) {
                $result = new TestResult();
                $result->setTestRun($run);
                $result->setTestName($testId ?? 'Unknown');
                if ($testId) {
                    $result->setTestId($testId);
                }
                $result->setStatus(TestResult::STATUS_FAILED);
                $result->setErrorMessage($this->extractErrorSummary($cleanOutput));
                $results[] = $result;
            } elseif ($this->hasErrorIndicators($cleanOutput)) {
                $result = new TestResult();
                $result->setTestRun($run);
                $result->setTestName($testId ?? 'Unknown');
                if ($testId) {
                    $result->setTestId($testId);
                }
                $result->setStatus(TestResult::STATUS_BROKEN);
                $result->setErrorMessage($this->extractErrorSummary($cleanOutput));
                $results[] = $result;
            } elseif (preg_match('/OK\s*\(\d+\s*test/', $cleanOutput)) {
                // Definitive success: "OK (X tests..." - test passed
                $result = new TestResult();
                $result->setTestRun($run);
                $result->setTestName($testId ?? 'Unknown');
                if ($testId) {
                    $result->setTestId($testId);
                }
                $result->setStatus(TestResult::STATUS_PASSED);
                $results[] = $result;
            }
        }

        // Extract duration from timing line and apply to results
        if (preg_match('/Time:\s*([0-9:.]+)/', $cleanOutput, $timeMatch)) {
            $this->applyDurationToResults($results, $timeMatch[1]);
        }

        return $results;
    }

    /**
     * Get path to MFTF output directory.
     */
    public function getOutputPath(): string
    {
        return $this->projectDir . '/var/mftf-results';
    }

    /**
     * Get path to Allure results from MFTF for a specific run.
     *
     * Each run writes to isolated per-run directory via ALLURE_OUTPUT_PATH env var.
     * Volume mount syncs container path to host path automatically.
     */
    public function getAllureResultsPath(int $runId): string
    {
        return $this->projectDir . '/var/mftf-results/allure-results/run-' . $runId;
    }

    public function extractErrorSummary(string $output, int $maxLines = 5): string
    {
        $cleanOutput = preg_replace('/\x1b\[[0-9;]*m|\[[0-9;]*m/', '', $output) ?? $output;
        $lines = preg_split('/\R/', $cleanOutput) ?: [];

        $interesting = [];
        $interestingPattern = '/ERROR:|Missing module environment file:|Fatal error:|Uncaught exception|failed to generate|not available under|\[.*Exception\]/i';

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if ('' === $trimmed || !preg_match($interestingPattern, $trimmed)) {
                continue;
            }

            $interesting[] = $trimmed;

            if (preg_match('/^\[.*Exception\]$/', $trimmed) && isset($lines[$index + 1])) {
                $nextLine = trim($lines[$index + 1]);
                if ('' !== $nextLine) {
                    $interesting[] = $nextLine;
                }
            }

            if (count($interesting) >= $maxLines) {
                break;
            }
        }

        if (empty($interesting)) {
            $interesting = array_values(array_filter(array_map(
                static fn (string $line): string => trim($line),
                $lines,
            )));
            $interesting = array_slice($interesting, 0, $maxLines);
        }

        $summary = implode("\n", array_slice($interesting, 0, $maxLines));

        return mb_substr($summary, 0, 2000);
    }

    /**
     * Poll a running process with lock refresh, heartbeat, and cancellation support.
     *
     * Owns fclose($handle) via try/finally — callers must not close the handle after calling this.
     *
     * @param resource $handle Output file handle
     */
    protected function pollProcessWithHeartbeat(
        Process $process,
        TestRun $run,
        $handle,
        ?callable $lockRefreshCallback,
        ?callable $heartbeatCallback,
        ?string $testName = null,
    ): void {
        $lastRefresh = time();
        $lastHeartbeat = time();
        $lockRefreshFailures = 0;
        $heartbeatFailures = 0;
        $refreshInterval = 30;
        $heartbeatInterval = 300;
        $maxLockRefreshFailures = 5;
        $lastCancellationCheck = time();
        $cancellationCheckInterval = 5;
        $cancellationRefreshFailures = 0;
        $processStartTime = time();
        $maxWallClockTimeout = 4200; // 70 min: safety net above the 60 min process timeout

        try {
            while ($process->isRunning()) {
                usleep(500000);
                $now = time();

                // Wall-clock safety net: kills process if isRunning() timeout mechanism is blocked
                if (($now - $processStartTime) > $maxWallClockTimeout) {
                    $this->logger->error('Wall-clock timeout exceeded, force-killing process', [
                        'runId' => $run->getId(),
                        'testName' => $testName,
                        'elapsed' => $now - $processStartTime,
                        'processRunning' => $process->isRunning(),
                    ]);
                    $process->stop(10);

                    throw new \RuntimeException(sprintf('Wall-clock timeout exceeded (%ds) for test %s', $maxWallClockTimeout, $testName ?? 'unknown'));
                }

                if (($now - $lastCancellationCheck) >= $cancellationCheckInterval) {
                    $this->checkCancellation($run, $process, $cancellationRefreshFailures);
                    $lastCancellationCheck = $now;
                }

                // Refresh environment lock to prevent expiration during long-running tests
                if ($lockRefreshCallback && ($now - $lastRefresh) >= $refreshInterval) {
                    $lastRefresh = $now; // Always update — prevents immediate re-attempt on failure

                    try {
                        $lockRefreshCallback();
                        $lockRefreshFailures = 0;
                    } catch (\Exception $e) {
                        // Retry once after 2s (handles transient Docker DNS blips)
                        usleep(2_000_000);

                        try {
                            $lockRefreshCallback();
                            $lockRefreshFailures = 0;
                            $this->logger->info('Lock refresh succeeded on retry', [
                                'runId' => $run->getId(),
                                'testName' => $testName,
                                'originalError' => $e->getMessage(),
                            ]);
                        } catch (\Exception $retryException) {
                            ++$lockRefreshFailures;
                            if ($lockRefreshFailures >= $maxLockRefreshFailures) {
                                $this->logger->error('Aborting test run - lock refresh persistently failing', [
                                    'runId' => $run->getId(),
                                    'testName' => $testName,
                                    'error' => $retryException->getMessage(),
                                    'consecutiveFailures' => $lockRefreshFailures,
                                ]);
                                $process->stop();

                                throw new \RuntimeException('Lock refresh failed - aborting to prevent parallel execution conflicts');
                            }
                            $this->logger->warning("Lock refresh failed (will retry in {$refreshInterval}s)", [
                                'runId' => $run->getId(),
                                'testName' => $testName,
                                'error' => $retryException->getMessage(),
                                'consecutiveFailures' => $lockRefreshFailures,
                            ]);
                        }
                    }
                }

                // Heartbeat: extend message redelivery window during long-running tests
                if ($heartbeatCallback && ($now - $lastHeartbeat) >= $heartbeatInterval) {
                    try {
                        $heartbeatCallback();
                        $lastHeartbeat = $now;
                        $heartbeatFailures = 0;
                    } catch (\Exception $e) {
                        ++$heartbeatFailures;
                        if ($heartbeatFailures >= 3) {
                            $this->logger->error('Persistent heartbeat failure - message may be redelivered', [
                                'runId' => $run->getId(),
                                'testName' => $testName,
                                'error' => $e->getMessage(),
                                'consecutiveFailures' => $heartbeatFailures,
                            ]);
                        } else {
                            $this->logger->warning('Heartbeat failed', [
                                'runId' => $run->getId(),
                                'testName' => $testName,
                                'error' => $e->getMessage(),
                                'consecutiveFailures' => $heartbeatFailures,
                            ]);
                        }
                    }
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Extract strict testId token from arbitrary text.
     */
    private function extractStrictTestId(string $text): ?string
    {
        if (preg_match('/\b([A-Z]{2,10}-?\d{2,6}[A-Z]{0,3})\b/i', $text, $matches)) {
            return $this->normalizeTestId($matches[1]);
        }

        return null;
    }

    /**
     * Extract strict testId from a Cest class name or text containing "Cest".
     */
    private function extractStrictTestIdFromCest(string $text): ?string
    {
        if (preg_match('/([A-Z]{2,10}-?\d{2,6}[A-Z]{0,3})Cest/i', $text, $matches)) {
            return $this->normalizeTestId($matches[1]);
        }

        $trimmed = preg_replace('/Cest$/i', '', $text);
        if ($trimmed && $trimmed !== $text) {
            $fromTrimmed = $this->extractStrictTestId($trimmed);
            if ($fromTrimmed) {
                return $fromTrimmed;
            }
        }

        return $this->extractStrictTestId($text);
    }

    /**
     * Normalize testId for strict matching (preserves suffix like US/ES).
     */
    private function normalizeTestId(string $testId): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $testId));
    }

    /**
     * Check if run was cancelled and stop process if so.
     *
     * @throws \RuntimeException if cancelled
     */
    private function checkCancellation(TestRun $run, Process $process, int &$consecutiveFailures = 0): void
    {
        try {
            $this->entityManager->refresh($run);
            $consecutiveFailures = 0;
        } catch (\Throwable $e) {
            ++$consecutiveFailures;
            $this->logger->warning('checkCancellation refresh failed', [
                'runId' => $run->getId(),
                'error' => $e->getMessage(),
                'emOpen' => $this->entityManager->isOpen(),
                'consecutiveFailures' => $consecutiveFailures,
            ]);

            if ($consecutiveFailures >= 5) {
                $this->logger->error('Cancellation check persistently failing', [
                    'runId' => $run->getId(),
                    'consecutiveFailures' => $consecutiveFailures,
                ]);
                $process->stop(10);

                throw new \RuntimeException('EntityManager unrecoverable - cannot check cancellation');
            }

            return;
        }

        if (TestRun::STATUS_CANCELLED === $run->getStatus()) {
            $this->logger->info('Test run cancelled, stopping MFTF process', [
                'runId' => $run->getId(),
                'pid' => $process->getPid(),
            ]);

            $this->terminateRunProcess($run);
            $process->stop(10); // Give 10 seconds for graceful shutdown

            throw new \RuntimeException('Test run was cancelled');
        }
    }

    private function terminateRunProcess(TestRun $run): void
    {
        $pidFile = $this->getPidFilePath((int) $run->getId());
        // Use getContainerNameForEnvironment to avoid container creation side effects
        $container = $this->containerPool->getContainerNameForEnvironment($run->getEnvironment());

        $killCommand = sprintf(
            'if [ -f %1$s ]; then pid=$(cat %1$s); ' .
            'if [ -n "$pid" ]; then ' .
            'kill -TERM -- -"$pid" 2>/dev/null || true; kill -TERM "$pid" 2>/dev/null || true; ' .
            'sleep 2; ' .
            'kill -KILL -- -"$pid" 2>/dev/null || true; kill -KILL "$pid" 2>/dev/null || true; ' .
            'fi; ' .
            'rm -f %1$s; fi',
            escapeshellarg($pidFile),
        );

        $process = new Process([
            'docker', 'exec',
            $container,
            'bash', '-c',
            $killCommand,
        ]);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->warning('Failed to terminate MFTF process', [
                'runId' => $run->getId(),
                'error' => $process->getErrorOutput(),
            ]);
        }
    }

    /**
     * Check if output contains error indicators that suggest test failure.
     *
     * Codeception summary format:
     * - Success: "OK (1 test, 10 assertions)"
     * - Failure: "ERRORS!" or "Tests: X, Assertions: Y, Errors: Z" (where Z > 0)
     *
     * IMPORTANT: Check for errors FIRST because "PASSED" can appear for individual
     * steps even when the overall test fails (e.g., cleanup errors in _after hook).
     */
    private function hasErrorIndicators(string $output): bool
    {
        // FIRST: Check for definitive error summary from Codeception
        // This takes priority over individual "PASSED" lines
        $definitiveErrorPatterns = [
            '/ERRORS!\s*$/m',                            // Codeception error marker
            '/Errors:\s*([1-9]\d*)/i',                   // "Errors: N" where N > 0
            '/Failures:\s*([1-9]\d*)/i',                 // "Failures: N" where N > 0
        ];

        foreach ($definitiveErrorPatterns as $pattern) {
            if (preg_match($pattern, $output)) {
                return true; // Definitive failure, regardless of "PASSED" lines
            }
        }

        // Check for definitive success - "OK (X tests..." means all tests passed
        if (preg_match('/OK\s*\(\d+\s*test/', $output)) {
            return false; // Definitive success
        }

        // Exclude known infrastructure/cleanup exceptions that don't indicate test failure
        $excludedPatterns = [
            '/In AllureHelper\.php.*?unlink\(.*?Cest\.php\).*?(?=\nrun \[)/s', // AllureHelper unlink block + stack trace
            '/In AllureHelper\.php.*?\[Exception\].*?(?=\nrun \[)/s',          // AllureHelper generic exception block
            '/Warning:\s*rmdir\([^)]*_generated[^)]*\):\s*Resource busy[^\n]*/i', // tmpfs mount rmdir warning
        ];

        foreach ($excludedPatterns as $pattern) {
            if (preg_match($pattern, $output)) {
                // Check if this is the ONLY exception - if so, not a real error
                $output = preg_replace($pattern, '', $output);
            }
        }

        // Check for other error patterns
        $errorPatterns = [
            '/\[.*Exception\]/',                        // Any exception (FastFailException, etc)
            '/Fatal error:/i',                          // PHP fatal error
            '/Uncaught exception/i',                    // Uncaught exception
            '/failed to generate/i',                    // MFTF generation failure
            '/Step\s+\[.*?\]\s+.*?FAIL/s',              // Step failure without proper result line
            '/^\s*FAIL\s*$/m',                          // Standalone FAIL line
        ];

        foreach ($errorPatterns as $pattern) {
            if (preg_match($pattern, $output)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read output file content (truncated to prevent memory issues).
     */
    private function readOutputFile(string $path, int $maxBytes = 102400): string
    {
        if (!file_exists($path)) {
            return '';
        }

        $size = filesize($path);
        if (false === $size || $size <= $maxBytes) {
            $content = @file_get_contents($path);
            if (false === $content) {
                $this->logger->error('Failed to read output file', ['path' => $path]);

                return '';
            }

            return $content;
        }

        // Read last N bytes for large files
        $handle = fopen($path, 'r');
        if (false === $handle) {
            $this->logger->error('Failed to open output file for reading', ['path' => $path]);

            return '';
        }
        fseek($handle, -$maxBytes, SEEK_END);
        $content = '... [truncated - showing last ' . round($maxBytes / 1024) . "KB]\n" . fread($handle, $maxBytes);
        fclose($handle);

        return $content;
    }

    /**
     * Get output suitable for parsing test names/statuses.
     * Prefer head+tail of the full output file to avoid truncation issues.
     */
    private function getOutputForParsing(string $fallback, ?string $outputFilePath): string
    {
        if (!$outputFilePath || !file_exists($outputFilePath)) {
            return $fallback;
        }

        $parsed = $this->readOutputFileForParsing($outputFilePath);

        return '' !== $parsed ? $parsed : $fallback;
    }

    /**
     * Read head+tail of output file to preserve test headers and summaries.
     */
    private function readOutputFileForParsing(string $path, int $headBytes = 200000, int $tailBytes = 200000): string
    {
        $size = filesize($path);
        if (false === $size) {
            return '';
        }

        if ($size <= $headBytes + $tailBytes) {
            $content = @file_get_contents($path);
            if (false === $content) {
                $this->logger->error('Failed to read output file for parsing', ['path' => $path]);

                return '';
            }

            return $content;
        }

        $handle = fopen($path, 'r');
        if (false === $handle) {
            $this->logger->error('Failed to open output file for parsing', ['path' => $path]);

            return '';
        }

        $head = fread($handle, $headBytes) ?: '';
        fseek($handle, -$tailBytes, SEEK_END);
        $tail = fread($handle, $tailBytes) ?: '';
        fclose($handle);

        return $head . "\n... [truncated for parsing]\n" . $tail;
    }

    /**
     * Fallback parser when block matching fails.
     * Uses Signature lines to identify tests and OK/FAILURES summary for status.
     *
     * @return TestResult[]
     */
    private function parseResultsFallback(TestRun $run, string $output): array
    {
        $results = [];

        // Look for Signature lines to get test names
        $testNames = [];
        if (preg_match_all('/Signature:\s*[^\s]+\\\\([A-Z][A-Za-z0-9]+Cest):(\w+)/m', $output, $sigMatches, PREG_SET_ORDER)) {
            foreach ($sigMatches as $m) {
                $testNames[] = ['cest' => $m[1], 'method' => $m[2]];
            }
        }

        // Count results from summary
        $passedCount = 0;
        $failedCount = 0;

        // Check for OK summary: "OK (1 test, 7 assertions)"
        if (preg_match('/OK\s*\((\d+)\s*test/', $output, $okMatch)) {
            $passedCount = (int) $okMatch[1];
        }
        // Check for failures summary: "Tests: 5, Failures: 2"
        if (preg_match('/Tests:\s*(\d+).*?Failures:\s*(\d+)/s', $output, $failMatch)) {
            $failedCount = (int) $failMatch[2];
            $passedCount = (int) $failMatch[1] - $failedCount;
        }

        // Create results from parsed data
        foreach ($testNames as $i => $test) {
            $result = new TestResult();
            $result->setTestRun($run);
            $result->setTestName($test['cest'] . ':' . $test['method']);

            // Determine status based on position vs counts
            $status = ($i < $passedCount) ? TestResult::STATUS_PASSED : TestResult::STATUS_FAILED;
            $result->setStatus($status);

            $testId = $this->extractStrictTestIdFromCest($test['cest']);
            if ($testId) {
                $result->setTestId($testId);
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Apply duration from timing line to results.
     * Distributes total time evenly if multiple tests.
     *
     * @param TestResult[] $results
     */
    private function applyDurationToResults(array $results, string $timeStr): void
    {
        // Parse time format "MM:SS.mmm" or "SS.mmm"
        $parts = explode(':', $timeStr);
        $seconds = 0.0;
        if (2 === count($parts)) {
            $seconds = (int) $parts[0] * 60 + (float) $parts[1];
        } else {
            $seconds = (float) $parts[0];
        }

        // Distribute evenly if multiple tests
        $perTest = count($results) > 0 ? $seconds / count($results) : 0;
        foreach ($results as $result) {
            $result->setDuration($perTest);
        }
    }

    /**
     * Build command to generate MFTF credentials file from env variables.
     *
     * MFTF uses _CREDS suffix in tests to reference credentials from .credentials file.
     * This extracts relevant variables from .env and formats them for MFTF.
     *
     * Supports MAGENTO_API_AUTH_USERNAME and MAGENTO_API_AUTH_PASSWORD for separate
     * API authentication when SSO browser login uses different credentials.
     */
    private function buildCredentialsCommand(string $envFile, string $credentialsFile): string
    {
        // Extract password/secret variables from .env and format for .credentials
        // Format: magento/VAR_NAME=value
        // If MAGENTO_API_AUTH_USERNAME/PASSWORD is set, use it for MAGENTO_ADMIN_USERNAME/PASSWORD in credentials
        // This allows SSO browser login to use different credentials than REST API auth
        $script = <<<'BASH'
            (
                echo "# MFTF credentials auto-generated from .env"
                echo "magento/tfa/OTP_SHARED_SECRET=ABCDEFGHIJKLMNOP"

                # Check if API auth overrides exist
                API_AUTH_USER=$(grep -E "^MAGENTO_API_AUTH_USERNAME=" "$1" 2>/dev/null | cut -d'=' -f2- | sed "s/^['\"]//;s/['\"]$//")
                API_AUTH_PASS=$(grep -E "^MAGENTO_API_AUTH_PASSWORD=" "$1" 2>/dev/null | cut -d'=' -f2- | sed "s/^['\"]//;s/['\"]$//")

                # Extract all PASSWORD, SECRET, KEY, USERNAME variables from .env
                grep -E "^(MAGENTO_ADMIN|MAGENTO_TEST|.*PASSWORD|.*SECRET|.*KEY)=" "$1" 2>/dev/null | while IFS='=' read -r key value; do
                    # Remove quotes from value
                    value=$(echo "$value" | sed "s/^['\"]//;s/['\"]$//")

                    # Use API auth username for MAGENTO_ADMIN_USERNAME if override exists
                    if [ "$key" = "MAGENTO_ADMIN_USERNAME" ] && [ -n "$API_AUTH_USER" ]; then
                        value="$API_AUTH_USER"
                    fi

                    # Use API auth password for MAGENTO_ADMIN_PASSWORD if override exists
                    if [ "$key" = "MAGENTO_ADMIN_PASSWORD" ] && [ -n "$API_AUTH_PASS" ]; then
                        value="$API_AUTH_PASS"
                    fi

                    # Skip the API auth variables themselves
                    if [ "$key" = "MAGENTO_API_AUTH_USERNAME" ] || [ "$key" = "MAGENTO_API_AUTH_PASSWORD" ]; then
                        continue
                    fi

                    echo "magento/${key}=${value}"
                done
            ) > "$2"
            BASH;

        return sprintf(
            'bash -c %s -- %s %s',
            escapeshellarg($script),
            escapeshellarg($envFile),
            escapeshellarg($credentialsFile),
        );
    }

    private function mapStatus(string $codeceptStatus): string
    {
        return match (strtoupper($codeceptStatus)) {
            'PASSED' => TestResult::STATUS_PASSED,
            'FAIL' => TestResult::STATUS_FAILED,
            'ERROR' => TestResult::STATUS_BROKEN,
            'SKIP' => TestResult::STATUS_SKIPPED,
            default => TestResult::STATUS_BROKEN,
        };
    }

    /**
     * Extract error details from a matched test block for failed/error tests.
     */
    /** Map WebDriver error descriptions to their exception class names. */
    private const WEBDRIVER_ERROR_MAP = [
        'element not interactable' => 'ElementNotInteractableException',
        'element click intercepted' => 'ElementClickInterceptedException',
        'javascript error:' => 'JavascriptErrorException',
        'timed out after' => 'TimeoutException',
        'timed out waiting' => 'TimeoutException',
        'stale element reference' => 'StaleElementReferenceException',
    ];

    private function extractErrorFromBlock(string $block): ?string
    {
        // Look for WebDriver/Codeception exception class names
        $pattern = '/(?:Facebook\\\\WebDriver\\\\Exception\\\\\w+Exception|Codeception\\\\.+?Exception|Assert\w*Exception)[:\s].*$/m';
        if (preg_match($pattern, $block, $match)) {
            return mb_substr(trim($match[0]), 0, 2000);
        }

        // Look for common error descriptions and tag with exception class when possible
        $infraPattern = '/(?:element not interactable|element click intercepted|javascript error:|timed out (?:after|waiting)|stale element reference)[^\n]*/i';
        if (preg_match($infraPattern, $block, $match)) {
            $desc = trim($match[0]);
            $exceptionClass = $this->resolveWebDriverExceptionClass($desc);
            $prefix = $exceptionClass ? $exceptionClass . ': ' : '';

            return mb_substr($prefix . $desc, 0, 2000);
        }

        // Assertion failures (never tagged — these are genuine test failures)
        if (preg_match('/(?:Failed asserting|Expected .+? but got)[^\n]*/i', $block, $match)) {
            return mb_substr(trim($match[0]), 0, 2000);
        }

        return null;
    }

    private function resolveWebDriverExceptionClass(string $description): ?string
    {
        $lower = strtolower($description);
        foreach (self::WEBDRIVER_ERROR_MAP as $needle => $exceptionClass) {
            if (str_contains($lower, $needle)) {
                return $exceptionClass;
            }
        }

        return null;
    }

    private function getPidFilePath(int $runId): string
    {
        return $this->magentoRoot . '/dev/tests/acceptance/.mftf-run-' . $runId . '.pid';
    }
}
