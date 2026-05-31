<?php

declare(strict_types=1);

namespace Acme\Bus\Command;

use Acme\Bus\CommandHandlerInterface;
use Acme\Bus\CommandResult;
use Acme\Metrics\Counter;
use Psr\Log\LoggerInterface;

final class CommandBus
{
    /** @param array<class-string, CommandHandlerInterface> $handlers */
    public function __construct(
        private readonly array $handlers,
        private readonly Counter $counter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(object $command): CommandResult
    {
        $type = $command::class;
        $handler = $this->handlers[$type] ?? null;
        if ($handler === null) {
            $this->counter->inc('command.unknown', ['type' => $type]);
            return CommandResult::failure("no handler for {$type}");
        }

        $start = microtime(true);
        try {
            $result = $handler->handle($command);
            $elapsedMs = (int) ((microtime(true) - $start) * 1000);
            $this->counter->inc('command.success', ['type' => $type]);
            $this->counter->observe('command.duration_ms', $elapsedMs, ['type' => $type]);
            $this->logger->info('command executed', ['type' => $type, 'ms' => $elapsedMs]);

            return CommandResult::ok($result);
        } catch (\Throwable $e) {
            $this->counter->inc('command.error', ['type' => $type]);
            $this->logger->error('command error', ['type' => $type, 'error' => $e->getMessage()]);
            return CommandResult::failure($e->getMessage());
        }
    }
}
