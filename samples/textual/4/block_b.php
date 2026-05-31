<?php
declare(strict_types=1);

namespace Insurance\Pdf;

final class ClaimSummaryPdf
{
    public function __construct(private \Insurance\Pdf\PdfWriter $writer) {}

    public function render(string $claimId, string $insured, float $amount): string
    {
        $disclaimer = <<<DISC
        LEGAL DISCLAIMER

        This document is a contract between you ("the Insured") and Sentinel Mutual Insurance
        Company ("the Insurer"). By accepting this policy you agree that any dispute, claim,
        or controversy arising out of or relating to this contract shall be resolved by
        binding arbitration administered by the American Arbitration Association under its
        Commercial Arbitration Rules, and judgment on the award rendered by the arbitrator(s)
        may be entered in any court having jurisdiction thereof.

        You agree to indemnify and hold harmless the Insurer, its officers, directors,
        employees, and agents from and against any and all claims, damages, losses, costs,
        and expenses (including reasonable attorneys' fees) arising out of or in connection
        with any breach by you of the terms of this contract.

        This contract shall be governed by and construed in accordance with the laws of the
        Commonwealth of Massachusetts, without regard to its conflict-of-laws principles.
        If any provision of this contract is held to be invalid or unenforceable, the
        remaining provisions shall continue in full force and effect.
        DISC;

        $this->writer->open();
        $this->writer->heading("Claim #{$claimId}");
        $this->writer->paragraph("Insured: {$insured}");
        $this->writer->paragraph(sprintf('Settlement: $%0.2f', $amount));
        $this->writer->pageBreak();
        $this->writer->smallText($disclaimer);
        return $this->writer->close();
    }
}
