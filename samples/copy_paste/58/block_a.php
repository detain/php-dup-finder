<?php

declare(strict_types=1);

namespace App\Helpers;

use Throwable;
use ErrorException;
use Error;

class ErrorFormatter
{
    public static function formatException(Throwable $exception, bool $includeTrace = false): array
    {
        $result = [
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'type' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        if ($includeTrace) {
            $result['trace'] = self::formatTrace($exception->getTrace());
        }

        return $result;
    }

    public static function formatErrorException(ErrorException $exception): array
    {
        return [
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'severity' => self::getErrorSeverityName($exception->getSeverity()),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }

    public static function formatError(Error $error): array
    {
        return [
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'type' => get_class($error),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }

    public static function formatTrace(array $trace): array
    {
        $formatted = [];

        foreach ($trace as $index => $frame) {
            $formatted[] = [
                'index' => $index,
                'file' => $frame['file'] ?? 'unknown',
                'line' => $frame['line'] ?? 0,
                'function' => self::formatFunctionName($frame),
                'args' => self::formatArgs($frame['args'] ?? []),
            ];
        }

        return $formatted;
    }

    public static function formatFunctionName(array $frame): string
    {
        $class = $frame['class'] ?? '';
        $type = $frame['type'] ?? '';
        $function = $frame['function'] ?? '';

        if ($class && $function) {
            return $class . $type . $function;
        }

        return $function ?: 'unknown';
    }

    public static function formatArgs(array $args): array
    {
        $formatted = [];

        foreach ($args as $arg) {
            $formatted[] = self::formatArg($arg);
        }

        return $formatted;
    }

    public static function formatArg(mixed $arg): string
    {
        if (is_null($arg)) {
            return 'null';
        }

        if (is_bool($arg)) {
            return $arg ? 'true' : 'false';
        }

        if (is_int($arg) || is_float($arg)) {
            return (string) $arg;
        }

        if (is_string($arg)) {
            return strlen($arg) > 50 ? substr($arg, 0, 50) . '...' : $arg;
        }

        if (is_array($arg)) {
            return 'Array(' . count($arg) . ')';
        }

        if (is_object($arg)) {
            return get_class($arg);
        }

        if (is_resource($arg)) {
            return 'Resource(' . get_resource_type($arg) . ')';
        }

        return 'unknown';
    }

    public static function getErrorSeverityName(int $severity): string
    {
        return match ($severity) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'E_UNKNOWN',
        };
    }

    public static function formatForLogging(Throwable $exception): string
    {
        $formatted = self::formatException($exception, true);

        return sprintf(
            "[%s] %s: %s in %s on line %d\nTrace:\n%s",
            $formatted['timestamp'],
            $formatted['type'],
            $formatted['message'],
            $formatted['file'],
            $formatted['line'],
            self::formatTraceAsString($formatted['trace'])
        );
    }

    public static function formatTraceAsString(array $trace): string
    {
        $lines = [];

        foreach ($trace as $frame) {
            $lines[] = sprintf(
                '  #%d %s(%d): %s()',
                $frame['index'],
                $frame['file'],
                $frame['line'],
                $frame['function']
            );
        }

        return implode("\n", $lines);
    }

    public static function getErrorType(Throwable $exception): string
    {
        if ($exception instanceof \App\Exceptions\ValidationException) {
            return 'validation';
        }

        if ($exception instanceof \App\Exceptions\NotFoundException) {
            return 'not_found';
        }

        if ($exception instanceof \App\Exceptions\AuthenticationException) {
            return 'authentication';
        }

        if ($exception instanceof \App\Exceptions\AuthorizationException) {
            return 'authorization';
        }

        if ($exception instanceof \App\Exceptions\RateLimitException) {
            return 'rate_limit';
        }

        return 'general';
    }
}
