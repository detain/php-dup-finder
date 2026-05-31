<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Infrastructure\Config\ConfigService;

/**
 * Centralized notification service providing all notification types.
 * Eliminates duplication of ConfigService injection.
 */
class CentralizedNotificationService
{
    private ConfigService $config;
    private EmailService $emailService;
    private SmsService $smsService;
    private PushNotificationService $pushService;

    public function __construct(ConfigService $config)
    {
        $this->config = $config;
        $this->emailService = new EmailService($config);
        $this->smsService = new SmsService($config);
        $this->pushService = new PushNotificationService($config);
    }
}
