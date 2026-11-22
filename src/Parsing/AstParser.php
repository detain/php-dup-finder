<?php
declare(strict_types=1);

namespace Phpdup\Parsing;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Thin wrapper around nikic/php-parser that returns the file's top-level
 * statement list. Read failures (missing, removed, permission-denied files)
 * and parse errors are both surfaced as null with the reason stored on
 * $lastError so callers can decide how to log; a successful call clears it.
 */
final class AstParser
{
    private Parser $parser;

    /** Reason for the most recent parseFile()/parseCode() failure — a
     *  php-parser error, or an Error recording a read failure
     *  ("could not read file <path>: <diagnostic>"); null after success. */
    public ?Error $lastError = null;

    public function __construct()
    {
        $factory = new ParserFactory();
        $this->parser = $factory->createForHostVersion();
    }

    /**
     * @return list<Node\Stmt>|null null when the file cannot be read or cannot
     *                              be parsed; $lastError holds the reason in
     *                              either case
     */
    public function parseFile(string $path): ?array
    {
        error_clear_last();
        $code = @file_get_contents($path);
        if ($code === false) {
            $this->lastError = new Error(
                sprintf('could not read file %s: %s', $path, self::describeReadFailure($path))
            );
            return null;
        }
        return $this->parseCode($code);
    }

    /**
     * Pull the suppressed diagnostic (no such file, permission denied, ...)
     * explaining why the read failed. The error slot is cleared right before
     * the read, so error_get_last() is guaranteed fresh.
     */
    private static function describeReadFailure(string $path): string
    {
        $failure = error_get_last();
        $message = is_array($failure) ? $failure['message'] : '';
        if ($message === '') {
            return 'read failed';
        }

        // PHP prefixes 'file_get_contents(<path>): '; the path already names
        // itself in the surrounding message, so drop the duplicated preamble.
        $preamble = sprintf('file_get_contents(%s): ', $path);
        if (str_starts_with($message, $preamble)) {
            $message = substr($message, strlen($preamble));
        }
        return $message;
    }

    /**
     * @return list<Node\Stmt>|null
     */
    public function parseCode(string $code): ?array
    {
        $this->lastError = null;
        try {
            $stmts = $this->parser->parse($code);
            return $stmts ?? [];
        } catch (Error $e) {
            $this->lastError = $e;
            return null;
        }
    }
}
