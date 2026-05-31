<?php
declare(strict_types=1);

namespace Billing\Subscription;

use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Request;

final class SubscriptionController
{
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly SubscriptionService $subscriptionService,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcher $dispatcher
    ) {}

    public function cancel(Request $request, int $subscriptionId): JsonResponse
    {
        $customerId = $request->attributes->getInt('customer_id');

        try {
            $subscription = $this->subscriptionService->cancelSubscription(
                $subscriptionId,
                $customerId
            );

            $this->dispatcher->dispatch(new SubscriptionCancelledEvent($subscription));

            $this->logger->info('Subscription cancelled successfully', [
                'subscription_id' => $subscriptionId,
                'customer_id' => $customerId,
                'cancelled_at' => (new \DateTimeImmutable())->format('c'),
                'end_date' => $subscription->getCurrentPeriodEnd()->format('c')
            ]);

            return $this->json([
                'message' => 'Subscription cancelled successfully',
                'effective_date' => $subscription->getCurrentPeriodEnd()->format('Y-m-d')
            ]);

        } catch (SubscriptionNotFoundException $e) {
            $this->logger->error('Subscription cancellation failed: not found', [
                'subscription_id' => $subscriptionId,
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->json(['error' => 'Subscription not found'], 404);

        } catch (SubscriptionAlreadyCancelledException $e) {
            $this->logger->warning('Attempt to cancel already cancelled subscription', [
                'subscription_id' => $subscriptionId,
                'customer_id' => $customerId
            ]);
            return $this->json(['error' => 'Subscription is already cancelled'], 409);

        } catch (ApiErrorException $e) {
            $this->logger->error('Stripe API error during subscription cancellation', [
                'subscription_id' => $subscriptionId,
                'customer_id' => $customerId,
                'stripe_error' => $e->getMessage(),
                'stripe_code' => $e->getStripeCode(),
                'http_status' => $e->getHttpStatus()
            ]);
            return $this->json(['error' => 'Payment provider error. Please try again.'], 502);

        } catch (\Throwable $e) {
            $this->logger->critical('Unexpected error cancelling subscription', [
                'subscription_id' => $subscriptionId,
                'customer_id' => $customerId,
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function update(Request $request, int $subscriptionId): JsonResponse
    {
        $planId = $request->request->get('plan_id');

        try {
            $subscription = $this->subscriptionService->changePlan(
                $subscriptionId,
                $planId
            );

            $this->logger->info('Subscription plan changed', [
                'subscription_id' => $subscriptionId,
                'new_plan' => $planId,
                'effective_date' => (new \DateTimeImmutable())->format('c')
            ]);

            return $this->json([
                'message' => 'Subscription updated',
                'subscription' => $subscription->toArray()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to update subscription', [
                'subscription_id' => $subscriptionId,
                'plan_id' => $planId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if ($e instanceof ApiErrorException) {
                return $this->json(['error' => 'Payment provider error'], 502);
            }

            return $this->json(['error' => 'Failed to update subscription'], 400);
        }
    }
}
