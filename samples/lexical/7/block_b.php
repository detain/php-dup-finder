<?php
declare(strict_types=1);

namespace Acme\Library\Loans;

use Acme\Library\Domain\Loan;
use Acme\Library\Domain\PatronType;

final class DueDateScheduler
{
    /** @var array<string,string> */
    private array $loanPeriods = [
        'standard' => 'P14D',
        'student'  => 'P28D',
        'staff'    => 'P56D',
    ];

    public function computeDueDate(Loan $loan): string
    {
        $checkedOut = $loan->checkedOutAt()->format('c');
        $patronType = $loan->patron()->type();
        $period = $this->loanPeriods[$patronType] ?? 'P14D';

        // same token-shape: parse + add interval + format
        $moment = new \DateTimeImmutable($checkedOut);
        $moment = $moment->add(new \DateInterval($period));
        $formatted = $moment->format('Y-m-d');

        return $formatted;
    }

    public function bulkDueDates(iterable $loans): array
    {
        $rows = [];
        foreach ($loans as $loan) {
            $rows[$loan->id()] = $this->computeDueDate($loan);
        }
        return $rows;
    }
}
