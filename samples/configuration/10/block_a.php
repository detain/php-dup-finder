<?php
declare(strict_types=1);

namespace Acme\Subsystems\Auth;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SamplingHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\IntrospectionProcessor;

final class AuthLoggerBootstrap
{
    public static function create(): Logger
    {
        $stream = new StreamHandler('php://stderr', Logger::INFO);
        $formatter = new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true);
        $formatter->includeStacktraces(true);
        $stream->setFormatter($formatter);

        $sampled = new SamplingHandler($stream, 10);

        $logger = new Logger('acme.subsystem.auth');
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

    public static function logSignIn(Logger $log, string $userId, string $ip): void
    {
        $log->info('auth.signin', ['user' => $userId, 'ip' => $ip]);
    }
}
