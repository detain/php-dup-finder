<?php
declare(strict_types=1);

namespace App\Console\YearEnd;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use App\Database\Connection;
use App\Pdf\PdfRenderer;
use App\Archive\ReportArchive;

final class GenerateCommissionsYearEndReportCommand extends Command
{
    protected static $defaultName = 'reports:yearend:commissions';

    public function __construct(
        private Connection $db,
        private PdfRenderer $pdf,
        private ReportArchive $archive,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('year', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $y = (int) $in->getArgument('year');
        $out->writeln("Generating commissions report for {$y}");
        $raw = $this->db->fetchAll(
            'SELECT MONTH(paid_at) AS m, SUM(amount_cents) AS amt, COUNT(DISTINCT partner_id) AS partners
             FROM partner_commissions WHERE YEAR(paid_at) = ? GROUP BY MONTH(paid_at)',
            [$y]
        );
        $perMonth = array_fill(1, 12, ['amount' => 0, 'partners' => 0]);
        $sum = 0;
        foreach ($raw as $r) {
            $perMonth[(int) $r['m']] = ['amount' => (int) $r['amt'], 'partners' => (int) $r['partners']];
            $sum += (int) $r['amt'];
        }
        $rendered = $this->pdf->render('reports/commissions_yearend.tpl', [
            'year' => $y,
            'monthly' => $perMonth,
            'total_paid' => $sum,
        ]);
        $fileName = "commissions_yearend_{$y}.pdf";
        $stored = $this->archive->store('finance', $y, $fileName, $rendered);
        $this->db->insert('report_manifest', [
            'report' => 'commissions_yearend',
            'year' => $y,
            'url' => $stored,
            'size_bytes' => strlen($rendered),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $out->writeln("<info>Stored at {$stored}</info>");
        return Command::SUCCESS;
    }
}
