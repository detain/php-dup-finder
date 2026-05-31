<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\EventDispatcher\EventDispatcherInterface;

/**
 * Base service class with event dispatching capability.
 * Centralizes EventDispatcherInterface injection.
 */
abstract class BaseEventfulService
{
    protected EventDispatcherInterface $eventDispatcher;

    public function __construct(EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    protected function dispatch(object $event): void
    {
        $this->eventDispatcher->dispatch($event);
    }
}

/**
 * Billing service extending base for event support.
 */
class BillingService extends BaseEventfulService
{
    private InvoiceRepositoryInterface $invoiceRepository;

    public function __construct(
        InvoiceRepositoryInterface $invoiceRepository,
        PaymentGatewayInterface $paymentGateway,
        EventDispatcherInterface $eventDispatcher
    ) {
        parent::__construct($eventDispatcher);
        $this->invoiceRepository = $invoiceRepository;
    }
}
