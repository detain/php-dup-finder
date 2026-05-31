<?php
declare(strict_types=1);

namespace App\Cart\Service;

interface AbandonmentDetectorInterface
{
    public function isAbandoned(string $cartId): bool;
    public function getAbandonedCartIds(): array;
    public function getAbandonmentScore(string $cartId): float;
}

final class AbandonmentDetector implements AbandonmentDetectorInterface
{
    public function __construct(
        private readonly CartRepository $cartRepository,
        private readonly CartEventRepository $eventRepository,
        private readonly int $abandonmentThresholdHours = 24
    ) {}

    public function isAbandoned(string $cartId): bool
    {
        $cart = $this->cartRepository->findById($cartId);

        if ($cart === null) {
            return false;
        }

        if ($cart->isConverted() || $cart->isRecovered()) {
            return false;
        }

        $lastActivity = $cart->getLastActivityAt() ?? $cart->getCreatedAt();
        $hoursElapsed = $this->calculateHoursSince($lastActivity);

        return $hoursElapsed >= $this->abandonmentThresholdHours;
    }

    public function getAbandonedCartIds(): array
    {
        $carts = $this->cartRepository->findAllActive();

        return array_values(array_filter(
            array_map(fn($cart) => $cart->getId(), $carts),
            fn($id) => $this->isAbandoned($id)
        ));
    }

    public function getAbandonmentScore(string $cartId): float
    {
        $cart = $this->cartRepository->findById($cartId);

        if ($cart === null) {
            return 0.0;
        }

        $events = $this->eventRepository->findEventsForCart($cartId);
        $score = min(50, count($events) * 5);
        $score += min(30, $cart->getTotalValue() / 5);
        $score += $cart->hasCheckoutStarted() ? 20 : 0;

        return min(100.0, $score);
    }

    private function calculateHoursSince(\DateTimeImmutable $time): int
    {
        $diff = (new \DateTimeImmutable())->getTimestamp() - $time->getTimestamp();
        return (int) floor($diff / 3600);
    }
}
