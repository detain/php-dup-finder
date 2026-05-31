<?php
declare(strict_types=1);

namespace Airbnb\Booking\Service;

use Airbnb\Booking\Repository\ReservationRepository;
use Airbnb\Booking\Repository\PaymentRepository;
use Airbnb\Booking\Repository\CalendarRepository;
use Airbnb\Booking\Entity\Reservation;
use Airbnb\Booking\Entity\Payment;
use Airbnb\Booking\Entity\CalendarHold;
use Airbnb\Booking\Exception\BookingException;
use Airbnb\Booking\Service\PricingService;
use Airbnb\Booking\Service\CancellationPolicyService;
use Psr\Log\LoggerInterface;

final class ReservationService
{
    private ReservationRepository $reservationRepo;
    private PaymentRepository $paymentRepo;
    private CalendarRepository $calendarRepo;
    private PricingService $pricingService;
    private CancellationPolicyService $cancellationPolicy;
    private LoggerInterface $logger;

    public function __construct(
        ReservationRepository $reservationRepo,
        PaymentRepository $paymentRepo,
        CalendarRepository $calendarRepo,
        PricingService $pricingService,
        CancellationPolicyService $cancellationPolicy,
        LoggerInterface $logger
    ) {
        $this->reservationRepo = $reservationRepo;
        $this->paymentRepo = $paymentRepo;
        $this->calendarRepo = $calendarRepo;
        $this->pricingService = $pricingService;
        $this->cancellationPolicy = $cancellationPolicy;
        $this->logger = $logger;
    }

    public function createReservation(string $listingId, array $guestInfo, \DateTimeImmutable $checkIn, \DateTimeImmutable $checkOut): ReservationResult
    {
        $this->logger->info('Creating reservation', [
            'listing_id' => $listingId,
            'check_in' => $checkIn->format('Y-m-d'),
            'check_out' => $checkOut->format('Y-m-d')
        ]);

        $listing = $this->reservationRepo->findListing($listingId);
        if ($listing === null) {
            throw new BookingException("Listing not found: {$listingId}");
        }

        $availabilityHold = $this->calendarRepo->acquireHold(
            $listingId,
            $checkIn,
            $checkOut,
            'reservation_pending'
        );

        if ($availabilityHold === null) {
            throw new BookingException("Dates no longer available for listing: {$listingId}");
        }

        $pricing = $this->pricingService->calculatePricing(
            $listingId,
            $checkIn,
            $checkOut,
            $guestInfo['guest_count'] ?? 1
        );

        $this->logger->debug('Pricing calculated', [
            'listing_id' => $listingId,
            'base_price' => $pricing->getBasePrice(),
            'total_price' => $pricing->getTotal()
        ]);

        $reservation = Reservation::create([
            'listing_id' => $listingId,
            'guest_id' => $guestInfo['guest_id'],
            'host_id' => $listing->getHostId(),
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'guest_count' => $guestInfo['guest_count'] ?? 1,
            'total_price' => $pricing->getTotal(),
            'currency' => $pricing->getCurrency(),
            'status' => 'pending_payment',
            'pricing_details' => $pricing->toArray(),
            'cancellation_policy' => $this->cancellationPolicy->getPolicyForListing($listingId),
            'created_at' => new \DateTimeImmutable()
        ]);

        $savedReservation = $this->reservationRepo->save($reservation);

        $paymentIntent = $this->paymentRepo->createPaymentIntent(
            $savedReservation->getId(),
            $pricing->getTotal(),
            $pricing->getCurrency()
        );

        $this->calendarRepo->updateHoldStatus(
            $availabilityHold->getId(),
            'confirmed',
            $savedReservation->getId()
        );

        $this->logger->info('Reservation created successfully', [
            'reservation_id' => $savedReservation->getId(),
            'listing_id' => $listingId,
            'total_price' => $pricing->getTotal()
        ]);

        return new ReservationResult([
            'success' => true,
            'reservation_id' => $savedReservation->getId(),
            'payment_intent_id' => $paymentIntent->getId(),
            'payment_amount' => $pricing->getTotal(),
            'currency' => $pricing->getCurrency(),
            'requires_payment_by' => (new \DateTimeImmutable())->modify('+30 minutes')->format('c')
        ]);
    }

    public function confirmPayment(string $reservationId, string $paymentIntentId): ConfirmationResult
    {
        $reservation = $this->reservationRepo->findById($reservationId);
        if ($reservation === null) {
            throw new BookingException("Reservation not found: {$reservationId}");
        }

        if ($reservation->getStatus() !== 'pending_payment') {
            throw new BookingException("Reservation is not pending payment, current status: {$reservation->getStatus()}");
        }

        $paymentVerified = $this->paymentRepo->verifyPaymentIntent($paymentIntentId, $reservation->getTotalPrice());
        if (!$paymentVerified) {
            throw new BookingException('Payment verification failed');
        }

        $this->reservationRepo->updateStatus($reservationId, 'confirmed', [
            'confirmed_at' => new \DateTimeImmutable(),
            'payment_intent_id' => $paymentIntentId
        ]);

        $this->calendarRepo->confirmHoldForReservation(
            $reservation->getListingId(),
            $reservationId
        );

        $this->logger->info('Reservation payment confirmed', [
            'reservation_id' => $reservationId,
            'payment_intent_id' => $paymentIntentId
        ]);

        return new ConfirmationResult([
            'success' => true,
            'reservation_id' => $reservationId,
            'confirmed_at' => (new \DateTimeImmutable())->format('c')
        ]);
    }

    public function cancelReservation(string $reservationId, string $cancellationReason): CancellationResult
    {
        $reservation = $this->reservationRepo->findById($reservationId);
        if ($reservation === null) {
            throw new BookingException("Reservation not found: {$reservationId}");
        }

        $refundAmount = $this->cancellationPolicy->calculateRefund(
            $reservation,
            new \DateTimeImmutable()
        );

        if ($refundAmount > 0 && $reservation->hasPaymentIntent()) {
            $this->paymentRepo->processRefund(
                $reservation->getPaymentIntentId(),
                $refundAmount
            );
        }

        $this->reservationRepo->updateStatus($reservationId, 'cancelled', [
            'cancelled_at' => new \DateTimeImmutable(),
            'cancellation_reason' => $cancellationReason,
            'refund_amount' => $refundAmount
        ]);

        $this->calendarRepo->releaseHold(
            $reservation->getListingId(),
            $reservationId
        );

        $this->logger->info('Reservation cancelled', [
            'reservation_id' => $reservationId,
            'refund_amount' => $refundAmount
        ]);

        return new CancellationResult([
            'success' => true,
            'reservation_id' => $reservationId,
            'refund_amount' => $refundAmount,
            'cancelled_at' => (new \DateTimeImmutable())->format('c')
        ]);
    }
}
