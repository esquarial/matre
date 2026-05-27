<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\TestRun;
use App\Repository\TestRunRepository;
use App\Service\TestRunnerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:report:generate',
    description: 'Manually generate an Allure report for a completed test run',
)]
class GenerateReportCommand extends Command
{
    public function __construct(
        private readonly TestRunRepository $testRunRepository,
        private readonly TestRunnerService $testRunnerService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('run-id', InputArgument::REQUIRED, 'The test run ID to generate a report for')
            ->setHelp(
                <<<'HELP'
                    The <info>%command.name%</info> command generates an Allure report for a completed test run:

                        <info>php %command.full_name% 42</info>

                    This is useful when auto-report generation is disabled for individual runs.
                    The run must be in a terminal state (completed or failed).
                    HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $runId = (int) $input->getArgument('run-id');
        $run = $this->testRunRepository->find($runId);

        if (!$run) {
            $io->error(sprintf('Test run #%d not found.', $runId));

            return Command::FAILURE;
        }

        $terminalStatuses = [TestRun::STATUS_COMPLETED, TestRun::STATUS_FAILED];
        if (!in_array($run->getStatus(), $terminalStatuses, true)) {
            $io->error(sprintf(
                'Test run #%d is in "%s" state. Only completed or failed runs can have reports generated.',
                $runId,
                $run->getStatus(),
            ));

            return Command::FAILURE;
        }

        $io->info(sprintf('Generating report for test run #%d...', $runId));

        try {
            $report = $this->testRunnerService->generateReports($run);

            $io->success(sprintf('Report generated for test run #%d.', $runId));

            if ($report->getPublicUrl()) {
                $io->writeln(sprintf('Report URL: %s', $report->getPublicUrl()));
            }
        } catch (\Throwable $e) {
            $io->error(sprintf('Report generation failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
