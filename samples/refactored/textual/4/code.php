<?php
declare(strict_types=1);

namespace Insurance\Pdf;

final class LegalDisclaimer
{
    public static function text(): string
    {
        // Single source of truth; lawyers edit only here.
        return file_get_contents(__DIR__ . '/legal/disclaimer.en-US.txt') ?: '';
    }
}

abstract class BaseInsurancePdf
{
    public function __construct(protected \Insurance\Pdf\PdfWriter $writer) {}

    protected function appendDisclaimer(): void
    {
        $this->writer->pageBreak();
        $this->writer->smallText(LegalDisclaimer::text());
    }
}

final class PolicyPdf extends BaseInsurancePdf
{
    public function render(string $policyNumber, string $insured, string $coverage): string
    {
        $this->writer->open();
        $this->writer->heading("Policy #{$policyNumber}");
        $this->writer->paragraph("Insured: {$insured}");
        $this->writer->paragraph("Coverage: {$coverage}");
        $this->appendDisclaimer();
        return $this->writer->close();
    }
}

final class ClaimSummaryPdf extends BaseInsurancePdf
{
    public function render(string $claimId, string $insured, float $amount): string
    {
        $this->writer->open();
        $this->writer->heading("Claim #{$claimId}");
        $this->writer->paragraph("Insured: {$insured}");
        $this->writer->paragraph(sprintf('Settlement: $%0.2f', $amount));
        $this->appendDisclaimer();
        return $this->writer->close();
    }
}
