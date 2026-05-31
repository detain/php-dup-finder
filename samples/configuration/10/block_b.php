<?php
declare(strict_types=1);

namespace Acme\Subsystems\Billing;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SamplingHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\IntrospectionProcessor;

final class BillingLoggerBootstrap
{
    public static function create(): Logger
    {
        $stream = new StreamHandler('php://stderr', Logger::INFO);
        $formatter = new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true);
        $formatter->includeStacktraces(true);
        $stream->setFormatter($formatter);

        $sampled = new SamplingHandler($stream, 10);

        $logger = new Logger('acme.subsystem.billing');
        $logger->pushHandler($sampled);
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushProcessor(new IntrospectionProcessor(Logger::INFO));
        $logger->pushProcessor(function (array $record): array {
            $record['extra']['env']     = 'prod';
            $record['extra']['service'] = 'acme';
            return $record;
        });

        return $logger;
    }

    public static function logCharge(Logger $log, int $invoiceId, int $cents): void
    {
        $log->info('billing.charge', ['invoice' => $invoiceId, 'cents' => $cents]);
    }
}
