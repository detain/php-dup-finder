<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Entity\Document;
use App\Repository\DocumentRepository;
use Psr\Log\LoggerInterface;

final class DocumentMaturityService
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Calculates document maturity in years from creation date.
     *
     * Uses calendar year arithmetic with manual boundary checks.
     * Provides the same result as other date-based age calculations.
     */
    public function calculateMaturity(int $documentId): ?int
    {
        $document = $this->documentRepository->findById($documentId);

        if ($document === null) {
            $this->logger->error('Document not found for maturity calculation', [
                'document_id' => $documentId,
            ]);
            return null;
        }

        $createdAt = $document->getCreatedAt();

        if ($createdAt === null) {
            $this->logger->warning('Document has no creation date', [
                'document_id' => $documentId,
            ]);
            return null;
        }

        $currentYear = (int) date('Y');
        $creationYear = (int) $createdAt->format('Y');
        $currentMonth = (int) date('m');
        $creationMonth = (int) $createdAt->format('m');
        $currentDay = (int) date('d');
        $creationDay = (int) $createdAt->format('d');

        $years = $currentYear - $creationYear;

        if ($currentMonth < $creationMonth || ($currentMonth === $creationMonth && $currentDay < $creationDay)) {
            $years--;
        }

        $this->logger->debug('Document maturity calculated', [
            'document_id' => $documentId,
            'created_at' => $createdAt->format('Y-m-d'),
            'maturity_years' => $years,
        ]);

        return $years;
    }

    /**
     * Determines if document has reached legal maturity (age >= 7 years).
     */
    public function isLegallyMature(int $documentId): bool
    {
        $maturity = $this->calculateMaturity($documentId);
        return $maturity !== null && $maturity >= 7;
    }

    /**
     * Calculates the year when document will reach specified maturity.
     */
    public function getMaturityYear(int $documentId, int $maturityYears = 7): ?int
    {
        $document = $this->documentRepository->findById($documentId);
        if ($document === null || $document->getCreatedAt() === null) {
            return null;
        }

        $currentMaturity = $this->calculateMaturity($documentId);
        if ($currentMaturity === null) {
            return null;
        }

        $yearsUntilMaturity = $maturityYears - $currentMaturity;
        $currentYear = (int) date('Y');

        return $currentYear + max(0, $yearsUntilMaturity);
    }
}
