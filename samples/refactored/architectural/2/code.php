<?php
declare(strict_types=1);

namespace App\Cqrs;

interface CommandHandler
{
    public function handle(object $command): object;
}

final class CommandBus
{
    /** @param array<class-string, CommandHandler> $handlers */
    public function __construct(
        private array $handlers,
        private \Psr\Log\LoggerInterface $log,
    ) {}

    public function dispatch(object $command): object
    {
        $class = $command::class;
        $short = (new \ReflectionClass($class))->getShortName();
        $this->log->info("handling {$short}", ['cmd' => $class]);
        $handler = $this->handlers[$class]
            ?? throw new \InvalidArgumentException("No handler for {$class}");
        try {
            return $handler->handle($command);
        } catch (\Throwable $e) {
            throw new \RuntimeException("{$short} failed: " . $e->getMessage(), 0, $e);
        }
    }
}

/**
 * Each module now only ships:
 *   - a Command DTO  (e.g. RegisterUserCommand)
 *   - a Result DTO   (e.g. RegisterUserResult)
 *   - a Handler that implements CommandHandler
 *
 * Bus wiring becomes config:
 *
 *   new CommandBus([
 *       RegisterUserCommand::class  => $registerUserHandler,
 *       PublishPostCommand::class   => $publishPostHandler,
 *       RefundPaymentCommand::class => $refundPaymentHandler,
 *   ], $logger);
 */
