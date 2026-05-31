<?php

declare(strict_types=1);

namespace App\Fees;

use App\Entity\Loan;
use App\Repository\LoanRepository;
use Psr\Log\LoggerInterface;

final class LoanFeeCalculatorService
{
    public function __construct(
        private readonly LoanRepository $loanRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateOriginationFee(int $loanId): int
    {
        $loan = $this->loanRepository->findById($loanId);

        if ($loan === null) {
            throw new \RuntimeException('Loan not found');
        }

        $principal = $loan->getPrincipal();
        $loanType = $loan->getType();
        $borrowerGrade = $loan->getBorrowerGrade();

        if ($loanType === 'personal') {
            if ($principal <= 5000) {
                $rate = 0.02;
            } elseif ($principal <= 20000) {
                $rate = 0.015;
            } elseif ($principal <= 50000) {
                $rate = 0.0125;
            } else {
                $rate = 0.01;
            }
        } elseif ($loanType === 'business') {
            if ($principal <= 10000) {
                $rate = 0.025;
            } elseif ($principal <= 50000) {
                $rate = 0.02;
            } else {
                $rate = 0.015;
            }
        } else {
            $rate = 0.02;
        }

        if ($borrowerGrade === 'A') {
            $rate *= 0.8;
        } elseif ($borrowerGrade === 'B') {
            $rate *= 0.9;
        } elseif (in_array($borrowerGrade, ['D', 'E', 'F'])) {
            $rate *= 1.2;
        }

        return (int) round($principal * $rate);
    }

    public function calculateLateFee(int $loanId): int
    {
        $loan = $this->loanRepository->findById($loanId);

        if ($loan === null) {
            throw new \RuntimeException('Loan not found');
        }

        $outstandingAmount = $loan->getOutstandingAmount();
        $daysLate = $loan->getDaysLate();

        if ($daysLate <= 0) {
            return 0;
        }

        if ($daysLate <= 15) {
            $rate = 0.005;
        } elseif ($daysLate <= 30) {
            $rate = 0.01;
        } else {
            $rate = 0.02;
        }

        $lateFee = (int) round($outstandingAmount * $rate);

        $maxLateFee = (int) round($outstandingAmount * 0.10);
        if ($lateFee > $maxLateFee) {
            $lateFee = $maxLateFee;
        }

        return $lateFee;
    }

    public function calculatePrepaymentFee(int $loanId): int
    {
        $loan = $this->loanRepository->findById($loanId);

        if ($loan === null) {
            throw new \RuntimeException('Loan not found');
        }

        $principal = $loan->getPrincipal();
        $remainingPayments = $loan->getRemainingPayments();
        $loanAge = $loan->getLoanAgeInMonths();

        if ($loanAge <= 6) {
            $rate = 0.03;
        } elseif ($loanAge <= 12) {
            $rate = 0.02;
        } elseif ($loanAge <= 24) {
            $rate = 0.01;
        } else {
            return 0;
        }

        if ($remainingPayments <= 3) {
            $rate *= 0.5;
        } elseif ($remainingPayments <= 6) {
            $rate *= 0.75;
        }

        return (int) round($principal * $rate);
    }
}
