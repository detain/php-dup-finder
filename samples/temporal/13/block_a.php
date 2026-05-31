<?php
declare(strict_types=1);

namespace Twilio\Verify\Service;

use Twilio\Verify\Repository\VerificationRepository;
use Twilio\Verify\Repository\ChallengeRepository;
use Twilio\Verify\Entity\Verification;
use Twilio\Verify\Entity\Challenge;
use Twilio\Verify\Entity\VerificationAttempt;
use Twilio\Verify\Exception\VerificationException;
use Twilio\Core\SMS\TwilioClient;
use Twilio\Core\Email\SMTPClient;
use Psr\Log\LoggerInterface;

final class MultiChannelVerificationService
{
    private VerificationRepository $verificationRepo;
    private ChallengeRepository $challengeRepo;
    private TwilioClient $twilioClient;
    private SMTPClient $smtpClient;
    private LoggerInterface $logger;

    public function __construct(
        VerificationRepository $verificationRepo,
        ChallengeRepository $challengeRepo,
        TwilioClient $twilioClient,
        SMTPClient $smtpClient,
        LoggerInterface $logger
    ) {
        $this->verificationRepo = $verificationRepo;
        $this->challengeRepo = $challengeRepo;
        $this->twilioClient = $twilioClient;
        $this->smtpClient = $smtpClient;
        $this->logger = $logger;
    }

    public function initiateVerification(string $channel, string $recipient, string $type): VerificationResult
    {
        $this->logger->info('Initiating verification', [
            'channel' => $channel,
            'recipient' => $recipient,
            'type' => $type
        ]);

        $existingVerification = $this->verificationRepo->findPendingVerification(
            $channel,
            $recipient
        );

        if ($existingVerification !== null) {
            $this->logger->debug('Clearing existing verification', [
                'verification_id' => $existingVerification->getId()
            ]);
            $this->verificationRepo->updateStatus($existingVerification->getId(), 'cancelled');
        }

        $code = $this->generateSecureCode($type);
        $expiresAt = (new \DateTimeImmutable())->modify('+10 minutes');

        $verification = Verification::create([
            'channel' => $channel,
            'recipient' => $recipient,
            'type' => $type,
            'code' => hash('sha256', $code),
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => new \DateTimeImmutable(),
            'expires_at' => $expiresAt
        ]);

        $savedVerification = $this->verificationRepo->save($verification);

        $challenge = Challenge::create([
            'verification_id' => $savedVerification->getId(),
            'challenge_type' => $type,
            'status' => 'pending',
            'created_at' => new \DateTimeImmutable()
        ]);
        $this->challengeRepo->save($challenge);

        try {
            $sent = match ($channel) {
                'sms' => $this->sendSmsVerification($recipient, $code),
                'email' => $this->sendEmailVerification($recipient, $code),
                'voice' => $this->sendVoiceVerification($recipient, $code),
                default => throw new \InvalidArgumentException("Unsupported channel: {$channel}")
            };

            if (!$sent) {
                throw new VerificationException('Failed to send verification code');
            }

            $this->verificationRepo->updateStatus($savedVerification->getId(), 'pending');

            $this->logger->info('Verification initiated successfully', [
                'verification_id' => $savedVerification->getId(),
                'channel' => $channel,
                'expires_at' => $expiresAt->format('c')
            ]);

            return new VerificationResult([
                'success' => true,
                'verification_id' => $savedVerification->getId(),
                'expires_at' => $expiresAt->format('c')
            ]);

        } catch (\Throwable $e) {
            $this->verificationRepo->updateStatus($savedVerification->getId(), 'failed');
            $this->logger->error('Verification initiation failed', [
                'recipient' => $recipient,
                'channel' => $channel,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function verifyCode(string $verificationId, string $code): VerifyResult
    {
        $verification = $this->verificationRepo->findById($verificationId);
        if ($verification === null) {
            throw new VerificationException('Verification not found');
        }

        if ($verification->getStatus() !== 'pending') {
            throw new VerificationException('Verification is not in pending status');
        }

        if ($verification->getExpiresAt() < new \DateTimeImmutable()) {
            $this->verificationRepo->updateStatus($verificationId, 'expired');
            throw new VerificationException('Verification code has expired');
        }

        if ($verification->getAttempts() >= 3) {
            $this->verificationRepo->updateStatus($verificationId, 'max_attempts_exceeded');
            throw new VerificationException('Maximum verification attempts exceeded');
        }

        $this->verificationRepo->incrementAttempts($verificationId);

        $codeHash = hash('sha256', $code);
        if (!hash_equals($verification->getCode(), $codeHash)) {
            $this->logger->warning('Invalid verification code attempt', [
                'verification_id' => $verificationId,
                'attempts' => $verification->getAttempts()
            ]);
            throw new VerificationException('Invalid verification code');
        }

        $this->verificationRepo->updateStatus($verificationId, 'verified');
        $this->challengeRepo->updateStatus($verification->getChallengeId(), 'verified');

        $this->logger->info('Verification successful', [
            'verification_id' => $verificationId
        ]);

        return new VerifyResult([
            'success' => true,
            'verified_at' => (new \DateTimeImmutable())->format('c')
        ]);
    }

    private function generateSecureCode(string $type): string
    {
        return match ($type) {
            'numeric' => (string) random_int(100000, 999999),
            'alphanumeric' => bin2hex(random_bytes(4)),
            default => (string) random_int(1000, 9999)
        };
    }

    private function sendSmsVerification(string $phone, string $code): bool
    {
        return $this->twilioClient->sendSms(
            $phone,
            "Your verification code is: {$code}"
        );
    }

    private function sendEmailVerification(string $email, string $code): bool
    {
        return $this->smtpClient->send(
            $email,
            'Your Verification Code',
            "Your verification code is: {$code}"
        );
    }

    private function sendVoiceVerification(string $phone, string $code): bool
    {
        return $this->twilioClient->initiateCall(
            $phone,
            'verification',
            ['code' => $code]
        );
    }
}
