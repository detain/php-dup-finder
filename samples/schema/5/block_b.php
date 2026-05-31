<?php

declare(strict_types=1);

namespace App\Infrastructure\PaymentGateway;

/**
 * Payment gateway API schema definitions.
 * These schemas are duplicated from:
 * - Doctrine entity: PaymentTransaction
 * - Database table: payment_transactions
 * - Webhook payload schemas
 * - Reporting database
 */
class PaymentGatewaySchema
{
    public const CREATE_PAYMENT_INTENT_SCHEMA = [
        'type' => 'object',
        'required' => ['amount', 'currency', 'payment_method'],
        'properties' => [
            'amount' => [
                'type' => 'integer',
                'description' => 'Amount in smallest currency unit (cents)',
                'minimum' => 1,
                'example' => 2999,
            ],
            'currency' => [
                'type' => 'string',
                'description' => 'ISO 4217 currency code',
                'pattern' => '^[A-Z]{3}$',
                'example' => 'USD',
            ],
            'payment_method' => [
                'type' => 'string',
                'enum' => ['card', 'bank_account', 'paypal', 'apple_pay', 'google_pay'],
            ],
            'customer_id' => [
                'type' => 'string',
                'description' => 'Customer identifier in merchant system',
            ],
            'order_id' => [
                'type' => 'string',
                'description' => 'Associated order identifier',
            ],
            'description' => [
                'type' => 'string',
                'maxLength' => 500,
            ],
            'metadata' => [
                'type' => 'object',
                'additionalProperties' => true,
            ],
            'return_url' => [
                'type' => 'string',
                'format' => 'uri',
                'description' => 'URL to redirect after payment',
            ],
        ],
    ];

    public const PAYMENT_INTENT_RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'id' => [
                'type' => 'string',
                'description' => 'Payment intent identifier',
            ],
            'object' => [
                'type' => 'string',
                'const' => 'payment_intent',
            ],
            'amount' => ['type' => 'integer'],
            'currency' => ['type' => 'string'],
            'status' => [
                'type' => 'string',
                'enum' => ['requires_payment_method', 'requires_confirmation', 'requires_action', 'processing', 'requires_capture', 'canceled', 'succeeded'],
            ],
            'client_secret' => [
                'type' => 'string',
                'description' => 'Client secret for frontend confirmation',
            ],
            'payment_method' => ['type' => 'string'],
            'customer_id' => ['type' => 'string'],
            'order_id' => ['type' => 'string'],
            'created' => [
                'type' => 'integer',
                'description' => 'Unix timestamp of creation',
            ],
            'modified' => [
                'type' => 'integer',
                'description' => 'Unix timestamp of last modification',
            ],
            'last_payment_error' => [
                'type' => 'object',
                'properties' => [
                    'code' => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                    'decline_code' => ['type' => 'string'],
                ],
            ],
            'metadata' => ['type' => 'object'],
        ],
    ];

    public const REFUND_REQUEST_SCHEMA = [
        'type' => 'object',
        'required' => ['transaction_id'],
        'properties' => [
            'transaction_id' => [
                'type' => 'string',
                'description' => 'Original transaction to refund',
            ],
            'amount' => [
                'type' => 'integer',
                'description' => 'Amount to refund in smallest currency unit',
                'minimum' => 1,
            ],
            'reason' => [
                'type' => 'string',
                'enum' => ['duplicate', 'fraudulent', 'requested_by_customer', 'other'],
            ],
            'metadata' => [
                'type' => 'object',
                'additionalProperties' => true,
            ],
        ],
    ];

    public const WEBHOOK_EVENT_SCHEMA = [
        'type' => 'object',
        'required' => ['id', 'type', 'created_at', 'data'],
        'properties' => [
            'id' => ['type' => 'string'],
            'type' => [
                'type' => 'string',
                'enum' => [
                    'payment_intent.succeeded',
                    'payment_intent.payment_failed',
                    'payment_intent.canceled',
                    'charge.refunded',
                    'charge.dispute.created',
                ],
            ],
            'created_at' => ['type' => 'integer'],
            'livemode' => ['type' => 'boolean'],
            'data' => [
                'type' => 'object',
                'properties' => [
                    'object' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'amount' => ['type' => 'integer'],
                            'currency' => ['type' => 'string'],
                            'status' => ['type' => 'string'],
                            'customer_id' => ['type' => 'string'],
                            'order_id' => ['type' => 'string'],
                            'payment_method' => ['type' => 'string'],
                            'gateway_response' => ['type' => 'object'],
                            'created' => ['type' => 'integer'],
                            'modified' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ],
    ];
}
