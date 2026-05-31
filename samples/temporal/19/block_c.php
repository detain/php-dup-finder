<?php
declare(strict_types=1);

namespace Twilio\Verify\Service;

use Twilio\Verify\Repository\VerificationRepository;
use Twilio\Verify\Repository\CheckRepository;
use Twilio\Verify\Repository\ServiceRepository;
use Twilio\Verify\Entity\Verification;
use Twilio\Verify\Entity\Check;
use Twilio\Verify\Entity\VerificationService;
use Twilio\Verify\Exception\VerificationException;
use Twilio\Verify\Service\Backend\BackendValidator;
use Psr\Log\LoggerInterface;

final class VerificationOrchestrationService
{
    private VerificationRepository $verificationRepo;
    private CheckRepository $checkRepo;
    private ServiceRepository $serviceRepo;
    private BackendValidator $backendValidator;
    private LoggerInterface $logger;

    public function __construct(
        VerificationRepository $verificationRepo,
        CheckRepository $checkRepo,
        ServiceRepository $serviceRepo,
        BackendValidator $backendValidator,
        LoggerInterface $logger
    ) {
        $this->verificationRepo = $verificationRepo;
        $this->checkRepo = $checkRepo;
        $this->serviceRepo = $serviceRepo;
        $this->backendValidator = $backendValidator;
        $this->logger = $logger;
    }

    public function startVerification(string $serviceSid, array $verificationData): VerificationResult
    {
        $this->logger->info('Starting verification', [
            'service_sid' => $serviceSid,
            'channel' => $verificationData['channel'] ?? 'unknown'
        ]);

        $service = $this->serviceRepo->findBySid($serviceSid);
        if ($service === null) {
            throw new VerificationException("Verification service not found: {$serviceSid}");
        }

        if (!$service->isEnabled()) {
            throw new VerificationException("Verification service is disabled: {$serviceSid}");
        }

        $to = $verificationData['to'];
        $channel = $verificationData['channel'] ?? 'sms';

        $rateLimit = $this->checkRepo->getRecentVerificationCount($to, $channel);
        if ($rateLimit >= $service->getRateLimitPerHour()) {
            throw new VerificationException('Rate limit exceeded for channel');
        }

        $verificationLock = $this->verificationRepo->acquireVerificationLock($to, $channel);
        if ($verificationLock === null) {
            throw new VerificationException("Could not acquire verification lock for: {$to}");
        }

        $this->logger->debug('Verification lock acquired', ['to' => $to]);

        try {
            $this->verificationRepo->cancelPendingVerifications($to, $channel);

            $code = $this->generateVerificationCode($channel);

            $verification = Verification::create([
                'service_sid' => $serviceSid,
                'to' => $to,
                'channel' => $channel,
                'code' => hash('sha256', $code),
                'status' => 'pending',
                'ttl' => $service->getTtlSeconds(),
                'created_at' => new \DateTimeImmutable(),
                'expires_at' => (new \DateTimeImmutable())->modify("+{$service->getTtlSeconds()} seconds")
            ]);

            $savedVerification = $this->verificationRepo->save($verification);
            $this->logger->debug('Verification record created', [
                'verification_sid' => $savedVerification->getSid()
            ]);

            $sent = $this->sendVerificationCode($savedVerification, $code, $channel);

            if (!$sent) {
                $this->verificationRepo->updateStatus($savedVerification->getSid(), 'failed');
                throw new VerificationException('Failed to send verification code');
            }

            $this->verificationRepo->updateStatus($savedVerification->getSid(), 'sent', [
                'sent_at' => new \DateTimeImmutable()
            ]);

            $this->verificationRepo->recordCheck($to, $channel);

            $this->verificationRepo->releaseVerificationLock($verificationLock);

            $this->logger->info('Verification sent successfully', [
                'verification_sid' => $savedVerification->getSid(),
                'channel' => $channel,
                'to' => $this->maskValue($to)
            ]);

            return new VerificationResult([
                'success' => true,
                'verification_sid' => $savedVerification->getSid(),
                'status' => 'sent',
                'valid_until' => $verification->getExpiresAt()->format('c')
            ]);

        } catch (\Throwable $e) {
            $this->verificationRepo->releaseVerificationLock($verificationLock);
            $this->logger->error('Verification start failed', [
                'to' => $this->maskValue($to),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function checkVerification(string $serviceSid, string $to, string $code): CheckResult
    {
        $verification = $this->verificationRepo->findLatestByToAndService($to, $serviceSid);
        if ($verification === null) {
            throw new VerificationException('No pending verification found');
        }

        if ($verification->getStatus() !== 'sent') {
            throw new VerificationException("Verification is not in valid status: {$verification->getStatus()}");
        }

        if ($verification->isExpired()) {
            $this->verificationRepo->updateStatus($verification->getSid(), 'expired');
            throw new VerificationException('Verification has expired');
        }

        $attempts = $this->verificationRepo->getAttemptCount($verification->getSid());
        if ($attempts >= $verification->getMaxAttempts()) {
            $this->verificationRepo->updateStatus($verification->getSid(), 'max_attempts_exceeded');
            throw new VerificationException('Maximum verification attempts exceeded');
        }

        $checkLock = $this->checkRepo->acquireCheckLock($verification->getSid());
        if ($checkLock === null) {
            throw new VerificationException('Could not acquire check lock');
        }

        try {
            $this->verificationRepo->recordAttempt($verification->getSid());

            $codeHash = hash('sha256', $code);
            $isValid = hash_equals($verification->getCode(), $codeHash);

            if ($isValid) {
                $this->verificationRepo->updateStatus($verification->getSid(), 'approved');
                $this->verificationRepo->recordSuccessfulCheck($to);

                $this->logger->info('Verification approved', [
                    'verification_sid' => $verification->getSid()
                ]);

                return new CheckResult([
                    'success' => true,
                    'status' => 'approved'
                ]);
            }

            $this->logger->warning('Invalid verification code', [
                'verification_sid' => $verification->getSid(),
                'attempts' => $attempts + 1
            ]);

            return new CheckResult([
                'success' => false,
                'status' => 'pending',
                'attempts_remaining' => $verification->getMaxAttempts() - $attempts - 1
            ]);

        } finally {
            $this->checkRepo->releaseCheckLock($checkLock);
        }
    }

    private function generateVerificationCode(string $channel): string
    {
        return match ($channel) {
            'sms', 'voice' => (string) random_int(100000, 999999),
            'email' => bin2hex(random_bytes(4)),
            default => (string) random_int(1000, 9999)
        };
    }

    private function sendVerificationCode(Verification $verification, string $code, string $channel): bool
    {
        return match ($channel) {
            'sms' => $this->backendValidator->sendSms($verification->getTo(), $code),
            'email' => $this->backendValidator->sendEmail($verification->getTo(), $code),
            'voice' => $this->backendValidator->initiateVoiceCall($verification->getTo(), $code),
            default => false
        };
    }

    private function maskValue(string $value): string
    {
        if (strlen($value) <= 4) {
            return '****';
        }
        return substr($value, 0, 2) . '****' . substr($value, -2);
    }
}
