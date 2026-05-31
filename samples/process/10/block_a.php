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

final class GenerateSalesYearEndReportCommand extends Command
{
    protected static $defaultName = 'reports:yearend:sales';

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
        $year = (int) $in->getArgument('year');
        $out->writeln("Generating sales report for {$year}");
        $rows = $this->db->fetchAll(
            'SELECT MONTH(created_at) AS m, SUM(total_cents) AS total, COUNT(*) AS cnt
             FROM orders WHERE YEAR(created_at) = ? GROUP BY MONTH(created_at)',
            [$year]
        );
        $monthly = array_fill(1, 12, ['total' => 0, 'count' => 0]);
        $grandTotal = 0;
        foreach ($rows as $r) {
            $monthly[(int) $r['m']] = ['total' => (int) $r['total'], 'count' => (int) $r['cnt']];
            $grandTotal += (int) $r['total'];
        }
        $pdfBytes = $this->pdf->render('reports/sales_yearend.tpl', [
            'year' => $year,
            'monthly' => $monthly,
            'grand_total' => $grandTotal,
        ]);
        $filename = "sales_yearend_{$year}.pdf";
        $url = $this->archive->store('sales', $year, $filename, $pdfBytes);
        $this->db->insert('report_manifest', [
            'report' => 'sales_yearend',
            'year' => $year,
            'url' => $url,
            'size_bytes' => strlen($pdfBytes),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $out->writeln("<info>Stored at {$url}</info>");
        return Command::SUCCESS;
    }
}
