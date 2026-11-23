<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Refactor\CodeParam;
use Phpdup\Refactor\Hole;
use Phpdup\Util\ControlText;

/**
 * Generates PHPUnit test skeletons for the suggested abstraction.
 *
 * Produces one test class per cluster with a data-provider that
 * iterates the holes' observed values across cluster members. The
 * test bodies are intentionally `markTestIncomplete()` placeholders
 * — a human still needs to fill in the expected behaviour, but the
 * scaffolding (provider rows + signature + naming) is done.
 *
 * The parameter header is CODE, so it projects Hole display fields
 * through CodeParam (sigil passthrough + code-safe types) instead of
 * re-prepending `$` — review r2 N-1 cured the 128/159 `$$arg` skeletons.
 *
 * output-S54 (once-040/output-024): the write leg is fail-loud — directory
 * creation and every skeleton write route through WritesReportFile.
 *
 * output-S55 (php-dup-finder-output-023): scaffold convergence. The
 * per-cluster {@see buildTest()} now sits behind the pure multi-file seam
 * {@see build()} (filename => skeleton source, the sanctioned
 * array-shape of the reporting-pattern rule), and writeTo() writes exactly
 * that map through the fail-loud venue. Output bytes unchanged.
 *
 * once-074 (once-S65): directory hygiene on re-runs. Skeleton files are
 * keyed by content-derived cluster ids, so a run whose cluster set shrank
 * or churned used to leave the previous generation's orphan skeletons
 * beside the new ones (removed clusters' tests surviving as if still
 * proposed). writeTo() now sweeps the skeleton names IT CAN MINT —
 * Cluster<identifier>Test.php, exactly the shape {@see className()}
 * produces — before writing the current set. Pattern-scoped and
 * non-recursive: other files in the dir are not its business; a planted
 * symlink at an owned name is removed as a link, never written through;
 * enumeration or removal the disk refuses raises the venue's
 * ReportWriteException instead of shipping a mixed-generation dir.
 */
final class RefactorTestReporter
{
    use WritesReportFile;

    /**
     * once-074: the skeleton names THIS reporter can mint, as a
     * directory-entry pattern — the exact image of
     * {@see className()}.'Cluster' … 'Test.php' with the identifier-safe
     * projection between (Slug admits only [a-zA-Z0-9_-], dashes then
     * tighten to '_'), so anything else in the output dir — the user's own
     * tests, helpers, a differently-named file — is never swept.
     */
    private const OWNED_SKELETON_PATTERN = '/^Cluster[a-zA-Z0-9_]*Test\.php$/';

    /**
     * House-rule entrypoint: write one PHPUnit test-skeleton file per
     * cluster under `$dir`, exactly as {@see build()} renders them.
     */
    public function writeTo(Report $report, string $dir): void
    {
        $this->ensureDirectory($dir);
        // once-074: one generation per dir — drop the previous run's
        // skeletons before this run lays its own (trait sweep, OWNED_SKELETON_PATTERN).
        $this->sweepOwnedNames($dir, self::OWNED_SKELETON_PATTERN, 'test skeleton');
        foreach ($this->build($report) as $name => $contents) {
            $this->writeFile($dir . DIRECTORY_SEPARATOR . $name, $contents);
        }
    }

    /**
     * Pure seam wrapping {@see buildTest()} (output-S55): nothing is
     * written here — cluster order is insertion order, and the filename
     * stem stays the SAME output-S36 derivation the class name uses.
     *
     * @return array<string,string> '<Cluster…Test.php>' => skeleton source,
     *                              one entry per multi-member cluster
     */
    public function build(Report $report): array
    {
        $files = [];
        foreach ($report->clusters as $cluster) {
            if (count($cluster->members) < 2) {
                continue;
            }
            $files[$this->className($cluster) . '.php'] = $this->buildTest($cluster);
        }
        return $files;
    }

