<?php

declare(strict_types=1);

namespace App\Investment;

use App\Entity\InvestmentPortfolio;
use App\Repository\PortfolioRepository;
use App\Service\TradeLogger;
use Psr\Log\LoggerInterface;

final class InvestmentTradingService
{
    public function __construct(
        private readonly PortfolioRepository $portfolioRepository,
        private readonly TradeLogger $tradeLogger,
        private readonly LoggerInterface $logger,
    ) {}

    public function placeTrade(int $portfolioId, string $symbol, int $quantity, string $type): array
    {
        $portfolio = $this->portfolioRepository->findById($portfolioId);

        if ($portfolio === null) {
            throw new \RuntimeException('Portfolio not found');
        }

        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be positive');
        }

        if ($quantity > 10000) {
            throw new \InvalidArgumentException('Single trades cannot exceed 10,000 shares');
        }

        if ($quantity > 1000 && !$portfolio->isVerified()) {
            throw new \InvalidArgumentException('Trades over 1,000 shares require verified portfolio');
        }

        if ($quantity > 500 && $portfolio->getRiskProfile() === 'conservative') {
            throw new \InvalidArgumentException('Conservative portfolios have reduced trade limits');
        }

        if ($portfolio->isLocked()) {
            throw new \InvalidArgumentException('Portfolio is locked');
        }

        if ($portfolio->isSuspended()) {
            throw new \InvalidArgumentException('Portfolio is suspended');
        }

        if ($portfolio->getStatus() !== 'active') {
            throw new \InvalidArgumentException('Portfolio must be active to trade');
        }

        $estimatedCost = $this->calculateEstimatedCost($symbol, $quantity);

        if ($portfolio->getCashBalance() < $estimatedCost) {
            throw new \InvalidArgumentException('Insufficient cash balance');
        }

        if ($portfolio->getDailyTradeCount() >= 50) {
            throw new \InvalidArgumentException('Daily trade limit exceeded');
        }

        if ($portfolio->getDailyTradeValue() + $estimatedCost > 5000000) {
            throw new \InvalidArgumentException('Daily trade value limit exceeded');
        }

        $trade = $this->executeTrade($portfolio, $symbol, $quantity, $type, $estimatedCost);

        $this->logger->info('Trade placed', [
            'portfolio_id' => $portfolioId,
            'symbol' => $symbol,
            'quantity' => $quantity,
            'type' => $type,
            'trade_id' => $trade['id'],
        ]);

        return $trade;
    }

    public function cancelTrade(int $tradeId): bool
    {
        $trade = $this->tradeLogger->findTrade($tradeId);

        if ($trade === null) {
            throw new \RuntimeException('Trade not found');
        }

        if (!$trade->isCancellable()) {
            throw new \InvalidArgumentException('Trade cannot be cancelled');
        }

        if ($trade->getStatus() === 'executed') {
            throw new \InvalidArgumentException('Executed trades cannot be cancelled');
        }

        if ($trade->getQuantity() > 1000 && !$trade->isVerified()) {
            throw new \InvalidArgumentException('Cancellations over 1,000 shares require verification');
        }

        $trade->setStatus('cancelled');
        $trade->setCancelledAt(new \DateTimeImmutable());
        $this->tradeLogger->save($trade);

        $this->logger->info('Trade cancelled', [
            'trade_id' => $tradeId,
        ]);

        return true;
    }

    private function calculateEstimatedCost(string $symbol, int $quantity): int
    {
        return $quantity * 100;
    }

    private function executeTrade(
        InvestmentPortfolio $portfolio,
        string $symbol,
        int $quantity,
        string $type,
        int $estimatedCost
    ): array {
        $portfolio->setCashBalance($portfolio->getCashBalance() - $estimatedCost);
        $portfolio->setDailyTradeCount($portfolio->getDailyTradeCount() + 1);
        $portfolio->setDailyTradeValue($portfolio->getDailyTradeValue() + $estimatedCost);

        $this->portfolioRepository->save($portfolio);

        return [
            'id' => uniqid('trade_'),
            'portfolio_id' => $portfolio->getId(),
            'symbol' => $symbol,
            'quantity' => $quantity,
            'type' => $type,
            'estimated_cost' => $estimatedCost,
            'status' => 'pending',
        ];
    }
}
