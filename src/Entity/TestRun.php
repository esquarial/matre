<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TestRunRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * TestRun Entity.
 *
 * Represents a single test execution run
 * Tracks status, output, and results of MFTF/Playwright tests
 */
#[ORM\Entity(repositoryClass: TestRunRepository::class)]
#[ORM\Table(name: 'matre_test_runs')]
#[ORM\Index(name: 'IDX_TEST_RUN_STATUS', columns: ['status'])]
#[ORM\Index(name: 'IDX_TEST_RUN_ENV', columns: ['environment_id'])]
#[ORM\Index(name: 'IDX_TEST_RUN_CREATED', columns: ['created_at'])]
#[ORM\Index(name: 'IDX_TEST_RUN_ENV_STATUS', columns: ['environment_id', 'status'])]
#[ORM\Index(name: 'IDX_TEST_RUN_SUITE', columns: ['suite_id'])]
#[ORM\Index(name: 'IDX_TEST_RUN_EXECUTED_BY', columns: ['executed_by_id'])]
#[ORM\Index(name: 'IDX_TEST_RUN_ORIGINAL', columns: ['original_run_id'])]
#[ORM\HasLifecycleCallbacks]
class TestRun
{
    public const TYPE_MFTF = 'mftf';
    public const TYPE_PLAYWRIGHT = 'playwright';
    public const TYPE_BOTH = 'both';

    public const TYPES = [
        self::TYPE_MFTF => 'MFTF',
        self::TYPE_PLAYWRIGHT => 'Playwright',
        self::TYPE_BOTH => 'Both',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_CLONING = 'cloning';
    public const STATUS_RUNNING = 'running';
    public const STATUS_REPORTING = 'reporting';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_WAITING = 'waiting';

    public const STATUSES = [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_PREPARING => 'Preparing',
        self::STATUS_CLONING => 'Cloning Module',
        self::STATUS_WAITING => 'Waiting for Lock',
        self::STATUS_RUNNING => 'Running Tests',
        self::STATUS_REPORTING => 'Generating Report',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_FAILED => 'Failed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    public const TRIGGERED_BY_SCHEDULER = 'scheduler';
    public const TRIGGERED_BY_MANUAL = 'manual';
    public const TRIGGERED_BY_API = 'api';

    // Aliases for cleaner API
    public const TRIGGER_MANUAL = self::TRIGGERED_BY_MANUAL;
    public const TRIGGER_SCHEDULER = self::TRIGGERED_BY_SCHEDULER;
    public const TRIGGER_API = self::TRIGGERED_BY_API;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TestEnvironment::class)]
    #[ORM\JoinColumn(name: 'environment_id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Environment is required.')]
    private TestEnvironment $environment;

    #[ORM\ManyToOne(targetEntity: TestSuite::class)]
    #[ORM\JoinColumn(name: 'suite_id', nullable: true, onDelete: 'SET NULL')]
    private ?TestSuite $suite = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'executed_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $executedBy = null;

