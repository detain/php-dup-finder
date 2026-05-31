<?php
declare(strict_types=1);

namespace Ecommerce\Account;

use Doctrine\ORM\EntityRepository;
use Psr\Log\LoggerInterface;

final class TwoFactorAuthService
{
    public function __construct(
        private readonly EntityRepository $userRepo,
        private readonly LoggerInterface $logger,
        private readonly TotpService $totpService,
        private readonly SmsGateway $smsGateway
    ) {}

    public function enableTwoFactor(User $user, string $phone, string $method = 'sms'): EnableResult
    {
        // Validate phone number for SMS delivery
        $phoneValidation = $this->validatePhoneForSms($phone);
        if (!$phoneValidation['valid']) {
            $this->logger->warning('2FA enable failed: invalid phone', [
                'user_id' => $user->getId(),
                'error' => $phoneValidation['error']
            ]);
            return EnableResult::failure($phoneValidation['error']);
        }

        $normalizedPhone = $phoneValidation['normalized'];

        // Generate secret
        $secret = $this->totpService->generateSecret();

        // For SMS method, send test message
        if ($method === 'sms') {
            $testCode = $this->totpService->generateCode($secret);
            $sent = $this->smsGateway->send(
                $normalizedPhone,
                "Your two-factor authentication code is: {$testCode}"
            );

            if (!$sent) {
                $this->logger->error('2FA setup failed: could not send test SMS', [
                    'user_id' => $user->getId()
                ]);
                return EnableResult::failure('Failed to send verification SMS. Please check the phone number.');
            }
        }

        // Store pending 2FA setup
        $user->setTwoFactorPending(true);
        $user->setTwoFactorPhone($normalizedPhone);
        $user->setTwoFactorMethod($method);
        $user->setTwoFactorSecret($secret);

        $this->userRepo->getEntityManager()->flush();

        $this->logger->info('Two-factor authentication setup initiated', [
            'user_id' => $user->getId(),
            'method' => $method
        ]);

        return EnableResult::pending('Please verify your phone number to complete setup');
    }

    private function validatePhoneForSms(string $phone): array
    {
        // Extract digits
        $stripped = preg_replace('/[^0-9+]/', '', $phone);
        $digitsOnly = preg_replace('/\D/', '', $stripped);

        // Validate character content
        if (preg_match('/[^0-9\+]/', $phone)) {
            return ['valid' => false, 'error' => 'Phone number contains invalid characters'];
        }

        // Country code detection
        $countryCode = null;
        if (str_starts_with($digitsOnly, '1') && strlen($digitsOnly) === 11) {
            $countryCode = '1';
            $localNumber = substr($digitsOnly, 1);
        } elseif (str_starts_with($digitsOnly, '44') && strlen($digitsOnly) >= 12) {
            $countryCode = '44';
            $localNumber = substr($digitsOnly, 2);
        } elseif (str_starts_with($digitsOnly, '61') && strlen($digitsOnly) === 11) {
            $countryCode = '61';
            $localNumber = substr($digitsOnly, 2);
        } else {
            $localNumber = $digitsOnly;
        }

        // Validate local number length
        $localLength = strlen($localNumber);
        if ($localLength < 9 || $localLength > 10) {
            return ['valid' => false, 'error' => 'Phone number must have 9-10 local digits'];
        }

        // Build E.164 format
        if ($countryCode !== null) {
            $normalized = '+' . $countryCode . $localNumber;
        } else {
            $normalized = '+' . ltrim($digitsOnly, '+');
        }

        // Validate format with regex
        if (!preg_match('/^\+[1-9]\d{9,14}$/', $normalized)) {
            return ['valid' => false, 'error' => 'Phone number format is not recognized'];
        }

        return ['valid' => true, 'normalized' => $normalized];
    }
}
