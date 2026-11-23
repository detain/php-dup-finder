<?php
declare(strict_types=1);

namespace Phpdup\Testing;

use Phpdup\Reporting\WritesReportFile;

/**
 * Generates synthetic PHP corpora with controlled duplication for
 * detection-rate fuzz testing.
 *
 * The generator produces N "templates" (parametrised function
 * skeletons), instantiates each K times with different hole-value
 * sets, and writes one file per instantiation. The intended
 * duplication topology is recoverable from the generator's plan,
 * so a fuzz test can compare phpdup's reported clusters against
 * the ground truth.
 *
 * Templates are intentionally simple — the goal is detection-rate
 * benchmarking, not realistic application code.
 *
 * Security contract (php-dup-finder-output-061): a plan is DATA, never
 * code. Every template name is validated against a strict identifier
 * whitelist before any file is written, so a key such as `../x` can no
 * longer escape the target directory through the filename sink. Every
 * hole value is rendered with {@see var_export()} into a PHP literal,
 * so plan-supplied text — `value`, `threshold` or `callee` — can never
 * break out of its position and execute in the generated file. The
 * output is always syntactically valid PHP regardless of plan content.
 */
final class FuzzCorpusGenerator
{
    use WritesReportFile;

    /**
     * Whitelist for a template name. It doubles as the path-segment
     * guard (no `/`, no `.` → no traversal) and as the identifier
     * guard for the namespace/class sinks; the generator prefixes each
     * interpolated position with a letter so even a digit-initial name
     * such as `9lives` yields a valid namespace segment and class name.
     */
    private const TEMPLATE_NAME_PATTERN = '/^[A-Za-z0-9_-]+$/';

    public function __construct(
        private readonly int $seed = 42,
    ) {
    }

    /**
     * @param array<string, list<array<string,string>>> $plan
     *   template-name → list of hole-value rows (one row per
     *   instantiation; each row maps hole-name → literal repr).
     * @return list<array{file:string,template:string,row:int}>
     * @throws \InvalidArgumentException When a template name is not a
     *   safe identifier (e.g. it carries a path separator).
     */
    public function generate(string $dir, array $plan): array
    {
        // Fail fast BEFORE writing anything: a hostile or malformed
        // plan must leave no half-written corpus behind, and the
        // filename/identifier sinks below all trust this whitelist.
        foreach (array_keys($plan) as $template) {
            if (preg_match(self::TEMPLATE_NAME_PATTERN, (string)$template) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'FuzzCorpusGenerator: template name %s is not a safe identifier'
                        . ' (must match %s — no path separators or code characters)',
                    var_export((string)$template, true),
                    self::TEMPLATE_NAME_PATTERN,
                ));
            }
        }

        $this->ensureDirectory($dir);
        // Seeded mt_rand keeps generation deterministic for fuzz tests.
        // We explicitly avoid \Random\Randomizer because that class is
        // PHP 8.2+ and this codebase's floor is 8.1.
        mt_srand($this->seed);
        $manifest = [];
        foreach ($plan as $template => $rows) {
            /** @var array<string,string> $holes */
            foreach ($rows as $idx => $holes) {
                $code = $this->renderTemplate((string)$template, $holes, (int)$idx);
                $file = sprintf('%s/%s_%03d.php', $dir, $template, $idx);
                // output-S54 (once-040/output-024): the manifest is a CLAIM
                // about ground truth — it may only be appended once the file
                // has actually landed byte-for-byte. A refused write now
                // aborts loudly instead of shipping a corpus whose manifest
                // lists files that were never written.
                $this->writeFile($file, $code);
                $manifest[] = ['file' => $file, 'template' => (string)$template, 'row' => (int)$idx];
            }
        }
        return $manifest;
    }

    /** @param array<string,string> $holes */
    private function renderTemplate(string $template, array $holes, int $idx): string
    {
        $value     = $holes['value']     ?? mt_rand(1, 99);
        $threshold = $holes['threshold'] ?? mt_rand(1, 99);
        $callee    = $holes['callee']    ?? 'doThing';

        // Every hole value is data, never code: var_export renders it as
        // a PHP literal so plan-supplied text cannot break out of its
        // position (php-dup-finder-output-061). $callee lands inside
        // $this->{...}, which accepts a string literal for the method
        // name; $value/$threshold land in expression position as plain
        // literals.
        $valueRepr     = var_export($value, true);
        $thresholdRepr = var_export($threshold, true);
        $calleeRepr    = var_export($callee, true);

        // Build the dynamic-method target with literal braces around the
        // quoted name so the generated call is conventional, parseable
        // PHP ($this->{'doThing'}(...)) rather than $this->{'...'} with the
        // braces eaten by the surrounding heredoc interpolation.
        $callTarget = '$this->{' . $calleeRepr . '}';

        // $template already matches the identifier whitelist (generate()
        // validated it), but the whitelist admits `-` (filename-safe) which
        // is NOT valid inside a PHP identifier. Map it to `_` for the
        // namespace/class sinks; the on-disk filename keeps the raw name.
        // Both segments are letter-initial, so they are always valid.
        $ident     = str_replace('-', '_', $template);
        $namespace = "Generated\\Gen_{$ident}_{$idx}";

        return <<<PHP
<?php
declare(strict_types=1);

namespace {$namespace};

class T_{$ident}
{
    public function process(\$item): mixed
    {
        if (\$item->score > {$thresholdRepr}) {
            return {$callTarget}({$valueRepr}, \$item);
        }
        \$result = [];
        foreach (\$item->children as \$child) {
            \$result[] = {$callTarget}({$valueRepr}, \$child);
        }
        return \$result;
    }
}

PHP;
    }
}
