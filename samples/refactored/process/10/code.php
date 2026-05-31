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
use App\YearEnd\ReportProfile;
use App\YearEnd\ReportProfileRegistry;

final class GenerateYearEndReportCommand extends Command
{
    protected static $defaultName = 'reports:yearend';

    public function __construct(
        private Connection $db,
        private PdfRenderer $pdf,
        private ReportArchive $archive,
        private ReportProfileRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('report', InputArgument::REQUIRED);
        $this->addArgument('year', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $p = $this->registry->get((string) $in->getArgument('report'));
        $year = (int) $in->getArgument('year');
        $out->writeln("Generating {$p->name} report for {$year}");
        $rows = $this->db->fetchAll($p->aggregateSql, [$year]);
        $aggregated = ($p->aggregate)($rows);
        $bytes = $this->pdf->render($p->template, [
            'year' => $year,
            'monthly' => $aggregated['monthly'],
            'totals' => $aggregated['totals'],
        ]);
        $filename = "{$p->name}_yearend_{$year}.pdf";
        $url = $this->archive->store($p->bucket, $year, $filename, $bytes);
        $this->db->insert('report_manifest', [
            'report' => "{$p->name}_yearend",
            'year' => $year,
            'url' => $url,
            'size_bytes' => strlen($bytes),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $out->writeln("<info>Stored at {$url}</info>");
        return Command::SUCCESS;
    }
}
