<?php
declare(strict_types=1);

namespace App\Cart\Service;

use App\Cart\Repository\CartRepository;
use App\Cart\Entity\Cart;
use App\Cart\Entity\CartItem;
use Psr\Log\LoggerInterface;

final class CartAbandonmentService
{
    private CartRepository $cartRepository;
    private LoggerInterface $logger;

    public function __construct(
        CartRepository $cartRepository,
        LoggerInterface $logger
    ) {
        $this->cartRepository = $cartRepository;
        $this->logger = $logger;
    }

    public function isCartAbandoned(string $cartId): bool
    {
        $cart = $this->cartRepository->findById($cartId);

        if ($cart === null) {
            return false;
        }

        if ($cart->isRecovered()) {
            return false;
        }

        if ($cart->isConverted()) {
            return false;
        }

        $lastActivity = $cart->getLastActivityAt();
        if ($lastActivity === null) {
            $lastActivity = $cart->getCreatedAt();
        }

        $hoursSinceActivity = (new \DateTimeImmutable())->getTimestamp() - $lastActivity->getTimestamp();
        $hoursSinceActivity = (int) floor($hoursSinceActivity / 3600);

        return $hoursSinceActivity >= 24;
    }

    public function findAbandonedCarts(int $hoursThreshold = 24): array
    {
        $cutoffTime = (new \DateTimeImmutable())->modify("-{$hoursThreshold} hours");

        $allCarts = $this->cartRepository->findAllActive();

        $abandonedCarts = [];

        foreach ($allCarts as $cart) {
            if ($cart->isRecovered() || $cart->isConverted()) {
                continue;
            }

            $lastActivity = $cart->getLastActivityAt() ?? $cart->getCreatedAt();

            if ($lastActivity < $cutoffTime) {
                $abandonedCarts[] = $cart;
            }
        }

        return $abandonedCarts;
    }

    public function calculateAbandonmentRate(): float
    {
        $startDate = (new \DateTimeImmutable())->modify('-30 days');

        $totalCarts = $this->cartRepository->countCreatedSince($startDate);

        $abandonedCarts = $this->cartRepository->countAbandonedSince($startDate);

        if ($totalCarts === 0) {
            return 0.0;
        }

        return round(($abandonedCarts / $totalCarts) * 100, 2);
    }

    public function getCartRecoveryProbability(string $cartId): float
    {
        $cart = $this->cartRepository->findById($cartId);

        if ($cart === null) {
            return 0.0;
        }

        if ($cart->isRecovered() || $cart->isConverted()) {
            return 0.0;
        }

        $lastActivity = $cart->getLastActivityAt() ?? $cart->getCreatedAt();
        $hoursSinceActivity = (new \DateTimeImmutable())->getTimestamp() - $lastActivity->getTimestamp();
        $hoursSinceActivity = (int) floor($hoursSinceActivity / 3600);

        if ($hoursSinceActivity < 1) {
            return 0.8;
        }

        if ($hoursSinceActivity < 4) {
            return 0.6;
        }

        if ($hoursSinceActivity < 12) {
            return 0.4;
        }

        if ($hoursSinceActivity < 24) {
            return 0.2;
        }

        return 0.1;
    }

    public function markAsRecovered(string $cartId): void
    {
        $cart = $this->cartRepository->findById($cartId);

        if ($cart !== null) {
            $cart->setRecovered(true);
            $this->cartRepository->save($cart);
        }
    }
}
