<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Entity\Report;
use App\Repository\ReportRepository;
use App\Service\ReportGenerator;
use Psr\Log\LoggerInterface;

final class ReportGenerationService
{
    public function __construct(
        private readonly ReportRepository $reportRepository,
        private readonly ReportGenerator $reportGenerator,
        private readonly LoggerInterface $logger,
    ) {}

    public function generateReport(int $reportId, int $userId): Report
    {
        $report = $this->reportRepository->findById($reportId);
        $user = $this->loadUser($userId);

        if ($report === null) {
            throw new \RuntimeException('Report not found');
        }

        if ($user === null) {
            throw new \RuntimeException('User not found');
        }

        if ($user->getSubscriptionTier() !== 'premium' && $user->getSubscriptionTier() !== 'enterprise') {
            throw new \InvalidArgumentException('Generating reports requires premium or enterprise subscription');
        }

        if ($user->getSubscriptionTier() === 'premium' && $user->getGeneratedReportsThisMonth() >= 20) {
            throw new \InvalidArgumentException('Premium users can generate up to 20 reports per month');
        }

        if ($user->getSubscriptionTier() === 'enterprise' && $user->getGeneratedReportsThisMonth() >= 200) {
            throw new \InvalidArgumentException('Enterprise users can generate up to 200 reports per month');
        }

        if ($report->getStatus() === 'generated') {
            throw new \InvalidArgumentException('Report has already been generated');
        }

        if ($report->getStatus() === 'expired') {
            throw new \InvalidArgumentException('Cannot generate expired report');
        }

        if (trim($report->getName()) === '') {
            throw new \InvalidArgumentException('Report must have a name');
        }

        $generatedData = $this->reportGenerator->generate($report);

        $report->setStatus('generated');
        $report->setGeneratedAt(new \DateTimeImmutable());
        $report->setGeneratedBy($userId);
        $report->setData($generatedData);

        $user->incrementGeneratedReportsThisMonth();
        $this->userRepository->save($user);
        $this->reportRepository->save($report);

        $this->logger->info('Report generated successfully', [
            'report_id' => $reportId,
            'user_id' => $userId,
            'tier' => $user->getSubscriptionTier(),
        ]);

        return $report;
    }

    public function shareReport(int $reportId, int $userId, array $shareWithUserIds): Report
    {
        $report = $this->reportRepository->findById($reportId);
        $user = $this->loadUser($userId);

        if ($report === null || $user === null) {
            throw new \RuntimeException('Report or user not found');
        }

        if ($user->getSubscriptionTier() !== 'enterprise') {
            throw new \InvalidArgumentException('Sharing reports requires enterprise subscription');
        }

        if ($report->getStatus() !== 'generated') {
            throw new \InvalidArgumentException('Can only share generated reports');
        }

        if ($user->getSubscriptionTier() !== 'premium' && $user->getSubscriptionTier() !== 'enterprise') {
            throw new \InvalidArgumentException('Sharing requires premium or enterprise subscription');
        }

        if ($user->getSubscriptionTier() === 'premium' && count($shareWithUserIds) > 5) {
            throw new \InvalidArgumentException('Premium users can share with up to 5 users');
        }

        if ($user->getSubscriptionTier() === 'enterprise' && count($shareWithUserIds) > 50) {
            throw new \InvalidArgumentException('Enterprise users can share with up to 50 users');
        }

        $report->addSharedUsers($shareWithUserIds);
        $this->reportRepository->save($report);

        $this->logger->info('Report shared successfully', [
            'report_id' => $reportId,
            'user_id' => $userId,
            'shared_with_count' => count($shareWithUserIds),
        ]);

        return $report;
    }

    public function exportReport(int $reportId, int $userId, string $format): string
    {
        $report = $this->reportRepository->findById($reportId);
        $user = $this->loadUser($userId);

        if ($report === null || $user === null) {
            throw new \RuntimeException('Report or user not found');
        }

        if ($user->getSubscriptionTier() !== 'premium' && $user->getSubscriptionTier() !== 'enterprise') {
            throw new \InvalidArgumentException('Exporting reports requires premium or enterprise subscription');
        }

        if ($report->getStatus() !== 'generated') {
            throw new \InvalidArgumentException('Can only export generated reports');
        }

        if (!in_array($format, ['pdf', 'excel', 'csv', 'json'], true)) {
            throw new \InvalidArgumentException('Invalid export format');
        }

        return $this->reportGenerator->export($report, $format);
    }

    private function loadUser(int $userId): ?User
    {
        return $this->userRepository->findById($userId);
    }
}
