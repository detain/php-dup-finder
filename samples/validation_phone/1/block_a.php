<?php
declare(strict_types=1);

namespace Ecommerce\Checkout;

use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class BillingAddressHandler
{
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly LoggerInterface $logger,
        private readonly PhoneVerificationService $phoneVerifier
    ) {}

    public function processBillingInfo(Request $request): JsonResponse
    {
        $customerId = $this->requireCustomerId($request);

        $phone = $request->request->get('billing_phone', '');
        $country = $request->request->get('billing_country', 'US');

        // Validate phone number format
        $phoneValidation = $this->validatePhoneNumber($phone, $country);
        if (!$phoneValidation['valid']) {
            $this->logger->warning('Billing phone validation failed', [
                'customer_id' => $customerId,
                'reason' => $phoneValidation['error']
            ]);
            return $this->json(['error' => $phoneValidation['error']], 400);
        }

        $normalizedPhone = $phoneValidation['normalized'];

        // Verify phone ownership via SMS code
        if (!$this->phoneVerifier->isVerifiedForCustomer($customerId, $normalizedPhone)) {
            $code = $this->phoneVerifier->generateCode();
            $this->phoneVerifier->storePendingVerification($customerId, $normalizedPhone, $code);
            $this->phoneVerifier->sendSms($normalizedPhone, "Your verification code is: {$code}");

            return $this->json([
                'require_verification' => true,
                'message' => 'Please verify your phone number before proceeding'
            ], 403);
        }

        // Update billing address
        $this->entityManager->getRepository(Customer::class)
            ->find($customerId)
            ->setBillingPhone($normalizedPhone)
            ->setBillingCountry($country);

        $this->entityManager->flush();

        $this->logger->info('Billing phone updated', [
            'customer_id' => $customerId
        ]);

        return $this->json(['message' => 'Billing information saved']);
    }

    private function validatePhoneNumber(string $phone, string $country): array
    {
        // Remove all non-digit characters
        $digitsOnly = preg_replace('/\D/', '', $phone);

        // Check minimum length
        if (strlen($digitsOnly) < 10) {
            return ['valid' => false, 'error' => 'Phone number must have at least 10 digits'];
        }

        // Check maximum length
        if (strlen($digitsOnly) > 15) {
            return ['valid' => false, 'error' => 'Phone number cannot exceed 15 digits'];
        }

        // Validate country-specific format
        $countryFormats = [
            'US' => '/^1?([2-9]\d{9})$/',
            'CA' => '/^1?([2-9]\d{9})$/',
            'UK' => '/^44?([2-9]\d{9,10})$/',
            'AU' => '/^61?([2-9]\d{8})$/'
        ];

        if (isset($countryFormats[$country])) {
            if (!preg_match($countryFormats[$country], $digitsOnly)) {
                return [
                    'valid' => false,
                    'error' => "Invalid phone format for {$country}"
                ];
            }
        }

        // Normalize to E.164 format
        $normalized = '+' . ltrim($digitsOnly, '+');

        return ['valid' => true, 'normalized' => $normalized];
    }

    private function requireCustomerId(Request $request): int
    {
        $customerId = $request->request->getInt('customer_id');
        if ($customerId <= 0) {
            throw new \InvalidArgumentException('Customer ID is required');
        }
        return $customerId;
    }
}
