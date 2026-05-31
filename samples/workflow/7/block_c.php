<?php
declare(strict_types=1);

namespace App\Document\Generation;

use App\Domain\Entity\Report;
use App\Domain\Entity\Document;
use App\Domain\Repository\ReportRepositoryInterface;
use App\Domain\Service\TemplateServiceInterface;
use App\Domain\Service\PdfServiceInterface;
use App\Domain\Service\StorageServiceInterface;
use App\Domain\Service\NotificationServiceInterface;
use Psr\Log\LoggerInterface;

final readonly class ReportGenerationWorkflow
{
    public function __construct(
        private ReportRepositoryInterface $reportRepository,
        private TemplateServiceInterface $templateService,
        private PdfServiceInterface $pdfService,
        private StorageServiceInterface $storageService,
        private NotificationServiceInterface $notificationService,
        private LoggerInterface $logger,
    ) {}

    public function generateReport(string $reportId): void
    {
        $report = $this->reportRepository->findById($reportId);
        if ($report === null) {
            throw new \RuntimeException("Report not found: {$reportId}");
        }

        $this->logger->info('Starting report generation workflow', ['report_id' => $reportId]);

        $this->validateReport($report);

        $this->loadTemplate($report);

        $this->renderHtml($report);

        $this->generatePdf($report);

        $this->storeDocument($report);

        $this->attachToReport($report);

        $this->sendNotification($report);

        $this->updateReportStatus($report, 'document_generated');

        $this->recordAuditEvent($report, 'report_generated');

        $this->logger->info('Report generation workflow completed', ['report_id' => $reportId]);
    }

    private function validateReport(Report $report): void
    {
        if ($report->getStatus() !== 'ready') {
            throw new \RuntimeException("Report must be ready before generation");
        }

        if ($report->getData() === null) {
            throw new \RuntimeException("Report must have data");
        }

        $this->logger->debug('Report validation passed', ['report_id' => $report->getId()->toString()]);
    }

    private function loadTemplate(Report $report): void
    {
        $templateName = $report->getTemplate() ?? 'default_report';

        $template = $this->templateService->load($templateName);
        if ($template === null) {
            throw new \RuntimeException("Template not found: {$templateName}");
        }

        $report->setTemplateData(['template_id' => $template->getId()]);

        $this->logger->debug('Template loaded', [
            'report_id' => $report->getId()->toString(),
            'template' => $templateName,
        ]);
    }

    private function renderHtml(Report $report): void
    {
        $data = $this->prepareTemplateData($report);

        $html = $this->templateService->render($report->getTemplateData()['template_id'], $data);

        $report->setRenderedHtml($html);

        $this->logger->debug('HTML rendered', ['report_id' => $report->getId()->toString()]);
    }

    private function prepareTemplateData(Report $report): array
    {
        return [
            'report_title' => $report->getTitle(),
            'report_date' => $report->getReportDate()->format('Y-m-d'),
            'generated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'data' => $report->getData(),
            'summary' => $report->getSummary(),
            'sections' => $report->getSections(),
            'company' => [
                'name' => 'Acme Corporation',
                'address' => '123 Business St',
                'phone' => '555-0100',
            ],
        ];
    }

    private function generatePdf(Report $report): void
    {
        $result = $this->pdfService->generateFromHtml($report->getRenderedHtml(), [
            'page_size' => 'A4',
            'margin_top' => 25,
            'margin_bottom' => 25,
            'margin_left' => 20,
            'margin_right' => 20,
        ]);

        if (!$result->isSuccessful()) {
            $this->recordAuditEvent($report, 'pdf_generation_failed', [
                'error' => $result->getError(),
            ]);
            throw new \RuntimeException("PDF generation failed: {$result->getError()}");
        }

        $report->setPdfData($result->getPdfData());
        $report->setPageCount($result->getPageCount());

        $this->logger->debug('PDF generated', [
            'report_id' => $report->getId()->toString(),
            'page_count' => $result->getPageCount(),
        ]);
    }

    private function storeDocument(Report $report): void
    {
        $path = sprintf(
            'reports/%s/%s.pdf',
            $report->getReportDate()->format('Y-m'),
            $report->getId()->toString()
        );

        $url = $this->storageService->store($path, $report->getPdfData(), [
            'content_type' => 'application/pdf',
            'metadata' => [
                'report_id' => $report->getId()->toString(),
                'report_date' => $report->getReportDate()->format('Y-m-d'),
            ],
        ]);

        $report->setDocumentUrl($url);

        $this->logger->debug('Document stored', [
            'report_id' => $report->getId()->toString(),
            'path' => $path,
        ]);
    }

    private function attachToReport(Report $report): void
    {
        $document = new Document();
        $document->setType('report_pdf');
        $document->setUrl($report->getDocumentUrl());
        $document->setFileName($report->getTitle() . '.pdf');
        $document->setMimeType('application/pdf');
        $document->setSize(strlen($report->getPdfData()));

        $report->addDocument($document);

        $this->logger->debug('Document attached to report', ['report_id' => $report->getId()->toString()]);
    }

    private function sendNotification(Report $report): void
    {
        $this->notificationService->send(
            $report->getRequestedBy(),
            'report_ready',
            [
                'report_id' => $report->getId()->toString(),
                'report_title' => $report->getTitle(),
                'download_url' => $report->getDocumentUrl(),
            ]
        );

        $this->logger->debug('Notification sent', ['report_id' => $report->getId()->toString()]);
    }

    private function updateReportStatus(Report $report, string $status): void
    {
        $report->setStatus($status);
        $report->setUpdatedAt(new \DateTimeImmutable());
        $this->reportRepository->save($report);
    }

    private function recordAuditEvent(Report $report, string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'report_id' => $report->getId()->toString(),
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }
}
