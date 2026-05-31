<?php
declare(strict_types=1);

namespace Acme\Logging;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SamplingHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\IntrospectionProcessor;

final class LoggerFactory
{
    public const CHANNEL_PREFIX = 'acme.subsystem.';
    public const SAMPLE_FACTOR  = 10;
    public const STREAM_TARGET  = 'php://stderr';
    public const SERVICE_NAME   = 'acme';
    public const ENVIRONMENT    = 'prod';

    public static function for(string $subsystem): Logger
    {
        $stream    = new StreamHandler(self::STREAM_TARGET, Logger::INFO);
        $formatter = new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true);
        $formatter->includeStacktraces(true);
        $stream->setFormatter($formatter);

        $sampled = new SamplingHandler($stream, self::SAMPLE_FACTOR);

        $logger = new Logger(self::CHANNEL_PREFIX . $subsystem);
        $logger->pushHandler($sampled);
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushProcessor(new IntrospectionProcessor(Logger::INFO));
        $logger->pushProcessor(static function (array $record): array {
            $record['extra']['env']     = self::ENVIRONMENT;
            $record['extra']['service'] = self::SERVICE_NAME;
            return $record;
        });

        return $logger;
    }
}

// Usage:
// $authLog     = LoggerFactory::for('auth');
// $billingLog  = LoggerFactory::for('billing');
// $shippingLog = LoggerFactory::for('shipping');
