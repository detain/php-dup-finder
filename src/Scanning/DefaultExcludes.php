<?php
declare(strict_types=1);

namespace Phpdup\Scanning;

final class DefaultExcludes
{
    /** @return list<string> */
    public static function patterns(): array
    {
        return [
            'vendor/**',
            'node_modules/**',
            'logs/**',
            'cache/**',
            'storage/**',
            'build/**',
            'dist/**',
            '.git/**',
            '.svn/**',
            '.idea/**',
            '.vscode/**',
            '**/*.tpl.php',
            '**/*.blade.php',
            '**/.phpdup-cache/**',
            '**/phpdup-report/**',
        ];
    }
}
