<?php
declare(strict_types=1);

namespace Phpdup\Parsing;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Thin wrapper around nikic/php-parser that returns the file's top-level
 * statement list. Parse errors are caught and surfaced as null with the
 * error stored on $lastError so callers can decide how to log.
 */
final class AstParser
{
    private Parser $parser;
    public ?Error $lastError = null;

    public function __construct()
    {
        $factory = new ParserFactory();
        $this->parser = $factory->createForHostVersion();
    }

    /**
     * @return list<Node\Stmt>|null null on parse error
     */
    public function parseFile(string $path): ?array
    {
        $code = @file_get_contents($path);
        if ($code === false) {
            return null;
        }
        return $this->parseCode($code);
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
