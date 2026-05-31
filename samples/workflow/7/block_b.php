<?php
declare(strict_types=1);

namespace App\Document\Generation;

use App\Domain\Entity\Contract;
use App\Domain\Entity\Document;
use App\Domain\Repository\ContractRepositoryInterface;
use App\Domain\Service\TemplateServiceInterface;
use App\Domain\Service\PdfServiceInterface;
use App\Domain\Service\StorageServiceInterface;
use App\Domain\Service\NotificationServiceInterface;
use Psr\Log\LoggerInterface;

final readonly class ContractGenerationWorkflow
{
    public function __construct(
        private ContractRepositoryInterface $contractRepository,
        private TemplateServiceInterface $templateService,
        private PdfServiceInterface $pdfService,
        private StorageServiceInterface $storageService,
        private NotificationServiceInterface $notificationService,
        private LoggerInterface $logger,
    ) {}

    public function generateContract(string $contractId): void
    {
        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null) {
            throw new \RuntimeException("Contract not found: {$contractId}");
        }

        $this->logger->info('Starting contract generation workflow', ['contract_id' => $contractId]);

        $this->validateContract($contract);

        $this->loadTemplate($contract);

        $this->renderHtml($contract);

        $this->generatePdf($contract);

        $this->storeDocument($contract);

        $this->attachToContract($contract);

        $this->sendNotification($contract);

        $this->updateContractStatus($contract, 'document_generated');

        $this->recordAuditEvent($contract, 'contract_generated');

        $this->logger->info('Contract generation workflow completed', ['contract_id' => $contractId]);
    }

    private function validateContract(Contract $contract): void
    {
        if ($contract->getStatus() !== 'approved') {
            throw new \RuntimeException("Contract must be approved before generation");
        }

        if ($contract->getPartyA() === null || $contract->getPartyB() === null) {
            throw new \RuntimeException("Contract must have both parties");
        }

        $this->logger->debug('Contract validation passed', ['contract_id' => $contract->getId()->toString()]);
    }

    private function loadTemplate(Contract $contract): void
    {
        $templateName = $contract->getTemplate() ?? 'default_contract';

        $template = $this->templateService->load($templateName);
        if ($template === null) {
            throw new \RuntimeException("Template not found: {$templateName}");
        }

        $contract->setTemplateData(['template_id' => $template->getId()]);

        $this->logger->debug('Template loaded', [
            'contract_id' => $contract->getId()->toString(),
            'template' => $templateName,
        ]);
    }

    private function renderHtml(Contract $contract): void
    {
        $data = $this->prepareTemplateData($contract);

        $html = $this->templateService->render($contract->getTemplateData()['template_id'], $data);

        $contract->setRenderedHtml($html);

        $this->logger->debug('HTML rendered', ['contract_id' => $contract->getId()->toString()]);
    }

    private function prepareTemplateData(Contract $contract): array
    {
        return [
            'contract_number' => $contract->getContractNumber(),
            'effective_date' => $contract->getEffectiveDate()->format('Y-m-d'),
            'expiration_date' => $contract->getExpirationDate()->format('Y-m-d'),
            'party_a' => [
                'name' => $contract->getPartyA()->getName(),
                'address' => $contract->getPartyA()->getAddress(),
                'signatory' => $contract->getPartyA()->getSignatory(),
            ],
            'party_b' => [
                'name' => $contract->getPartyB()->getName(),
                'address' => $contract->getPartyB()->getAddress(),
                'signatory' => $contract->getPartyB()->getSignatory(),
            ],
            'terms' => $contract->getTerms(),
            'clauses' => $contract->getClauses(),
            'total_value' => $contract->getTotalValue(),
            'currency' => $contract->getCurrency(),
            'company' => [
                'name' => 'Acme Corporation',
                'address' => '123 Business St',
                'phone' => '555-0100',
            ],
        ];
    }

    private function generatePdf(Contract $contract): void
    {
        $result = $this->pdfService->generateFromHtml($contract->getRenderedHtml(), [
            'page_size' => 'A4',
            'margin_top' => 30,
            'margin_bottom' => 30,
            'margin_left' => 25,
            'margin_right' => 25,
        ]);

        if (!$result->isSuccessful()) {
            $this->recordAuditEvent($contract, 'pdf_generation_failed', [
                'error' => $result->getError(),
            ]);
            throw new \RuntimeException("PDF generation failed: {$result->getError()}");
        }

        $contract->setPdfData($result->getPdfData());
        $contract->setPageCount($result->getPageCount());

        $this->logger->debug('PDF generated', [
            'contract_id' => $contract->getId()->toString(),
            'page_count' => $result->getPageCount(),
        ]);
    }

    private function storeDocument(Contract $contract): void
    {
        $path = sprintf(
            'contracts/%s/%s.pdf',
            $contract->getPartyA()->getId()->toString(),
            $contract->getContractNumber()
        );

        $url = $this->storageService->store($path, $contract->getPdfData(), [
            'content_type' => 'application/pdf',
            'metadata' => [
                'contract_id' => $contract->getId()->toString(),
            ],
        ]);

        $contract->setDocumentUrl($url);

        $this->logger->debug('Document stored', [
            'contract_id' => $contract->getId()->toString(),
            'path' => $path,
        ]);
    }

    private function attachToContract(Contract $contract): void
    {
        $document = new Document();
        $document->setType('contract_pdf');
        $document->setUrl($contract->getDocumentUrl());
        $document->setFileName($contract->getContractNumber() . '.pdf');
        $document->setMimeType('application/pdf');
        $document->setSize(strlen($contract->getPdfData()));

        $contract->addDocument($document);

        $this->logger->debug('Document attached to contract', ['contract_id' => $contract->getId()->toString()]);
    }

    private function sendNotification(Contract $contract): void
    {
        $this->notificationService->send(
            $contract->getPartyA()->getId(),
            'contract_ready',
            [
                'contract_id' => $contract->getId()->toString(),
                'contract_number' => $contract->getContractNumber(),
                'download_url' => $contract->getDocumentUrl(),
            ]
        );

        $this->logger->debug('Notification sent', ['contract_id' => $contract->getId()->toString()]);
    }

    private function updateContractStatus(Contract $contract, string $status): void
    {
        $contract->setStatus($status);
        $contract->setUpdatedAt(new \DateTimeImmutable());
        $this->contractRepository->save($contract);
    }

    private function recordAuditEvent(Contract $contract, string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'contract_id' => $contract->getId()->toString(),
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }
}
