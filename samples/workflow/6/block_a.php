<?php
declare(strict_types=1);

namespace App\Fraud\Detection;

use App\Domain\Entity\Order;
use App\Domain\Entity\FraudCheck;
use App\Domain\Repository\FraudCheckRepositoryInterface;
use App\Domain\Service\FraudAnalysisServiceInterface;
use App\Domain\Service\OrderServiceInterface;
use App\Domain\Service\NotificationServiceInterface;
use Psr\Log\LoggerInterface;

final readonly class OrderFraudDetectionWorkflow
{
    public function __construct(
        private FraudCheckRepositoryInterface $fraudCheckRepository,
        private FraudAnalysisServiceInterface $fraudAnalysis,
        private OrderServiceInterface $orderService,
        private NotificationServiceInterface $notificationService,
        private LoggerInterface $logger,
    ) {}

    public function analyzeOrder(string $orderId): void
    {
        $order = $this->orderService->findOrder($orderId);
        if ($order === null) {
            throw new \RuntimeException("Order not found: {$orderId}");
        }

        $this->logger->info('Starting fraud detection workflow', ['order_id' => $orderId]);

        $this->createFraudCheck($order);

        $fraudCheck = $this->performRiskAnalysis($order);

        $this->checkVelocityRules($order, $fraudCheck);

        $this->checkDeviceFingerprint($order, $fraudCheck);

        $this->checkGeographicAnomalies($order, $fraudCheck);

        $riskLevel = $this->calculateRiskLevel($fraudCheck);

        $this->updateFraudCheck($fraudCheck, $riskLevel);

        $this->takeActionBasedOnRisk($order, $riskLevel);

        $this->notifyIfNeeded($order, $riskLevel);

        $this->logger->info('Fraud detection workflow completed', [
            'order_id' => $orderId,
            'risk_level' => $riskLevel,
        ]);
    }

    private function createFraudCheck(Order $order): FraudCheck
    {
        $fraudCheck = new FraudCheck();
        $fraudCheck->setOrderId($order->getId());
        $fraudCheck->setCustomerId($order->getCustomerId());
        $fraudCheck->setStatus('in_progress');
        $fraudCheck->setStartedAt(new \DateTimeImmutable());

        $this->fraudCheckRepository->save($fraudCheck);

        $this->logger->debug('Fraud check created', [
            'order_id' => $order->getId()->toString(),
            'fraud_check_id' => $fraudCheck->getId()->toString(),
        ]);

        return $fraudCheck;
    }

    private function performRiskAnalysis(Order $order): FraudCheck
    {
        $riskScore = $this->fraudAnalysis->calculateRiskScore($order);

        $fraudCheck = $this->fraudCheckRepository->findByOrderId($order->getId()->toString());
        $fraudCheck->setRiskScore($riskScore);
        $fraudCheck->setAnalysisDetails([
            'order_amount' => $order->getTotalAmount()->getAmount(),
            'customer_age_days' => $order->getCustomer()->getAccountAgeDays(),
            'order_history_count' => $order->getCustomer()->getOrderCount(),
        ]);

        $this->fraudCheckRepository->save($fraudCheck);

        $this->logger->debug('Risk analysis performed', [
            'order_id' => $order->getId()->toString(),
            'risk_score' => $riskScore,
        ]);

        return $fraudCheck;
    }

    private function checkVelocityRules(Order $order, FraudCheck $fraudCheck): void
    {
        $velocityResult = $this->fraudAnalysis->checkVelocityRules($order);

        $details = $fraudCheck->getAnalysisDetails();
        $details['velocity'] = [
            'passed' => $velocityResult->isPassed(),
            'violations' => $velocityResult->getViolations(),
        ];
        $fraudCheck->setAnalysisDetails($details);

        $this->fraudCheckRepository->save($fraudCheck);

        $this->logger->debug('Velocity rules checked', [
            'order_id' => $order->getId()->toString(),
            'passed' => $velocityResult->isPassed(),
        ]);
    }

    private function checkDeviceFingerprint(Order $order, FraudCheck $fraudCheck): void
    {
        $deviceResult = $this->fraudAnalysis->checkDeviceFingerprint($order);

        $details = $fraudCheck->getAnalysisDetails();
        $details['device'] = [
            'fingerprint_hash' => $deviceResult->getFingerprintHash(),
            'is_suspicious' => $deviceResult->isSuspicious(),
            'risk_indicators' => $deviceResult->getRiskIndicators(),
        ];
        $fraudCheck->setAnalysisDetails($details);

        $this->fraudCheckRepository->save($fraudCheck);

        $this->logger->debug('Device fingerprint checked', [
            'order_id' => $order->getId()->toString(),
            'is_suspicious' => $deviceResult->isSuspicious(),
        ]);
    }

    private function checkGeographicAnomalies(Order $order, FraudCheck $fraudCheck): void
    {
        $geoResult = $this->fraudAnalysis->checkGeographicAnomalies($order);

        $details = $fraudCheck->getAnalysisDetails();
        $details['geographic'] = [
            'billing_country' => $order->getBillingAddress()->getCountry(),
            'shipping_country' => $order->getShippingAddress()->getCountry(),
            'ip_country' => $order->getIpCountry(),
            'is_anomaly' => $geoResult->isAnomaly(),
        ];
        $fraudCheck->setAnalysisDetails($details);

        $this->fraudCheckRepository->save($fraudCheck);

        $this->logger->debug('Geographic anomalies checked', [
            'order_id' => $order->getId()->toString(),
            'is_anomaly' => $geoResult->isAnomaly(),
        ]);
    }

    private function calculateRiskLevel(FraudCheck $fraudCheck): string
    {
        $score = $fraudCheck->getRiskScore();
        $details = $fraudCheck->getAnalysisDetails();

        $riskIndicators = 0;
        if (($details['velocity']['passed'] ?? true) === false) {
            $riskIndicators++;
        }
        if (($details['device']['is_suspicious'] ?? false) === true) {
            $riskIndicators++;
        }
        if (($details['geographic']['is_anomaly'] ?? false) === true) {
            $riskIndicators++;
        }

        if ($score >= 80 || $riskIndicators >= 3) {
            return 'high';
        }
        if ($score >= 50 || $riskIndicators >= 2) {
            return 'medium';
        }
        if ($score >= 30 || $riskIndicators >= 1) {
            return 'low';
        }
        return 'none';
    }

    private function updateFraudCheck(FraudCheck $fraudCheck, string $riskLevel): void
    {
        $fraudCheck->setRiskLevel($riskLevel);
        $fraudCheck->setStatus('completed');
        $fraudCheck->setCompletedAt(new \DateTimeImmutable());

        $this->fraudCheckRepository->save($fraudCheck);
    }

    private function takeActionBasedOnRisk(Order $order, string $riskLevel): void
    {
        $action = match ($riskLevel) {
            'high' => 'block',
            'medium' => 'review',
            'low' => 'flag',
            default => 'allow',
        };

        $this->orderService->updateFraudAction($order->getId()->toString(), $action);

        $this->logger->debug('Fraud action taken', [
            'order_id' => $order->getId()->toString(),
            'action' => $action,
            'risk_level' => $riskLevel,
        ]);
    }

    private function notifyIfNeeded(Order $order, string $riskLevel): void
    {
        if ($riskLevel === 'high' || $riskLevel === 'medium') {
            $this->notificationService->sendToFraudTeam(
                'fraud_alert',
                [
                    'order_id' => $order->getId()->toString(),
                    'risk_level' => $riskLevel,
                    'customer_email' => $order->getCustomer()->getEmail(),
                    'order_amount' => $order->getTotalAmount()->getAmount(),
                ]
            );
        }
    }
}
