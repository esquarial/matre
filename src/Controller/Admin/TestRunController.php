<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\TestRun;
use App\Entity\User;
use App\Form\TestRunType;
use App\Message\TestRunMessage;
use App\Repository\TestRunRepository;
use App\Repository\TestSuiteRepository;
use App\Repository\UserRepository;
use App\Service\AllureStepParserService;
use App\Service\ArtifactCollectorService;
use App\Service\NotificationService;
use App\Service\TestRunnerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/test-runs')]
#[IsGranted('ROLE_ADMIN')]
class TestRunController extends AbstractController
{
    public function __construct(
        private readonly TestRunnerService $testRunnerService,
        private readonly ArtifactCollectorService $artifactCollector,
        private readonly AllureStepParserService $allureStepParser,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly TestRunRepository $testRunRepository,
        private readonly TestSuiteRepository $testSuiteRepository,
        private readonly NotificationService $notificationService,
        private readonly UserRepository $userRepository,
        private readonly string $noVncUrl,
        private readonly string $allurePublicUrl,
    ) {
    }

    #[Route('', name: 'admin_test_run_index', methods: ['GET'])]
    public function index(): Response
    {
        $suites = $this->testSuiteRepository->findAllOrdered();

        return $this->render('admin/test_run/index.html.twig', [
            'suites' => array_map(fn ($s) => ['id' => $s->getId(), 'name' => $s->getName()], $suites),
        ]);
    }

    #[Route('/new', name: 'admin_test_run_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = $this->createForm(TestRunType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $suite = $data['suite'];

            // Derive test type from suite type
            $type = match (true) {
                str_starts_with($suite->getType(), 'mftf') => TestRun::TYPE_MFTF,
                str_starts_with($suite->getType(), 'playwright') => TestRun::TYPE_PLAYWRIGHT,
                default => TestRun::TYPE_MFTF,
            };

            $run = $this->testRunnerService->createRun(
                $data['environment'],
                $type,
                $suite->getTestPattern(),
                $suite,
                TestRun::TRIGGER_MANUAL,
                filter_var($request->request->all('test_run')['sendNotifications'] ?? '1', FILTER_VALIDATE_BOOLEAN),
                $this->getUser(),
            );

            // Dispatch async execution
            $this->messageBus->dispatch(new TestRunMessage(
                $run->getId(),
                $run->getEnvironment()->getId(),
                TestRunMessage::PHASE_PREPARE,
            ));

            $this->addFlash('success', sprintf('Test run #%d started.', $run->getId()));

