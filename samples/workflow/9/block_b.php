<?php
declare(strict_types=1);

namespace App\Etl\Pipeline;

use App\Domain\Entity\PipelineRun;
use App\Domain\Repository\PipelineRunRepositoryInterface;
use App\Domain\Service\DataExtractorInterface;
use App\Domain\Service\DataTransformerInterface;
use App\Domain\Service\DataLoaderInterface;
use App\Domain\Service\ValidationServiceInterface;
use Psr\Log\LoggerInterface;

final readonly class OrderDataPipeline
{
    public function __construct(
        private PipelineRunRepositoryInterface $pipelineRunRepository,
        private DataExtractorInterface $extractor,
        private DataTransformerInterface $transformer,
        private DataLoaderInterface $loader,
        private ValidationServiceInterface $validation,
        private LoggerInterface $logger,
    ) {}

    public function execute(string $pipelineRunId): void
    {
        $pipelineRun = $this->pipelineRunRepository->findById($pipelineRunId);
        if ($pipelineRun === null) {
            throw new \RuntimeException("Pipeline run not found: {$pipelineRunId}");
        }

        $this->logger->info('Starting order data pipeline', ['pipeline_run_id' => $pipelineRunId]);

        $this->updatePipelineStatus($pipelineRun, 'running');

        try {
            $this->extractData($pipelineRun);

            $this->validateExtractedData($pipelineRun);

            $this->transformData($pipelineRun);

            $this->validateTransformedData($pipelineRun);

            $this->loadData($pipelineRun);

            $this->finalizePipeline($pipelineRun, 'success');

            $this->logger->info('Order data pipeline completed successfully', [
                'pipeline_run_id' => $pipelineRunId,
                'records_processed' => $pipelineRun->getRecordsProcessed(),
            ]);
        } catch (\Throwable $e) {
            $this->handlePipelineFailure($pipelineRun, $e);
            throw $e;
        }
    }

    private function extractData(PipelineRun $pipelineRun): void
    {
        $this->logger->debug('Starting data extraction', ['pipeline_run_id' => $pipelineRun->getId()->toString()]);

        $sourceConfig = $pipelineRun->getSourceConfig();

        $extractedData = $this->extractor->extract($sourceConfig);

        if (!$extractedData->isSuccessful()) {
            $this->recordPipelineError($pipelineRun, 'extraction_failed', $extractedData->getError());
            throw new \RuntimeException("Data extraction failed: {$extractedData->getError()}");
        }

        $pipelineRun->setExtractedData($extractedData->getData());
        $pipelineRun->setExtractedRecordCount($extractedData->getRecordCount());
        $pipelineRun->setExtractionDurationMs($extractedData->getDurationMs());

        $this->pipelineRunRepository->save($pipelineRun);

        $this->recordPipelineProgress($pipelineRun, 'data_extracted', [
            'record_count' => $extractedData->getRecordCount(),
            'duration_ms' => $extractedData->getDurationMs(),
        ]);

        $this->logger->debug('Data extraction completed', [
            'pipeline_run_id' => $pipelineRun->getId()->toString(),
            'record_count' => $extractedData->getRecordCount(),
        ]);
    }

    private function validateExtractedData(PipelineRun $pipelineRun): void
    {
        $this->logger->debug('Validating extracted data', ['pipeline_run_id' => $pipelineRun->getId()->toString()]);

        $validationResult = $this->validation->validateExtractedData(
            $pipelineRun->getExtractedData(),
            $this->getOrderValidationRules()
        );

        if (!$validationResult->isValid()) {
            $this->recordPipelineError($pipelineRun, 'validation_failed', $validationResult->getErrors());
            throw new \RuntimeException("Data validation failed: " . implode(', ', $validationResult->getErrors()));
        }

        $pipelineRun->setValidationDetails($validationResult->getDetails());

        $this->recordPipelineProgress($pipelineRun, 'extracted_data_validated', [
            'valid_count' => $validationResult->getValidCount(),
            'invalid_count' => $validationResult->getInvalidCount(),
        ]);

        $this->logger->debug('Extracted data validation passed', [
            'pipeline_run_id' => $pipelineRun->getId()->toString(),
        ]);
    }

    private function transformData(PipelineRun $pipelineRun): void
    {
        $this->logger->debug('Starting data transformation', ['pipeline_run_id' => $pipelineRun->getId()->toString()]);

        $transformedData = $this->transformer->transform(
            $pipelineRun->getExtractedData(),
            $this->getOrderTransformRules()
        );

        if (!$transformedData->isSuccessful()) {
            $this->recordPipelineError($pipelineRun, 'transformation_failed', $transformedData->getError());
            throw new \RuntimeException("Data transformation failed: {$transformedData->getError()}");
        }

        $pipelineRun->setTransformedData($transformedData->getData());
        $pipelineRun->setTransformedRecordCount($transformedData->getRecordCount());
        $pipelineRun->setTransformationDurationMs($transformedData->getDurationMs());

        $this->pipelineRunRepository->save($pipelineRun);

        $this->recordPipelineProgress($pipelineRun, 'data_transformed', [
            'record_count' => $transformedData->getRecordCount(),
            'duration_ms' => $transformedData->getDurationMs(),
        ]);

        $this->logger->debug('Data transformation completed', [
            'pipeline_run_id' => $pipelineRun->getId()->toString(),
            'record_count' => $transformedData->getRecordCount(),
        ]);
    }

    private function validateTransformedData(PipelineRun $pipelineRun): void
    {
        $this->logger->debug('Validating transformed data', ['pipeline_run_id' => $pipelineRun->getId()->toString()]);

        $validationResult = $this->validation->validateTransformedData(
            $pipelineRun->getTransformedData(),
            $this->getOrderValidationRules()
        );

        if (!$validationResult->isValid()) {
            $this->recordPipelineError($pipelineRun, 'transformed_validation_failed', $validationResult->getErrors());
            throw new \RuntimeException("Transformed data validation failed: " . implode(', ', $validationResult->getErrors()));
        }

        $pipelineRun->setTransformedValidationDetails($validationResult->getDetails());

        $this->recordPipelineProgress($pipelineRun, 'transformed_data_validated', [
            'valid_count' => $validationResult->getValidCount(),
            'invalid_count' => $validationResult->getInvalidCount(),
        ]);

        $this->logger->debug('Transformed data validation passed', [
            'pipeline_run_id' => $pipelineRun->getId()->toString(),
        ]);
    }

    private function loadData(PipelineRun $pipelineRun): void
    {
        $this->logger->debug('Starting data load', ['pipeline_run_id' => $pipelineRun->getId()->toString()]);

        $destinationConfig = $pipelineRun->getDestinationConfig();

        $loadResult = $this->loader->load(
            $pipelineRun->getTransformedData(),
            $destinationConfig
        );

        if (!$loadResult->isSuccessful()) {
            $this->recordPipelineError($pipelineRun, 'load_failed', $loadResult->getError());
            throw new \RuntimeException("Data load failed: {$loadResult->getError()}");
        }

        $pipelineRun->setLoadedRecordCount($loadResult->getLoadedCount());
        $pipelineRun->setFailedRecordCount($loadResult->getFailedCount());
        $pipelineRun->setLoadDurationMs($loadResult->getDurationMs());

        $this->pipelineRunRepository->save($pipelineRun);

        $this->recordPipelineProgress($pipelineRun, 'data_loaded', [
            'loaded_count' => $loadResult->getLoadedCount(),
            'failed_count' => $loadResult->getFailedCount(),
            'duration_ms' => $loadResult->getDurationMs(),
        ]);

        $this->logger->debug('Data load completed', [
            'pipeline_run_id' => $pipelineRun->getId()->toString(),
            'loaded_count' => $loadResult->getLoadedCount(),
        ]);
    }

    private function finalizePipeline(PipelineRun $pipelineRun, string $status): void
    {
        $pipelineRun->setStatus($status);
        $pipelineRun->setCompletedAt(new \DateTimeImmutable());
        $pipelineRun->setTotalDurationMs(
            $pipelineRun->getExtractionDurationMs() +
            $pipelineRun->getTransformationDurationMs() +
            $pipelineRun->getLoadDurationMs()
        );

        $this->pipelineRunRepository->save($pipelineRun);

        $this->recordPipelineProgress($pipelineRun, 'pipeline_completed', [
            'total_records_processed' => $pipelineRun->getRecordsProcessed(),
            'total_duration_ms' => $pipelineRun->getTotalDurationMs(),
        ]);
    }

    private function handlePipelineFailure(PipelineRun $pipelineRun, \Throwable $e): void
    {
        $this->recordPipelineError($pipelineRun, 'pipeline_failed', $e->getMessage());

        $pipelineRun->setStatus('failed');
        $pipelineRun->setErrorMessage($e->getMessage());
        $pipelineRun->setCompletedAt(new \DateTimeImmutable());

        $this->pipelineRunRepository->save($pipelineRun);

        $this->logger->error('Pipeline failed', [
            'pipeline_run_id' => $pipelineRun->getId()->toString(),
            'error' => $e->getMessage(),
        ]);
    }

    private function recordPipelineProgress(PipelineRun $pipelineRun, string $stage, array $data = []): void
    {
        $this->logger->info("Pipeline progress: {$stage}", array_merge([
            'pipeline_run_id' => $pipelineRun->getId()->toString(),
            'stage' => $stage,
        ], $data));
    }

    private function recordPipelineError(PipelineRun $pipelineRun, string $errorType, string $message): void
    {
        $this->logger->error("Pipeline error: {$errorType}", [
            'pipeline_run_id' => $pipelineRun->getId()->toString(),
            'error_type' => $errorType,
            'message' => $message,
        ]);
    }

    private function getOrderValidationRules(): array
    {
        return [
            'order_id' => ['required' => true, 'type' => 'string'],
            'customer_id' => ['required' => true, 'type' => 'string'],
            'total_amount' => ['required' => true, 'type' => 'numeric', 'min' => 0],
            'currency' => ['required' => true, 'type' => 'string'],
            'status' => ['required' => true, 'type' => 'string'],
        ];
    }

    private function getOrderTransformRules(): array
    {
        return [
            'order_id' => ['trim' => true],
            'customer_id' => ['trim' => true],
            'total_amount' => ['round' => 2],
            'currency' => ['normalize' => 'uppercase'],
            'status' => ['normalize' => 'lowercase'],
        ];
    }

    private function updatePipelineStatus(PipelineRun $pipelineRun, string $status): void
    {
        $pipelineRun->setStatus($status);
        $pipelineRun->setStartedAt(new \DateTimeImmutable());
        $this->pipelineRunRepository->save($pipelineRun);
    }
}
