<?php
declare(strict_types=1);

namespace App\Marketing\Service;

use App\Marketing\Repository\AbandonedCartRepository;
use App\Marketing\Repository\RecoveryAttemptRepository;
use Psr\Log\LoggerInterface;

final class AbandonedCartDetector
{
    private AbandonedCartRepository $abandonedCartRepo;
    private RecoveryAttemptRepository $attemptRepo;
    private LoggerInterface $logger;

    public const DEFAULT_ABANDONMENT_HOURS = 24;
    public const MIN_ITEMS_FOR_VALID_CART = 1;
    public const MIN_CART_VALUE_FOR_TRACKING = 5.00;

    public function __construct(
        AbandonedCartRepository $abandonedCartRepo,
        RecoveryAttemptRepository $attemptRepo,
        LoggerInterface $logger
    ) {
        $this->abandonedCartRepo = $abandonedCartRepo;
        $this->attemptRepo = $attemptRepo;
        $this->logger = $logger;
    }

    public function detectAbandonment(string $cartId): bool
    {
        $cartData = $this->abandonedCartRepo->getCartData($cartId);

        if ($cartData === null) {
            return false;
        }

        if (!$this->isEligibleForTracking($cartData)) {
            return false;
        }

        if ($this->alreadyConverted($cartId)) {
            return false;
        }

        if ($this->alreadyRecovered($cartId)) {
            return false;
        }

        $inactivityTime = $this->calculateInactivityHours($cartData);

        $abandonmentThreshold = $cartData['custom_abandonment_hours']
            ?? self::DEFAULT_ABANDONMENT_HOURS;

        return $inactivityTime >= $abandonmentThreshold;
    }

    public function getAbandonedCartIds(): array
    {
        $allCartData = $this->abandonedCartRepo->getAllActiveCarts();

        $abandonedIds = [];

        foreach ($allCartData as $cartData) {
            if ($this->isAbandoned($cartData)) {
                $abandonedIds[] = $cartData['id'];
            }
        }

        return $abandonedIds;
    }

    private function isAbandoned(array $cartData): bool
    {
        if (!$this->isEligibleForTracking($cartData)) {
            return false;
        }

        if ($this->alreadyConverted($cartData['id'])) {
            return false;
        }

        if ($this->alreadyRecovered($cartData['id'])) {
            return false;
        }

        $inactivityHours = $this->calculateInactivityHours($cartData);
        $thresholdHours = $cartData['custom_abandonment_hours'] ?? self::DEFAULT_ABANDONMENT_HOURS;

        return $inactivityHours >= $thresholdHours;
    }

    private function isEligibleForTracking(array $cartData): bool
    {
        if (($cartData['item_count'] ?? 0) < self::MIN_ITEMS_FOR_VALID_CART) {
            return false;
        }

        if (($cartData['total_value'] ?? 0) < self::MIN_CART_VALUE_FOR_TRACKING) {
            return false;
        }

        if (($cartData['is_guest_cart'] ?? false) && !($cartData['email_captured'] ?? false)) {
            return false;
        }

        return true;
    }

    private function alreadyConverted(string $cartId): bool
    {
        return $this->abandonedCartRepo->hasConversionRecord($cartId);
    }

    private function alreadyRecovered(string $cartId): bool
    {
        $recovered = $this->attemptRepo->hasSuccessfulRecovery($cartId);
        return $recovered;
    }

    private function calculateInactivityHours(array $cartData): int
    {
        $lastActivity = $cartData['last_activity_at'] ?? $cartData['created_at'];

        if ($lastActivity === null) {
            return PHP_INT_MAX;
        }

        $lastActivityTime = new \DateTimeImmutable($lastActivity);
        $now = new \DateTimeImmutable();

        $secondsDiff = $now->getTimestamp() - $lastActivityTime->getTimestamp();

        return (int) floor($secondsDiff / 3600);
    }

    public function getAbandonmentMetrics(): array
    {
        $abandonedIds = $this->getAbandonedCartIds();
        $totalCarts = $this->abandonedCartRepo->countActiveCarts();

        $abandonedCount = count($abandonedIds);

        $totalValue = 0.0;
        foreach ($abandonedIds as $id) {
            $cartData = $this->abandonedCartRepo->getCartData($id);
            if ($cartData !== null) {
                $totalValue += $cartData['total_value'];
            }
        }

        return [
            'abandoned_cart_count' => $abandonedCount,
            'total_active_carts' => $totalCarts,
            'abandonment_rate' => $totalCarts > 0 ? round(($abandonedCount / $totalCarts) * 100, 2) : 0,
            'total_abandoned_value' => $totalValue,
            'average_cart_value' => $abandonedCount > 0 ? round($totalValue / $abandonedCount, 2) : 0
        ];
    }
}
