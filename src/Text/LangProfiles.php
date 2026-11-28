<?php
declare(strict_types=1);

namespace Phpdup\Text;

/**
 * Item 7.1: the shipped language table of the native token tier.
 *
 * Extension (lower-cased, no dot) → LangProfile. This table is the tier's
 * admission surface: the scanner widens to exactly these extensions when
 * the tier is armed (kind 'text' named explicitly — see
 * {@see \Phpdup\Cli\Config::tokenTierArmed()}), and the harvester tokenizes
 * each admitted file with its profile. An extension missing here is inert
 * even under an armed run (it never reaches the tier at all).
 *
 * Deliberate absences: 'blade' (whose real files are *.blade.php — the PHP
 * extension already admits them through the primary gate) and 'php'
 * variants (the AST path owns PHP; the token tier is the non-PHP sibling).
 * 'md'/'txt' prose is left out on purpose: free text produces no honest
 * type-1/2 clones at token level and would flood the df census with natural
 * language windows.
 */
final class LangProfiles
{
    /**
     * @var array<string,LangProfile>|null extension → profile, minted once
     */
    private static ?array $table = null;

    /**
     * @return array<string,LangProfile>
     */
    public static function all(): array
    {
        if (self::$table !== null) {
            return self::$table;
        }

        $jsKeywords = [
            'if', 'else', 'for', 'while', 'do', 'return', 'function', 'class', 'new', 'this',
            'null', 'true', 'false', 'undefined', 'try', 'catch', 'finally', 'throw', 'typeof',
            'instanceof', 'in', 'of', 'let', 'const', 'var', 'async', 'await', 'yield', 'switch',
            'case', 'break', 'default', 'extends', 'import', 'export', 'from', 'delete', 'void',
            'static', 'get', 'set', 'super', 'continue',
        ];
        $shellKeywords = ['if', 'then', 'else', 'elif', 'fi', 'do', 'done', 'case', 'esac', 'for', 'while', 'until', 'in', 'function', 'select', 'time'];
        $yamlKeywords  = ['true', 'false', 'null', 'yes', 'no', 'on', 'off', 'and', 'or', 'not'];
        $twigKeywords  = [
            'if', 'else', 'elseif', 'endif', 'for', 'endfor', 'foreach', 'endforeach', 'while',
            'endwhile', 'set', 'block', 'endblock', 'extends', 'embed', 'endembed', 'include',
            'import', 'use', 'as', 'macro', 'endmacro', 'autoescape', 'endautoescape', 'spaceless',
            'endspaceless', 'raw', 'do', 'flush', 'with', 'without', 'and', 'or', 'not', 'in',
            'is', 'defined', 'same', 'null', 'true', 'false',
        ];

        $profiles = [
            'js'   => new LangProfile('javascript', lineComments: ['//'], blockComments: [['/*', '*/']], backtick: true, keywords: $jsKeywords),
            'mjs'  => new LangProfile('javascript', lineComments: ['//'], blockComments: [['/*', '*/']], backtick: true, keywords: $jsKeywords),
            'cjs'  => new LangProfile('javascript', lineComments: ['//'], blockComments: [['/*', '*/']], backtick: true, keywords: $jsKeywords),
            'jsx'  => new LangProfile('javascript', lineComments: ['//'], blockComments: [['/*', '*/']], backtick: true, keywords: $jsKeywords),
            'ts'   => new LangProfile('typescript', lineComments: ['//'], blockComments: [['/*', '*/']], backtick: true, keywords: $jsKeywords),
            'tsx'  => new LangProfile('typescript', lineComments: ['//'], blockComments: [['/*', '*/']], backtick: true, keywords: $jsKeywords),
            'json' => new LangProfile('json'),
            'tpl'  => new LangProfile('template', blockComments: [['<!--', '-->']], keywords: $twigKeywords),
            'twig' => new LangProfile('template', blockComments: [['{#', '#}'], ['<!--', '-->']], keywords: $twigKeywords),
            'yaml' => new LangProfile('yaml', lineComments: ['#'], keywords: $yamlKeywords),
            'yml'  => new LangProfile('yaml', lineComments: ['#'], keywords: $yamlKeywords),
            'sh'   => new LangProfile('shell', lineComments: ['#'], backtick: true, keywords: $shellKeywords),
            'bash' => new LangProfile('shell', lineComments: ['#'], backtick: true, keywords: $shellKeywords),
            'zsh'  => new LangProfile('shell', lineComments: ['#'], backtick: true, keywords: $shellKeywords),
            'css'  => new LangProfile('css', blockComments: [['/*', '*/']]),
            'scss' => new LangProfile('css', lineComments: ['//'], blockComments: [['/*', '*/']]),
            'html' => new LangProfile('html', blockComments: [['<!--', '-->']]),
        ];

        return self::$table = $profiles;
    }

    /** Profile of the file behind $path, or null when the tier does not speak it. */
    public static function profileFor(string $path): ?LangProfile
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $ext === '' ? null : (self::all()[$ext] ?? null);
    }

    /**
     * The extension list the scanner gate admits when the tier is armed.
     *
     * @return list<string>
     */
    public static function extensions(): array
    {
        return array_keys(self::all());
    }
}
