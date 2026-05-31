<?php

declare(strict_types=1);

namespace App\Reporting\Statement;

use App\Entity\Statement;
use App\Repository\StatementRepository;
use App\Service\PdfGenerator;
use App\Service\InterestCalculator;
use Psr\Log\LoggerInterface;
use Twig\Environment;

final class StatementGenerator
{
    public function __construct(
        private readonly StatementRepository $statements,
        private readonly InterestCalculator $interestCalculator,
        private readonly PdfGenerator $pdfGenerator,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {}

    public function generateForStatement(int $statementId): string
    {
        $statement = $this->statements->findById($statementId);

        if ($statement === null) {
            $this->logger->error('Statement not found for PDF generation', [
                'statement_id' => $statementId,
            ]);
            throw new \RuntimeException("Statement {$statementId} not found");
        }

        $lineItems = $this->buildLineItems($statement);
        $subtotal = $this->calculateSubtotal($lineItems);
        $interestAmount = $this->interestCalculator->calculateInterest($statement);
        $total = $subtotal + $interestAmount;

        $statementData = [
            'statement' => $statement,
            'line_items' => $lineItems,
            'subtotal' => $subtotal,
            'interest_amount' => $interestAmount,
            'total' => $total,
            'account_type' => $statement->getAccountType(),
            'bank_name' => $statement->getBankName(),
            'statement_date' => $statement->getCreatedAt()->format('Y-m-d H:i:s'),
            'statement_number' => $this->generateStatementNumber($statement),
        ];

        $html = $this->twig->render('statement/standard.html.twig', $statementData);
        $pdfPath = $this->pdfGenerator->generateFromHtml($html, [
            'filename' => "statement_{$statement->getStatementNumber()}.pdf",
            'directory' => '/var/storage/statements',
        ]);

        $this->logger->info('Statement PDF generated successfully', [
            'statement_id' => $statementId,
            'pdf_path' => $pdfPath,
        ]);

        return $pdfPath;
    }

    private function buildLineItems(Statement $statement): array
    {
        $items = [];
        foreach ($statement->getItems() as $item) {
            $items[] = [
                'description' => $item->getDescription(),
                'quantity' => $item->getQuantity(),
                'unit_price' => $item->getUnitPrice(),
                'line_total' => $item->getQuantity() * $item->getUnitPrice(),
            ];
        }
        return $items;
    }

    private function calculateSubtotal(array $lineItems): float
    {
        return array_reduce(
            $lineItems,
            fn(float $carry, array $item) => $carry + $item['line_total'],
            0.0
        );
    }

    private function generateStatementNumber(Statement $statement): string
    {
        return sprintf(
            'STMT-%s-%04d',
            $statement->getCreatedAt()->format('Ymd'),
            $statement->getId()
        );
    }
}
