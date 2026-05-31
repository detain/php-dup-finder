<?php
declare(strict_types=1);

namespace App\Analytics\Service;

use App\Analytics\Repository\CartEventRepository;
use App\Cart\Repository\CartRepository;
use Psr\Log\LoggerInterface;

final class CartBehaviorAnalyzer
{
    private CartEventRepository $eventRepository;
    private CartRepository $cartRepository;
    private LoggerInterface $logger;

    private const ABANDONMENT_WINDOW_HOURS = 24;

    public function __construct(
        CartEventRepository $eventRepository,
        CartRepository $cartRepository,
        LoggerInterface $logger
    ) {
        $this->eventRepository = $eventRepository;
        $this->cartRepository = $cartRepository;
        $this->logger = $logger;
    }

    public function checkAbandonmentStatus(string $cartId): bool
    {
        $cart = $this->cartRepository->findById($cartId);

        if ($cart === null) {
            return false;
        }

        if ($this->cartWasConverted($cart)) {
            return false;
        }

        if ($this->cartWasRecovered($cart)) {
            return false;
        }

        $lastInteractionTime = $this->getLastUserInteraction($cartId);

        if ($lastInteractionTime === null) {
            $lastInteractionTime = $cart->getCreatedAt();
        }

        $hoursElapsed = $this->calculateHoursSince($lastInteractionTime);

        return $hoursElapsed >= self::ABANDONMENT_WINDOW_HOURS;
    }

    public function identifyAbandonmentCandidates(): array
    {
        $recentEvents = $this->eventRepository->findCartEventsForWindow(
            new \DateTimeImmutable('-' . self::ABANDONMENT_WINDOW_HOURS . ' hours')
        );

        $cartLastActivityMap = [];

        foreach ($recentEvents as $event) {
            $cartId = $event->getCartId();
            $eventTime = $event->getCreatedAt();

            if (!isset($cartLastActivityMap[$cartId]) || $cartLastActivityMap[$cartId] < $eventTime) {
                $cartLastActivityMap[$cartId] = $eventTime;
            }
        }

        $abandonedCartIds = [];
        $cutoffTime = (new \DateTimeImmutable())->modify('-' . self::ABANDONMENT_WINDOW_HOURS . ' hours');

        foreach ($cartLastActivityMap as $cartId => $lastActivity) {
            if ($lastActivity < $cutoffTime) {
                $cart = $this->cartRepository->findById($cartId);

                if ($cart !== null && !$cart->isConverted() && !$cart->isRecovered()) {
                    $abandonedCartIds[] = $cartId;
                }
            }
        }

        return $this->cartRepository->findByIds($abandonedCartIds);
    }

    public function calculateAbandonmentScore(string $cartId): float
    {
        $cart = $this->cartRepository->findById($cartId);

        if ($cart === null) {
            return 0.0;
        }

        $events = $this->eventRepository->findEventsForCart($cartId);
        $eventCount = count($events);

        $cartValue = $cart->getTotalValue();

        $checkoutStarted = false;
        $checkoutProgress = 0;

        foreach ($events as $event) {
            if ($event->getType() === 'checkout_started') {
                $checkoutStarted = true;
            }

            if ($event->getType() === 'checkout_progress') {
                $checkoutProgress++;
            }
        }

        $score = min(100.0, $eventCount * 5);
        $score += min(30, $cartValue / 10);

        if ($checkoutStarted) {
            $score += 20;
        }

        $score += min(20, $checkoutProgress * 5);

        return $score;
    }

    private function cartWasConverted($cart): bool
    {
        return $cart->getConvertedAt() !== null;
    }

    private function cartWasRecovered($cart): bool
    {
        return $cart->getRecoveredAt() !== null;
    }

    private function getLastUserInteraction(string $cartId): ?\DateTimeImmutable
    {
        $lastEvent = $this->eventRepository->findLastEventForCart($cartId);
        return $lastEvent?->getCreatedAt();
    }

    private function calculateHoursSince(\DateTimeImmutable $time): int
    {
        $diff = (new \DateTimeImmutable())->getTimestamp() - $time->getTimestamp();
        return (int) floor($diff / 3600);
    }
}
