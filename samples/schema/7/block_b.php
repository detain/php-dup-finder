<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Channel;

/**
 * Notification channel configuration schemas.
 * These configurations are duplicated from:
 * - Doctrine entity: NotificationTemplate
 * - Database table: notification_templates
 * - Template engine
 * - Delivery logging
 */
class NotificationChannelConfig
{
    public const EMAIL_CHANNEL = 'email';
    public const SMS_CHANNEL = 'sms';
    public const PUSH_CHANNEL = 'push';
    public const WEBHOOK_CHANNEL = 'webhook';

    /**
     * Email channel configuration schema.
     */
    public static function getEmailConfig(): array
    {
        return [
            'type' => 'object',
            'required' => ['from_address', 'from_name', 'smtp_host'],
            'properties' => [
                'from_address' => [
                    'type' => 'string',
                    'format' => 'email',
                    'description' => 'Sender email address',
                ],
                'from_name' => [
                    'type' => 'string',
                    'maxLength' => 255,
                    'description' => 'Sender display name',
                ],
                'reply_to' => [
                    'type' => 'string',
                    'format' => 'email',
                    'description' => 'Reply-to address',
                ],
                'smtp_host' => [
                    'type' => 'string',
                    'description' => 'SMTP server hostname',
                ],
                'smtp_port' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 65535,
                    'default' => 587,
                ],
                'smtp_username' => [
                    'type' => 'string',
                ],
                'smtp_password' => [
                    'type' => 'string',
                ],
                'smtp_encryption' => [
                    'type' => 'string',
                    'enum' => ['tls', 'ssl', null],
                    'default' => 'tls',
                ],
                'template_engine' => [
                    'type' => 'string',
                    'enum' => ['twig', 'handlebars', 'mustache'],
                    'default' => 'twig',
                ],
                'max_batch_size' => [
                    'type' => 'integer',
                    'default' => 100,
                ],
                'rate_limit_per_second' => [
                    'type' => 'integer',
                    'default' => 10,
                ],
            ],
        ];
    }

    /**
     * SMS channel configuration schema.
     */
    public static function getSmsConfig(): array
    {
        return [
            'type' => 'object',
            'required' => ['provider', 'api_key'],
            'properties' => [
                'provider' => [
                    'type' => 'string',
                    'enum' => ['twilio', 'nexmo', 'aws_sns', 'textlocal'],
                ],
                'api_key' => [
                    'type' => 'string',
                ],
                'api_secret' => [
                    'type' => 'string',
                ],
                'from_number' => [
                    'type' => 'string',
                    'description' => 'Sender phone number in E.164 format',
                ],
                'max_length' => [
                    'type' => 'integer',
                    'default' => 160,
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => ['low', 'normal', 'high'],
                    'default' => 'normal',
                ],
                'webhook_url' => [
                    'type' => 'string',
                    'format' => 'uri',
                    'description' => 'Status callback URL',
                ],
            ],
        ];
    }

    /**
     * Push notification channel configuration schema.
     */
    public static function getPushConfig(): array
    {
        return [
            'type' => 'object',
            'required' => ['provider', 'credentials'],
            'properties' => [
                'provider' => [
                    'type' => 'string',
                    'enum' => ['fcm', 'apns', 'webpush'],
                ],
                'credentials' => [
                    'type' => 'object',
                    'description' => 'Provider-specific credentials',
                ],
                'batch_size' => [
                    'type' => 'integer',
                    'default' => 500,
                ],
                'ttl_seconds' => [
                    'type' => 'integer',
                    'default' => 86400,
                    'description' => 'Time to live for notifications',
                ],
                'collapse_key' => [
                    'type' => 'string',
                    'description' => 'Collapse key for grouping',
                ],
            ],
        ];
    }

    /**
     * Webhook channel configuration schema.
     */
    public static function getWebhookConfig(): array
    {
        return [
            'type' => 'object',
            'required' => ['url', 'method'],
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'format' => 'uri',
                    'description' => 'Webhook endpoint URL',
                ],
                'method' => [
                    'type' => 'string',
                    'enum' => ['POST', 'PUT', 'PATCH'],
                    'default' => 'POST',
                ],
                'headers' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string'],
                ],
                'timeout_seconds' => [
                    'type' => 'integer',
                    'default' => 30,
                ],
                'retry_on_failure' => [
                    'type' => 'boolean',
                    'default' => true,
                ],
                'auth_type' => [
                    'type' => 'string',
                    'enum' => ['none', 'basic', 'bearer', 'hmac'],
                ],
                'auth_credentials' => [
                    'type' => 'object',
                ],
            ],
        ];
    }

    public static function getAllChannels(): array
    {
        return [
            self::EMAIL_CHANNEL => self::getEmailConfig(),
            self::SMS_CHANNEL => self::getSmsConfig(),
            self::PUSH_CHANNEL => self::getPushConfig(),
            self::WEBHOOK_CHANNEL => self::getWebhookConfig(),
        ];
    }
}
