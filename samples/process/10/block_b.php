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

final class GenerateHeadcountYearEndReportCommand extends Command
{
    protected static $defaultName = 'reports:yearend:headcount';

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
        $yr = (int) $in->getArgument('year');
        $out->writeln("Generating headcount report for {$yr}");
        $rs = $this->db->fetchAll(
            'SELECT MONTH(event_date) AS m, SUM(CASE WHEN event = "hire" THEN 1 ELSE 0 END) AS hires,
                    SUM(CASE WHEN event = "term" THEN 1 ELSE 0 END) AS terms
             FROM employee_events WHERE YEAR(event_date) = ? GROUP BY MONTH(event_date)',
            [$yr]
        );
        $byMonth = array_fill(1, 12, ['hires' => 0, 'terms' => 0]);
        $netChange = 0;
        foreach ($rs as $r) {
            $byMonth[(int) $r['m']] = ['hires' => (int) $r['hires'], 'terms' => (int) $r['terms']];
            $netChange += (int) $r['hires'] - (int) $r['terms'];
        }
        $bytes = $this->pdf->render('reports/headcount_yearend.tpl', [
            'year' => $yr,
            'monthly' => $byMonth,
            'net_change' => $netChange,
        ]);
        $fname = "headcount_yearend_{$yr}.pdf";
        $location = $this->archive->store('hr', $yr, $fname, $bytes);
        $this->db->insert('report_manifest', [
            'report' => 'headcount_yearend',
            'year' => $yr,
            'url' => $location,
            'size_bytes' => strlen($bytes),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $out->writeln("<info>Stored at {$location}</info>");
        return Command::SUCCESS;
    }
}
