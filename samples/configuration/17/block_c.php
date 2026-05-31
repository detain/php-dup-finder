<?php

declare(strict_types=1);

namespace App\Services\Logging;

use Psr\Log\LoggerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Processor\WebProcessor;
use Monolog\Processor\MemoryUsageProcessor;
use Monolog\Processor\GitProcessor;
use Monolog\Level;

final class LogAggregationService
{
    private const LOG_LEVEL = Level::Info;
    private const LOG_PATH = '/var/log/app';
    private const LOG_FILE_PREFIX = 'app';
    private const LOG_FILE_MAX_FILES = 30;
    private const LOG_FILE_MAX_SIZE = 104857600;
    private const LOG_ROTATION_ENABLED = true;
    private const LOG_FORMAT_JSON = true;
    private const LOG_INCLUDE_STACKTRACE = false;
    private const LOG_INCLUDE_CONTEXT = true;
    private const LOG_INCLUDE_PROCESSOR = true;
    private const LOG_BUFFER_SIZE = 100;
    private const LOG_FLUSH_INTERVAL = 60;
    private const LOG_ASYNC = false;
    private const LOG_ASYNC_QUEUE_SIZE = 1000;

    private Logger $logger;
    private array $buffer = [];
    private ?int $lastFlush = null;

    public function __construct(
        private readonly string $environment = 'production'
    ) {
        $this->logger = $this->createLogger();
        $this->lastFlush = time();
    }

    private function createLogger(): Logger
    {
        $level = self::LOG_LEVEL;
        $logPath = self::LOG_PATH;

        if (!is_dir($logPath)) {
            mkdir($logPath, 0755, true);
        }

        if (self::LOG_ROTATION_ENABLED) {
            $handler = new RotatingFileHandler(
                $logPath . '/' . self::LOG_FILE_PREFIX . '.log',
                self::LOG_FILE_MAX_FILES,
                $level
            );
        } else {
            $handler = new StreamHandler(
                $logPath . '/' . self::LOG_FILE_PREFIX . '.log',
                $level
            );
        }

        $handler->setFilenameFormat(
            '{filename}-{date}',
            'Y-m-d'
        );

        if (self::LOG_FORMAT_JSON) {
            $formatter = new JsonFormatter();
            $formatter->includeStacktraces = self::LOG_INCLUDE_STACKTRACE;
            $handler->setFormatter($formatter);
        }

        $logger = new Logger('app');
        $logger->pushHandler($handler);

        if (self::LOG_INCLUDE_PROCESSOR) {
            $logger->pushProcessor(new WebProcessor());
            $logger->pushProcessor(new MemoryUsageProcessor());
            $logger->pushProcessor(new IntrospectionProcessor($level, [
                'Monolog\\',
                'Illuminate\\',
                'Symfony\\',
            ]));
        }

        if ($this->environment === 'production') {
            $logger->pushProcessor(new GitProcessor());
        }

        return $logger;
    }

    public function log($level, string $message, array $context = []): void
    {
        $logLevel = $this->parseLogLevel($level);

        if ($logLevel > self::LOG_LEVEL) {
            return;
        }

        if (self::LOG_ASYNC) {
            $this->bufferLog($level, $message, $context);
        } else {
            $this->writeLog($level, $message, $context);
        }
    }

    private function parseLogLevel($level): Level
    {
        if ($level instanceof Level) {
            return $level;
        }

        if (is_string($level)) {
            return Level::fromName(strtoupper($level));
        }

        return Level::Info;
    }

    private function bufferLog($level, string $message, array $context): void
    {
        if (count($this->buffer) >= self::LOG_BUFFER_SIZE) {
            $this->flush();
        }

        $this->buffer[] = [
            'level' => $level,
            'message' => $message,
            'context' => self::LOG_INCLUDE_CONTEXT ? $context : [],
            'timestamp' => microtime(true),
        ];
    }

    private function writeLog($level, string $message, array $context = []): void
    {
        try {
            $logLevel = $this->parseLogLevel($level);

            $this->logger->log($logLevel, $message, self::LOG_INCLUDE_CONTEXT ? $context : []);
        } catch (\Exception $e) {
            error_log('Failed to write log: ' . $e->getMessage());
        }
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $bufferCount = count($this->buffer);

        foreach ($this->buffer as $entry) {
            $this->writeLog($entry['level'], $entry['message'], $entry['context']);
        }

        $this->buffer = [];
        $this->lastFlush = time();

        $this->logger->debug('Log buffer flushed', [
            'count' => $bufferCount,
            'buffer_size' => self::LOG_BUFFER_SIZE,
            'flush_interval' => self::LOG_FLUSH_INTERVAL,
        ]);
    }

    public function shouldFlush(): bool
    {
        if (empty($this->buffer)) {
            return false;
        }

        if (count($this->buffer) >= self::LOG_BUFFER_SIZE) {
            return true;
        }

        return (time() - $this->lastFlush) >= self::LOG_FLUSH_INTERVAL;
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(Level::Info, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(Level::Warning, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(Level::Error, $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(Level::Debug, $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log(Level::Critical, $message, $context);
    }

    public function getLogger(): Logger
    {
        return $this->logger;
    }

    public function getBufferSize(): int
    {
        return count($this->buffer);
    }
}
