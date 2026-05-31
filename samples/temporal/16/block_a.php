<?php
declare(strict_types=1);

namespace Datadog\Metrics\Service;

use Datadog\Metrics\Repository\MetricRepository;
use Datadog\Metrics\Repository\AlertRepository;
use Datadog\Metrics\Entity\Metric;
use Datadog\Metrics\Entity\Alert;
use Datadog\Metrics\Entity\AlertEvaluation;
use Datadog\Metrics\Exception\AlertingException;
use Datadog\Metrics\Service\AggregationService;
use Datadog\Metrics\Service\NotificationPipeline;
use Psr\Log\LoggerInterface;

final class AlertEvaluationService
{
    private MetricRepository $metricRepo;
    private AlertRepository $alertRepo;
    private AggregationService $aggregator;
    private NotificationPipeline $notifications;
    private LoggerInterface $logger;

    public function __construct(
        MetricRepository $metricRepo,
        AlertRepository $alertRepo,
        AggregationService $aggregator,
        NotificationPipeline $notifications,
        LoggerInterface $logger
    ) {
        $this->metricRepo = $metricRepo;
        $this->alertRepo = $alertRepo;
        $this->aggregator = $aggregator;
        $this->notifications = $notifications;
        $this->logger = $logger;
    }

    public function evaluateAlerts(string $monitorId): AlertEvaluationResult
    {
        $this->logger->info('Starting alert evaluation', ['monitor_id' => $monitorId]);

        $monitor = $this->alertRepo->findMonitor($monitorId);
        if ($monitor === null) {
            throw new AlertingException("Monitor not found: {$monitorId}");
        }

        if (!$monitor->isEnabled()) {
            $this->logger->debug('Monitor is disabled, skipping evaluation', ['monitor_id' => $monitorId]);
            return new AlertEvaluationResult([
                'success' => true,
                'evaluated' => false,
                'reason' => 'monitor_disabled'
            ]);
        }

        $lock = $this->alertRepo->acquireEvaluationLock($monitorId);
        if ($lock === null) {
            throw new AlertingException("Could not acquire lock for monitor: {$monitorId}");
        }

        $this->logger->debug('Evaluation lock acquired', ['monitor_id' => $monitorId]);

        try {
            $queryWindow = $monitor->getQueryWindow();
            $metrics = $this->metricRepo->queryMetrics(
                $monitor->getMetricQuery(),
                $queryWindow->getStart(),
                $queryWindow->getEnd()
            );

            if (count($metrics) === 0) {
                $this->alertRepo->releaseEvaluationLock($lock);
                throw new AlertingException("No metrics returned for monitor: {$monitorId}");
            }

            $aggregated = $this->aggregator->aggregate(
                $metrics,
                $monitor->getAggregationMethod()
            );

            $evaluation = AlertEvaluation::create([
                'monitor_id' => $monitorId,
                'evaluated_at' => new \DateTimeImmutable(),
                'metric_count' => count($metrics),
                'aggregated_value' => $aggregated['value'],
                'threshold' => $monitor->getThreshold(),
                'operator' => $monitor->getOperator()
            ]);

            $isTriggered = $this->evaluateCondition(
                $aggregated['value'],
                $monitor->getOperator(),
                $monitor->getThreshold()
            );

            $evaluation->setTriggered($isTriggered);
            $this->alertRepo->saveEvaluation($evaluation);

            $previousState = $monitor->getLastAlertState();
            $newState = $isTriggered ? 'alerting' : 'ok';

            if ($previousState !== $newState) {
                $this->handleStateTransition($monitor, $previousState, $newState, $evaluation);
            }

            $this->alertRepo->updateMonitorState($monitorId, $newState, $evaluation->getId());
            $this->alertRepo->releaseEvaluationLock($lock);

            $this->logger->info('Alert evaluation completed', [
                'monitor_id' => $monitorId,
                'triggered' => $isTriggered,
                'value' => $aggregated['value']
            ]);

            return new AlertEvaluationResult([
                'success' => true,
                'monitor_id' => $monitorId,
                'triggered' => $isTriggered,
                'current_value' => $aggregated['value'],
                'threshold' => $monitor->getThreshold()
            ]);

        } catch (\Throwable $e) {
            $this->alertRepo->releaseEvaluationLock($lock);
            $this->logger->error('Alert evaluation failed', [
                'monitor_id' => $monitorId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function evaluateCondition(float $value, string $operator, float $threshold): bool
    {
        return match ($operator) {
            '>' => $value > $threshold,
            '>=' => $value >= $threshold,
            '<' => $value < $threshold,
            '<=' => $value <= $threshold,
            '==' => abs($value - $threshold) < 0.0001,
            default => false
        };
    }

    private function handleStateTransition($monitor, string $previousState, string $newState, AlertEvaluation $evaluation): void
    {
        $this->alertRepo->recordStateTransition(
            $monitor->getId(),
            $previousState,
            $newState,
            $evaluation->getId()
        );

        if ($newState === 'alerting') {
            $this->notifications->sendAlertNotification(
                $monitor,
                $evaluation
            );

            $alert = Alert::create([
                'monitor_id' => $monitor->getId(),
                'evaluation_id' => $evaluation->getId(),
                'state' => 'firing',
                'triggered_at' => new \DateTimeImmutable()
            ]);

            $this->alertRepo->saveAlert($alert);
        }
    }
}
