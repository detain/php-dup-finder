<?php
declare(strict_types=1);

namespace Phpdup\Architecture;

/**
 * One architectural observation about a cluster — emitted by an
 * {@see ArchitecturalAnalyzer}. Reporters render these alongside
 * pattern tags so reviewers can see "this cluster smells like a
 * God Class" without having to crawl the diff.
 *
 * Severity is one of:
 *   - 'note'     informational; no action required
 *   - 'warning'  worth investigating
 *   - 'error'    likely a real architectural issue
 */
final class Finding
{
    public const SEVERITY_NOTE    = 'note';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ERROR   = 'error';

    public function __construct(
        public readonly string $analyzer,
        public readonly string $code,
        public readonly string $message,
        public readonly string $severity = self::SEVERITY_NOTE,
        public readonly ?string $suggestion = null,
    ) {
    }
}
