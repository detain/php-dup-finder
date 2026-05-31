<?php
declare(strict_types=1);

namespace Webhooks\Handler;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

abstract class WebhookHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $config,
        private readonly SignatureVerifier $signatureVerifier
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->headers->get('X-Signature', '');

        // Verify signature
        $secret = $this->getWebhookSecret($provider);
        if (!$this->signatureVerifier->verify($payload, $signature, $secret)) {
            $this->logger->warning('Webhook signature verification failed', [
                'provider' => $provider,
                'ip' => $request->getClientIp()
            ]);
            return new JsonResponse(['error' => 'Invalid signature'], 401);
        }

        // Parse payload
        $data = json_decode($payload, true);
        if ($data === null) {
            $this->logger->error('Webhook payload parse failed', [
                'provider' => $provider
            ]);
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        // Handle based on provider
        try {
            return $this->processWebhook($provider, $data);

        } catch (\Exception $e) {
            $this->logger->error('Webhook processing failed', [
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);
            return new JsonResponse(['error' => 'Processing failed'], 500);
        }
    }

    private function getWebhookSecret(string $provider): string
    {
        return $this->config['webhooks']['providers'][$provider]['secret'] ?? '';
    }

    protected function processWebhook(string $provider, array $data): JsonResponse
    {
        // Implemented by subclasses
        throw new \RuntimeException('Not implemented');
    }
}

final class StripeWebhookHandler extends WebhookHandler
{
    public function __construct(
        LoggerInterface $logger,
        array $config,
        SignatureVerifier $signatureVerifier,
        private readonly PaymentService $paymentService,
        private readonly SubscriptionService $subscriptionService
    ) {
        parent::__construct($logger, $config, $signatureVerifier);
    }

    protected function processWebhook(string $provider, array $data): JsonResponse
    {
        $eventType = $data['type'] ?? 'unknown';

        $this->logger->info('Processing Stripe webhook', [
            'event_type' => $eventType,
            'event_id' => $data['id'] ?? null
        ]);

        return match ($eventType) {
            'payment_intent.succeeded' => $this->handlePaymentSucceeded($data),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($data),
            'customer.subscription.created' => $this->handleSubscriptionCreated($data),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($data),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($data),
            default => new JsonResponse(['handled' => false, 'reason' => 'Unknown event type'])
        };
    }

    private function handlePaymentSucceeded(array $data): JsonResponse
    {
        $paymentIntentId = $data['data']['object']['id'] ?? null;
        if ($paymentIntentId === null) {
            return new JsonResponse(['error' => 'Missing payment intent ID'], 400);
        }

        $result = $this->paymentService->confirmPayment($paymentIntentId);

        if ($result->isSuccessful()) {
            $this->logger->info('Payment confirmed via webhook', [
                'payment_intent' => $paymentIntentId
            ]);
            return new JsonResponse(['handled' => true]);
        }

        return new JsonResponse(['handled' => false, 'error' => 'Failed to confirm payment']);
    }

    private function handlePaymentFailed(array $data): JsonResponse
    {
        $paymentIntentId = $data['data']['object']['id'] ?? null;
        $errorMessage = $data['data']['object']['last_payment_error']['message'] ?? 'Unknown error';

        $this->logger->warning('Payment failed', [
            'payment_intent' => $paymentIntentId,
            'error' => $errorMessage
        ]);

        // Notify customer via email
        $this->paymentService->notifyPaymentFailed($paymentIntentId, $errorMessage);

        return new JsonResponse(['handled' => true]);
    }

    private function handleSubscriptionCreated(array $data): JsonResponse
    {
        $subscriptionId = $data['data']['object']['id'] ?? null;
        $customerId = $data['data']['object']['customer'] ?? null;

        $this->subscriptionService->createFromStripe($subscriptionId, $customerId);

        return new JsonResponse(['handled' => true]);
    }

    private function handleSubscriptionUpdated(array $data): JsonResponse
    {
        $subscriptionId = $data['data']['object']['id'] ?? null;
        $this->subscriptionService->updateFromStripe($subscriptionId);

        return new JsonResponse(['handled' => true]);
    }

    private function handleSubscriptionDeleted(array $data): JsonResponse
    {
        $subscriptionId = $data['data']['object']['id'] ?? null;
        $this->subscriptionService->cancelFromStripe($subscriptionId);

        return new JsonResponse(['handled' => true]);
    }
}

final class ShopifyWebhookHandler extends WebhookHandler
{
    public function __construct(
        LoggerInterface $logger,
        array $config,
        SignatureVerifier $signatureVerifier,
        private readonly OrderSyncService $orderSync
    ) {
        parent::__construct($logger, $config, $signatureVerifier);
    }

    protected function processWebhook(string $provider, array $data): JsonResponse
    {
        $topic = $data['topic'] ?? 'unknown';

        return match ($topic) {
            'orders/create' => $this->handleOrderCreated($data),
            'orders/updated' => $this->handleOrderUpdated($data),
            'orders/cancelled' => $this->handleOrderCancelled($data),
            default => new JsonResponse(['handled' => false])
        };
    }

    private function handleOrderCreated(array $data): JsonResponse
    {
        $shopifyOrderId = $data['data']['id'] ?? null;
        $this->orderSync->createFromShopify($shopifyOrderId, $data);

        return new JsonResponse(['handled' => true]);
    }

    private function handleOrderUpdated(array $data): JsonResponse
    {
        $shopifyOrderId = $data['data']['id'] ?? null;
        $this->orderSync->updateFromShopify($shopifyOrderId, $data);

        return new JsonResponse(['handled' => true]);
    }

    private function handleOrderCancelled(array $data): JsonResponse
    {
        $shopifyOrderId = $data['data']['id'] ?? null;
        $this->orderSync->cancelFromShopify($shopifyOrderId);

        return new JsonResponse(['handled' => true]);
    }
}
