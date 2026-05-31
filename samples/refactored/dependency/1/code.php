<?php

declare(strict_types=1);

namespace App\Application;

use Psr\Log\LoggerInterface;

/**
 * Base service class providing common dependencies.
 * Centralizes LoggerInterface injection to avoid duplication.
 */
abstract class BaseService
{
    protected LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
}

/**
 * Order service extending base to inherit logger.
 */
class OrderService extends BaseService
{
    private OrderRepositoryInterface $orderRepository;
    private EventDispatcher $eventDispatcher;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        EventDispatcher $eventDispatcher,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
        $this->orderRepository = $orderRepository;
        $this->eventDispatcher = $eventDispatcher;
    }
}