    /**
     * Render the test-class source for one cluster.
     */
    public function buildTest(Cluster $cluster): string
    {
        $className = $this->className($cluster);
        // output-035 (S6): the docblock row is metadata — defused name text.
        // output-S36 (php-dup-finder-output-033): the class-name position is
        // an identifier slot and now runs through the Slug sanitization —
        // that is SANITIZE, not spreadsheet-defuse: the S6 point stands
        // (a stray `'` there would break the lint pins) but raw id bytes
        // are no longer admitted either. Method/provider names are fixed
        // constants, provider labels always lead with "member_N_"
        // (structurally inert) and row values are PHP literals, not
        // spreadsheet cells.
        $defusedId = CsvReporter::defuseFormula($cluster->id);
        $paramList = $this->generateParamList($cluster->holes);
        $rows = $this->providerRows($cluster);
        $providerBody = '';
        foreach ($rows as $label => $args) {
            // output-S35 belt: the label embeds the member's recorded file
            // path, so it gets the ControlText scrub arm (LF/CR/NUL/ESC
            // cannot forge a row break in the generated file); the row
            // values are already single-line literals from reprValue().
            $providerBody .= "        '" . addslashes(ControlText::substitute($label)) . "' => [" . implode(', ', $args) . "],\n";
        }

        return <<<PHP
<?php
declare(strict_types=1);

namespace Refactored\\Tests;

use PHPUnit\\Framework\\TestCase;

/**
 * Auto-generated by phpdup for cluster {$defusedId}.
 *
 * The data-provider rows mirror the holes observed across cluster members.
 * Fill in each test body with the expected return value (or behaviour) for
 * the corresponding inputs — until then it is a markTestIncomplete().
 */
final class {$className} extends TestCase
{
    /**
     * @dataProvider casesProvider
     */
    public function testAbstractionMatchesEachMember({$paramList}): void
    {
        \$this->markTestIncomplete('phpdup: fill in expected output for the abstraction');
    }

    /** @return array<string, array<int, mixed>> */
    public static function casesProvider(): array
    {
        return [
{$providerBody}        ];
    }
}

PHP;
    }

    /**
     * The SINGLE derivation of both the artifact filename stem and the
     * declared class name (output-S36, php-dup-finder-output-033).
     *
     * Before this, writeTo() sanitized the id through {@see Slug} while
     * buildTest() interpolated it RAW: any legacy/odd id (pre-F-hex
     * streams, hand-seeded clusters) yielded an unparseable class
     * declaration or a class≠file-stem mismatch. Both call sites now read
     * this method, so stem == class is structural, not a coincidence of
     * well-behaved ids. Slug::safe() admits '-' (path-safe) which a PHP
     * identifier is not, hence the second byte-class tightening here —
     * current content ids ('F' + 16 hex, or the hex-only cluster ids)
     * contain no '-', so the projection is the identity on every shipped
     * artifact name (Slug's round-trip law, inherited).
     */
    private function className(Cluster $cluster): string
    {
        return 'Cluster' . str_replace('-', '_', Slug::safe($cluster->id)) . 'Test';
    }

    /**
     * Generate parameter list string from holes for the test method signature.
     *
     * @param list<Hole> $holes
     */
    private function generateParamList(array $holes): string
    {
        if ($holes === []) {
            return '';
        }
        $params = [];
        $used = [];
        foreach ($holes as $h) {
            $name = CodeParam::uniqueWithin(
                CodeParam::varName($h->suggestedName, $h->placeholder),
                $used
            );
            $type = CodeParam::typeText($h->inferredType) ?: 'mixed';
            $params[] = "{$type} {$name}";
        }
        return implode(', ', $params);
    }