    #[ORM\ManyToOne(targetEntity: TestRun::class)]
    #[ORM\JoinColumn(name: 'original_run_id', nullable: true, onDelete: 'SET NULL')]
    private ?TestRun $originalRun = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $retryAttempt = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $retryTestIds = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [self::TYPE_MFTF, self::TYPE_PLAYWRIGHT, self::TYPE_BOTH])]
    private string $type;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = self::STATUS_PENDING;

    /**
     * Optional filter for specific tests or groups.
     * For MFTF: test name like "MOEC1625" or group like "pricing"
     * For Playwright: grep pattern like "@smoke".
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $testFilter = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    #[Assert\Choice(choices: [self::TRIGGERED_BY_SCHEDULER, self::TRIGGERED_BY_MANUAL, self::TRIGGERED_BY_API])]
    private string $triggeredBy = self::TRIGGERED_BY_MANUAL;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $sendNotifications = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notificationSentAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    /**
     * Console output from test execution (truncated to 100KB).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $output = null;

    /**
     * Process ID for async execution tracking.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $processPid = null;

    /**
     * Path to output file for async execution.
     */
    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $outputFilePath = null;

    /**
     * Current test name for sequential group execution.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $currentTestName = null;

    /**
     * Total number of tests in sequential group execution.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $totalTests = null;

    /**
     * Number of completed tests in sequential group execution.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $completedTests = null;

    /**
     * @var Collection<int, TestResult>
     */
    #[ORM\OneToMany(targetEntity: TestResult::class, mappedBy: 'testRun', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $results;

    /**
     * @var Collection<int, TestReport>
     */
    #[ORM\OneToMany(targetEntity: TestReport::class, mappedBy: 'testRun', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $reports;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->results = new ArrayCollection();
        $this->reports = new ArrayCollection();
    }

    public function __toString(): string
    {
        return sprintf('Run #%d (%s)', $this->id ?? 0, $this->environment->getName());
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEnvironment(): TestEnvironment
    {
        return $this->environment;
    }

    public function setEnvironment(TestEnvironment $environment): static
    {
        $this->environment = $environment;

        return $this;
    }

    public function getSuite(): ?TestSuite
    {
        return $this->suite;
    }

    public function setSuite(?TestSuite $suite): static
    {
        $this->suite = $suite;

        return $this;
    }

    public function getExecutedBy(): ?User
    {
        return $this->executedBy;
    }

    public function setExecutedBy(?User $executedBy): static
    {
        $this->executedBy = $executedBy;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getTypeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStatusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getTestFilter(): ?string
    {
        return $this->testFilter;
    }

    public function setTestFilter(?string $testFilter): static
    {
        $this->testFilter = $testFilter;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function getTriggeredBy(): string
    {
        return $this->triggeredBy;
    }

    public function setTriggeredBy(string $triggeredBy): static
    {
        $this->triggeredBy = $triggeredBy;

        return $this;
    }

    public function isSendNotifications(): bool
    {
        return $this->sendNotifications;
    }

    public function setSendNotifications(bool $sendNotifications): static
    {
        $this->sendNotifications = $sendNotifications;

        return $this;
    }

    public function getNotificationSentAt(): ?\DateTimeImmutable
    {
        return $this->notificationSentAt;
    }

    public function markNotificationSent(): static
    {
        $this->notificationSentAt = new \DateTimeImmutable();

        return $this;
    }

    public function wasNotificationSent(): bool
    {
        return null !== $this->notificationSentAt;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getOutput(): ?string
    {
        return $this->output;
    }

    public function setOutput(?string $output): static
    {
        // Truncate to 100KB
        if (null !== $output && mb_strlen($output) > 102400) {
            $output = mb_substr($output, 0, 102400) . "\n... [truncated]";
        }
        $this->output = $output;

        return $this;
    }

    public function getProcessPid(): ?int
    {
        return $this->processPid;
    }

    public function setProcessPid(?int $processPid): static
    {
        $this->processPid = $processPid;

        return $this;
    }

    public function getOutputFilePath(): ?string
    {
        return $this->outputFilePath;
    }

    public function setOutputFilePath(?string $outputFilePath): static
    {
        $this->outputFilePath = $outputFilePath;

        return $this;
    }

    /**
     * Check if this run has an active async process.
     */
    public function hasActiveProcess(): bool
    {
        return null !== $this->processPid && $this->isRunning();
    }

    public function appendOutput(string $text): static
    {
        $current = $this->output ?? '';
        $this->setOutput($current . $text);

        return $this;
    }

    /**
     * @return Collection<int, TestResult>
     */
    public function getResults(): Collection
    {
        return $this->results;
    }

    public function addResult(TestResult $result): static
    {
        if (!$this->results->contains($result)) {
            $this->results->add($result);
            $result->setTestRun($this);
        }

        return $this;
    }

    public function removeResult(TestResult $result): static
    {
        $this->results->removeElement($result);

        return $this;
    }

    /**
     * @return Collection<int, TestReport>
     */
    public function getReports(): Collection
    {
        return $this->reports;
    }

    public function addReport(TestReport $report): static
    {
        if (!$this->reports->contains($report)) {
            $this->reports->add($report);
            $report->setTestRun($this);
        }

        return $this;
    }

    public function removeReport(TestReport $report): static
    {
        $this->reports->removeElement($report);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getOriginalRun(): ?TestRun
    {
        return $this->originalRun;
    }

    public function setOriginalRun(?TestRun $originalRun): static
    {
        $this->originalRun = $originalRun;

        return $this;
    }

    public function getRetryAttempt(): int
    {
        return $this->retryAttempt;
    }

    public function setRetryAttempt(int $retryAttempt): static
    {
        $this->retryAttempt = $retryAttempt;

        return $this;
    }

    public function getRetryTestIds(): ?string
    {
        return $this->retryTestIds;
    }

    public function setRetryTestIds(?string $retryTestIds): static
    {
        $this->retryTestIds = $retryTestIds;

        return $this;
    }

    /**
     * @return string[]
     */
    public function getRetryTestIdsArray(): array
    {
        if (null === $this->retryTestIds) {
            return [];
        }

        $decoded = json_decode($this->retryTestIds, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param string[] $testIds
     */
    public function setRetryTestIdsFromArray(array $testIds): static
    {
        $this->retryTestIds = empty($testIds) ? null : json_encode(array_values($testIds));

        return $this;
    }

    public function isRetry(): bool
    {
        return null !== $this->originalRun;
    }

    public function isRetryOfFailed(): bool
    {
        return null !== $this->originalRun && null !== $this->retryTestIds;
    }

    /**
     * Check if run is in a terminal state.
     */
    public function isFinished(): bool
    {
        return \in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }

    /**
     * Check if run is currently executing.
     */
    public function isRunning(): bool
    {
        return \in_array($this->status, [
            self::STATUS_PREPARING,
            self::STATUS_CLONING,
            self::STATUS_WAITING,
            self::STATUS_RUNNING,
            self::STATUS_REPORTING,
        ], true);
    }

    /**
     * Check if run can be cancelled.
     */
    public function canBeCancelled(): bool
    {
        return !$this->isFinished();
    }

    /**
     * Get duration in seconds.
     */
    public function getDuration(): ?int
    {
        if (null === $this->startedAt) {
            return null;
        }

        $endTime = $this->completedAt ?? new \DateTimeImmutable();

        return $endTime->getTimestamp() - $this->startedAt->getTimestamp();
    }

    /**
     * Get formatted duration string.
     */
    public function getDurationFormatted(): ?string
    {
        $duration = $this->getDuration();
        if (null === $duration) {
            return null;
        }

        $hours = floor($duration / 3600);
        $minutes = floor(($duration % 3600) / 60);
        $seconds = $duration % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
        }

        if ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $seconds);
        }

        return sprintf('%ds', $seconds);
    }

    /**
     * Get result counts.
     *
     * @return array{passed: int, failed: int, skipped: int, broken: int, total: int}
     */
    public function getResultCounts(): array
    {
        $counts = [
            'passed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'broken' => 0,
            'total' => 0,
        ];

        foreach ($this->results as $result) {
            ++$counts['total'];
            $status = $result->getStatus();
            if (isset($counts[$status])) {
                ++$counts[$status];
            }
        }

        return $counts;
    }

    /**
     * Mark run as execution started (sets RUNNING status).
     */
    public function markExecutionStarted(): static
    {
        $this->startedAt = new \DateTimeImmutable();
        $this->status = self::STATUS_RUNNING;

        return $this;
    }

    /**
     * Mark run as completed.
     */
    public function markCompleted(): static
    {
        $this->completedAt = new \DateTimeImmutable();
        $this->status = self::STATUS_COMPLETED;
        $this->errorMessage = null;

        return $this;
    }

    /**
     * Mark run as failed.
     */
    public function markFailed(string $errorMessage): static
    {
        $this->completedAt = new \DateTimeImmutable();
        $this->status = self::STATUS_FAILED;
        $this->errorMessage = $errorMessage;

        return $this;
    }

    /**
     * Mark run as cancelled.
     */
    public function markCancelled(): static
    {
        $this->completedAt = new \DateTimeImmutable();
        $this->status = self::STATUS_CANCELLED;

        return $this;
    }

    public function getCurrentTestName(): ?string
    {
        return $this->currentTestName;
    }

    public function setCurrentTestName(?string $currentTestName): static
    {
        $this->currentTestName = $currentTestName;

        return $this;
    }

    public function getTotalTests(): ?int
    {
        return $this->totalTests;
    }

    public function getCompletedTests(): ?int
    {
        return $this->completedTests;
    }

    public function setProgress(int $completed, int $total): static
    {
        $this->completedTests = $completed;
        $this->totalTests = $total;

        return $this;
    }
}