            return $this->redirectToRoute('admin_test_run_show', ['id' => $run->getId()]);
        }

        return $this->render('admin/test_run/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'admin_test_run_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $run = $this->testRunRepository->find($id);
        if (!$run) {
            $this->addFlash('error', sprintf('Test run #%d does not exist.', $id));

            return $this->redirectToRoute('admin_test_run_index');
        }

        $artifacts = $this->artifactCollector->listArtifacts($run);
        $artifactDisplay = $this->artifactCollector->groupArtifactsForDisplay($artifacts);

        return $this->render('admin/test_run/show.html.twig', [
            'run' => $run,
            'artifacts' => $artifacts,
            'artifactDisplay' => $artifactDisplay,
            'vnc_url' => $this->noVncUrl,
            'allure_public_url' => $this->allurePublicUrl,
        ]);
    }

    #[Route('/{id}/artifacts/{filename}', name: 'admin_test_run_artifact', methods: ['GET'], requirements: ['id' => '\d+', 'filename' => '.+'])]
    public function artifact(int $id, string $filename): Response
    {
        $run = $this->testRunRepository->find($id);
        if (!$run) {
            $this->addFlash('error', sprintf('Test run #%d does not exist.', $id));

            return $this->redirectToRoute('admin_test_run_index');
        }

        // Security: only allow specific extensions
        $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'html', 'htm', 'json'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExtensions, true)) {
            throw $this->createNotFoundException('File type not allowed');
        }

        if (!$this->artifactCollector->artifactExists($run, $filename)) {
            throw $this->createNotFoundException('Artifact not found');
        }

        $filePath = $this->artifactCollector->getArtifactFilePath($run, $filename);

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $filename,
        );

        return $response;
    }

    #[Route('/{id}/cancel', name: 'admin_test_run_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(Request $request, int $id): Response
    {
        $run = $this->testRunRepository->find($id);
        if (!$run) {
            $this->addFlash('error', sprintf('Test run #%d does not exist.', $id));

            return $this->redirectToRoute('admin_test_run_index');
        }

        if ($this->isCsrfTokenValid('cancel' . $run->getId(), $request->request->get('_token'))) {
            if ($run->canBeCancelled()) {
                $this->testRunnerService->cancelRun($run);
                $this->addFlash('success', sprintf('Test run #%d cancelled.', $run->getId()));
            } else {
                $this->addFlash('error', 'Test run cannot be cancelled in current state.');
            }
        } else {
            $this->addFlash('error', 'Invalid CSRF token.');
        }

        return $this->redirectToRoute('admin_test_run_show', ['id' => $run->getId()]);
    }

    #[Route('/{id}/retry', name: 'admin_test_run_retry', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retry(Request $request, int $id): Response
    {
        $run = $this->testRunRepository->find($id);
        if (!$run) {
            $this->addFlash('error', sprintf('Test run #%d does not exist.', $id));

            return $this->redirectToRoute('admin_test_run_index');
        }

        if ($this->isCsrfTokenValid('retry' . $run->getId(), $request->request->get('_token'))) {
            $newRun = $this->testRunnerService->retryRun($run, $this->getUser());

            // Dispatch async execution
            $this->messageBus->dispatch(new TestRunMessage(
                $newRun->getId(),
                $newRun->getEnvironment()->getId(),
                TestRunMessage::PHASE_PREPARE,
            ));

            $this->addFlash('success', sprintf('New test run #%d created from retry.', $newRun->getId()));

            return $this->redirectToRoute('admin_test_run_show', ['id' => $newRun->getId()]);
        }

        $this->addFlash('error', 'Invalid CSRF token.');

        return $this->redirectToRoute('admin_test_run_show', ['id' => $run->getId()]);
    }

    #[Route('/{id}/retry-failed', name: 'admin_test_run_retry_failed', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retryFailed(Request $request, int $id): Response
    {
        $run = $this->testRunRepository->find($id);
        if (!$run) {
            $this->addFlash('error', sprintf('Test run #%d does not exist.', $id));

            return $this->redirectToRoute('admin_test_run_index');
        }

        if ($this->isCsrfTokenValid('retry_failed' . $run->getId(), $request->request->get('_token'))) {
            $newRun = $this->testRunnerService->retryFailedRun($run, $this->getUser());

            if (null === $newRun) {
                $this->addFlash('warning', 'No retryable failures found. Only WebDriver/infrastructure errors can be retried.');

                return $this->redirectToRoute('admin_test_run_show', ['id' => $run->getId()]);
            }

            // Dispatch async execution
            $this->messageBus->dispatch(new TestRunMessage(
                $newRun->getId(),
                $newRun->getEnvironment()->getId(),
                TestRunMessage::PHASE_PREPARE,
            ));

            $retryCount = \count($newRun->getRetryTestIdsArray());
            $this->addFlash('success', sprintf('Retry run #%d created for %d failed test(s).', $newRun->getId(), $retryCount));

            return $this->redirectToRoute('admin_test_run_show', ['id' => $newRun->getId()]);
        }

        $this->addFlash('error', 'Invalid CSRF token.');

        return $this->redirectToRoute('admin_test_run_show', ['id' => $run->getId()]);
    }

    #[Route('/{id}/resend-notification', name: 'admin_test_run_resend_notification', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resendNotification(Request $request, int $id): Response
    {
        $run = $this->testRunRepository->find($id);
        if (!$run) {
            $this->addFlash('error', sprintf('Test run #%d does not exist.', $id));

            return $this->redirectToRoute('admin_test_run_index');
        }

        if (!$this->isCsrfTokenValid('resend_notification' . $run->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('admin_test_run_show', ['id' => $run->getId()]);
        }

        if (!$run->isFinished()) {
            $this->addFlash('error', 'Can only resend notifications for finished runs.');

            return $this->redirectToRoute('admin_test_run_show', ['id' => $run->getId()]);
        }

        $slackSent = false;
        $emailSent = false;

        if ($this->userRepository->shouldSendSlackNotification($run)) {
            $this->notificationService->sendSlackNotification($run);
            $slackSent = true;
        }

        $usersToEmail = $this->userRepository->findUsersToNotifyByEmail($run);
        $recipients = array_map(static fn (User $u) => $u->getEmail(), $usersToEmail);
        if (!empty($recipients)) {
            $this->notificationService->sendEmailNotification($run, $recipients);
            $emailSent = true;
        }

        if ($slackSent || $emailSent) {
            $channels = array_filter(['Slack' => $slackSent, 'Email' => $emailSent], fn ($v) => $v);
            $this->addFlash('success', 'Notification sent via: ' . implode(', ', array_keys($channels)));
        } else {
            $this->addFlash('warning', 'No users subscribed to notifications for this environment.');
        }

        return $this->redirectToRoute('admin_test_run_show', ['id' => $run->getId()]);
    }

    /**
     * Get live output for a running test.
     */
    #[Route('/{id}/live-output', name: 'admin_test_run_live_output', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function liveOutput(int $id): JsonResponse
    {
        $run = $this->testRunRepository->find($id);
        if (!$run) {
            return new JsonResponse([
                'error' => sprintf('Test run #%d does not exist.', $id),
            ], 404);
        }

        // For sequential group runs, return current test output
        $currentTest = $run->getCurrentTestName();
        $output = '';

        if (null !== $currentTest) {
            // Find current test's output file
            $safeFileName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $currentTest);
            $outputPath = $this->getParameter('kernel.project_dir')
                . sprintf('/var/test-output/run-%d/%s.log', $run->getId(), $safeFileName);

            if (file_exists($outputPath)) {
                $output = $this->readTailOfFile($outputPath, 102400);
            }
        } else {
            // Fallback to existing behavior for non-group runs
            $outputPath = $run->getOutputFilePath();
            if ($outputPath && file_exists($outputPath)) {
                $output = $this->readTailOfFile($outputPath, 102400);
            }
        }

        // Build progress string - show current test number (running), not just completed
        $progress = null;
        if (null !== $run->getTotalTests()) {
            $completed = $run->getCompletedTests() ?? 0;
            $total = $run->getTotalTests();
            // If a test is currently running, show that test number (completed + 1)
            $current = null !== $currentTest ? $completed + 1 : $completed;
            $progress = sprintf('%d/%d', $current, $total);
        }

        // Build results array for live display
        $results = [];
        foreach ($run->getResults() as $result) {
            $results[] = [
                'id' => $result->getId(),
                'testName' => $result->getTestName(),
                'status' => $result->getStatus(),
                'duration' => $result->getDuration(),
                'durationFormatted' => $result->getDurationFormatted(),
                'errorMessage' => $result->getErrorMessage(),
                'hasScreenshot' => null !== $result->getScreenshotPath(),
                'hasOutputFile' => null !== $result->getOutputFilePath(),
            ];
        }

        return new JsonResponse([
            'status' => $run->getStatus(),
            'currentTest' => $currentTest,
            'progress' => $progress,
            'output' => $output,
            'resultCounts' => $run->getResultCounts(),
            'results' => $results,
        ]);
    }

    /**
     * Get Allure execution steps for a specific test result.
     */
    #[Route('/{id}/results/{resultId}/steps', name: 'admin_test_run_result_steps', methods: ['GET'], requirements: ['id' => '\d+', 'resultId' => '\d+'])]
    public function getResultSteps(int $id, int $resultId): JsonResponse
    {
        $run = $this->testRunRepository->find($id);
        if (!$run) {
            return new JsonResponse(['error' => 'Test run not found'], 404);
        }

        $result = null;
        foreach ($run->getResults() as $r) {
            if ($r->getId() === $resultId) {
                $result = $r;

                break;
            }
        }

        if (!$result) {
            return new JsonResponse(['error' => 'Test result not found'], 404);
        }

        $steps = $this->allureStepParser->getStepsForResult($result);

        if (!$steps) {
            return new JsonResponse([
                'testName' => $result->getTestName(),
                'status' => $result->getStatus(),
                'duration' => $result->getDuration(),
                'steps' => [],
                'error' => 'Step details unavailable (Allure data not found)',
            ]);
        }

        // Backfill duration from Allure if missing in DB
        if (null === $result->getDuration() && isset($steps['duration']) && null !== $steps['duration']) {
            $result->setDuration($steps['duration']);
            $this->entityManager->flush();
        }

        return new JsonResponse($steps);
    }

    /**
     * Get individual test output for sequential group runs.
     */
    #[Route('/{id}/results/{resultId}/output', name: 'admin_test_run_result_output', methods: ['GET'], requirements: ['id' => '\\d+', 'resultId' => '\\d+'])]
    public function getTestOutput(TestRun $run, int $resultId): JsonResponse
    {
        $result = null;
        foreach ($run->getResults() as $r) {
            if ($r->getId() === $resultId) {
                $result = $r;

                break;
            }
        }

        if (!$result) {
            throw $this->createNotFoundException('Test result not found');
        }

        $outputPath = $result->getOutputFilePath();
        if (!$outputPath || !file_exists($outputPath)) {
            return $this->json(['output' => 'Output file not available']);
        }

        $output = $this->readTailOfFile($outputPath, 1024 * 1024); // 1MB limit

        return $this->json([
            'testName' => $result->getTestName(),
            'status' => $result->getStatus(),
            'output' => $output,
        ]);
    }

    /**
     * Read tail of file to prevent memory issues with large logs.
     */
    private function readTailOfFile(string $path, int $maxBytes): string
    {
        $size = filesize($path);
        if ($size <= $maxBytes) {
            return file_get_contents($path);
        }

        $handle = fopen($path, 'r');
        fseek($handle, -$maxBytes, SEEK_END);
        $content = fread($handle, $maxBytes);
        fclose($handle);

        return '... [truncated - showing last ' . round($maxBytes / 1024) . "KB]\n" . $content;
    }
}