    /**
     * @return array<string, list<string>> label → list of repr() strings
     *                                     (one per hole, taken from the
     *                                     member's observed value).
     */
    private function providerRows(Cluster $cluster): array
    {
        $count = count($cluster->members);
        // once-S35 (php-dup-finder-once-039): rows are addressed BY MEMBER
        // ID, not by blind positional indexing. AntiUnifier emits each
        // hole's observedValues aligned to cluster-member order; the member
        // id vector is the address that proves a row names the right
        // member. Members without a recorded id (pre-PreprocessStage
        // fixtures) address by position, so hand-built holes keep working;
        // content-equal members share an id — and, being
        // content-deterministic in the walk, share row-equal evidence.
        $keys = [];
        foreach ($cluster->members as $i => $m) {
            $keys[$i] = $m->id !== '' ? $m->id : '#' . $i;
        }
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $row = [];
            foreach ($cluster->holes as $h) {
                $row[] = $this->reprValue($h, $this->rowForId($h, $keys, $keys[$i]));
            }
            $label = "member_{$i}_" . str_replace([':', '/'], '_', $cluster->members[$i]->location());
            $out[$label] = $row;
        }
        return $out;
    }

    /**
     * Resolve one hole's row for one member by id key (see
     * {@see providerRows()}). A hole whose list runs short yields null —
     * the member has NO recorded observation — and reprValue renders an
     * explicit degraded gap. The retired positional `?? null` let that
     * miss reach the old null arm, which emitted a bare 'false'/'null':
     * a missing row counted as a VERIFIED absence or an observed null
     * (the pre-1.7 gap-row lie output-S27 legislated against). A hole
     * with rows beyond the member count has no member to name them —
     * they are unaddressed, never silently shifted onto a real member.
     *
     * @param list<string> $keys
     */
    private function rowForId(Hole $h, array $keys, string $key): ?string
    {
        foreach ($h->observedValues as $i => $value) {
            if (($keys[$i] ?? ('#' . $i)) === $key) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Render one observed hole value as a PHP literal suitable for
     * embedding directly in a `casesProvider` row.
     *
     * @param string|null $v the member's observed row resolved by
     *                       {@see rowForId()}; null = NO row recorded.
     */
    private function reprValue(Hole $h, ?string $v): string
    {
        if ($v === null) {
            // once-S35: an unrecorded row is a structural gap, not an
            // absence claim — same honest rendering as a degraded MISSING
            // row, distinct wording so a human can tell the two apart.
            return 'null /* degraded: member had no recorded row for this hole */';
        }
        if ($v === '<absent>') {
            // Belt: pre-5.4 cached holes may still carry the retired
            // printable sentinel; it claimed absence, so it projects as
            // the old arm did. Fresh rows can never contain it (Hole.php
            // forbids minting).
            return $h->kind === 'optional_block' ? 'false' : 'null';
        }
        if ($v === Hole::MISSING && $h->kind === 'optional_block' && !$h->degraded) {
            // output-S27: the canonical absence row on an optional hole
            // whose evidence is complete is a VERIFIED absence — exactly
            // what the retired printable sentinel stated, so the provider
            // row reads `false` (skip the segment) rather than the M2
            // degraded annotation below.
            return 'false';
        }
        if ($v === Hole::MISSING || $v === '<missing>') {
            // M2 fix: a degraded gap CARRIES '' (Hole::appendMissing) — the
            // null arm above never fires for it, and the quote fallback used
            // to ship a silent '' provider row where JSON says degraded:true.
            // An annotated null keeps the generated skeleton parseable AND
            // honest: the human editing it sees this slot is placeholder
            // evidence, not an observed empty string.
            return 'null /* degraded: observed value was a structural gap */';
        }
        if ($h->kind === 'optional_block') {
            // output-S36 (php-dup-finder-output-033): what survives the arms
            // above is a PRESENCE row — and ParameterSynthesizer::inferType
            // short-circuits EVERY optional_block hole to 'bool', so the
            // declaration slot is `bool` unconditionally. The retired
            // fall-through var_export'd the segment's statement repr INTO
            // that bool parameter: every present-member row was a
            // guaranteed TypeError the moment the skeleton drove a real
            // extracted function. The skeleton states the boolean fact the
            // generator can prove; the statement bytes stay visible on the
            // JSON/refactor-patch surfaces where they are evidence.
            return 'true';
        }
        // Numeric → bare; bool/null keywords → bare; everything else is DATA.
        if (preg_match('/^-?\d+(\.\d+)?$/', $v) === 1) {
            return $v;
        }
        if (in_array($v, ['true', 'false', 'null'], true)) {
            return $v;
        }
        // output-S35 (php-dup-finder-output-007, CRITICAL): the retired arm
        // here emitted every value matching `/^(['"]).*\1$/s` VERBATIM as
        // provider code. Quote-shaped text is not evidence of a literal —
        // ANY pretty-printed expression starts and ends on matching quotes,
        // so scanning a tree containing `$v = '' . touch('…') . '';` shipped
        // live code into casesProvider(), where PHPUnit evaluates the array
        // at collection time. The class docblock's own carve-out comment
        // ("row values are PHP literals") described the sink, not a proof.
        // Every non-whitelisted value is now one var_export'd string
        // literal — round-trips the observed repr byte-for-byte as data —
        // after the ControlText scrub (output-S18 venue) removes the four
        // stream-forging bytes no quoting may smuggle into the file's
        // line framing. The two bare arms above stay: a digit-only class
        // and a closed five-word keyword list cannot carry expression
        // shape; their TYPE fidelity (e.g. an int literal observed as
        // `10` vs a string "10") is output-S28/S29's declared territory.
        return var_export(ControlText::substitute($v), true);
    }
}
