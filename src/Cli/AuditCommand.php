<?php

declare(strict_types=1);

namespace Phpdup\Cli;

use Phpdup\Ml\LlmClient;
use Phpdup\Ml\LlmPackCache;
use Phpdup\Ml\LlmPrompts;
use Phpdup\Reporting\EvidencePackBuilder;
use Phpdup\Reporting\SourceTextStore;
use Phpdup\Util\ControlText;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Item 8.2 — `phpdup audit` (improve_logic.md): "Runs the pipeline, then
 * sends evidence packs to an LLM in batches to fill in title, proposal,
 * risk, effort and 'intentional?' per finding, followed by one pass that
 * merges findings into themes and writes the summary and landing order.
 * Cache by evidence-pack hash so reruns only pay for changed findings."
 *
 * POSTURE (info-doc §2 Phase-8, the decided rules this class implements):
 *  - the DRIVE is the analyze pipeline itself: admitAnalysis()/runAnalysis()
 *    are the batch-1 extraction, so a scan behaves EXACTLY like analyze
 *    (same doors, same exit codes, same CLI report body). `audit` is a
 *    JUDGMENT layer on top, never a second pipeline.
 *  - llm_* knobs are config-file only (root phpdup.json), there are NO
 *    --llm-* flags: analyze never consumes the keys, and a per-run argv
 *    arm would fork the endpoint mid-cohort. Off by default (llm_url
 *    ''): zero sockets, zero cache, the audit document ships un-annotated
 *    and the drive exit is unchanged.
 *  - the bearer token is ENV-ONLY (PHPDUP_LLM_TOKEN). Reports embed the
 *    resolved config section, so a token in a config key would ship in
 *    every artifact — the token never touches Config, the schema, or the
 *    audit document (only the boolean token_present is recorded).
 *  - failure = un-annotated report ships, exit unchanged. Transport,
 *    decode, and shape failures degrade to pending findings + honest
 *    notes; the judgment cache makes each rerun pay only for what
 *    changed, per the letter's cache-by-pack-hash rule.
 *  - --apply/--tui/--watch are refused at the door: the audit layer is a
 *    headless judgment pass; an armed apply under a "review" verb is the
 *    kind of surface an operator should never reach by accident.
 *  - the audit DOCUMENT is a standalone file artifact (schema
 *    'phpdup-audit'): the analyze report JSON never grows a field, the
 *    Golden snapshots never move, and JsonSchemaSpec::SCHEMA_VERSION is
 *    untouched.
 *
 * SEMANTIC LABELS: docs/CLI.md and docs/config-schema.json both deferred
 * semantic theme labels ("outbound HTTP" as a phrase) to this item — the
 * theme-merge pass below is exactly that venue. The heuristic
 * summary.themes surface (item 6.4, ThemeAnalyzer) stays untouched.
 */
final class AuditCommand extends Command
{
    /**
     * @param (callable(string $url, string $body, list<string> $headers): ?string)|null $transportOverride
     *                                     hermetic test seam — unit/integration
     *                                     suites inject a fake and never open a
     *                                     socket; production passes nothing
     */
    public function __construct(
        ?\Phpdup\Cli\ConfigLoader $doorLoaderForTests = null,
        private readonly mixed $transportOverride = null,
    ) {
        parent::__construct($doorLoaderForTests);
    }

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setName('audit')
            ->setDescription('Run the pipeline, then have an LLM annotate the evidence packs (title/proposal/risk/effort/intentional?) and merge findings into themes with a summary and landing order.')
            ->addOption('audit', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Write the phpdup-audit JSON document to FILE (required; standalone artifact — the analyze report shapes are untouched).')
            ->addOption('dry-run-packs', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Inventory the evidence packs and write the audit document with annotations pending; make zero LLM calls even when llm_url is armed.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Headless posture doors (exit 2 = invalid invocation, per the
        // docs/CLI.md contract): audit inherits every analyze flag for the
        // DRIVE, but the three postures that take over a terminal or
        // rewrite source are refused here explicitly.
        foreach (['tui' => '--tui', 'watch' => '--watch', 'apply' => '--apply'] as $option => $flag) {
            if ($input->getOption($option)) {
                $output->writeln('<error>phpdup: phpdup audit never runs ' . $flag . ' — the audit layer is a headless judgment pass over the drive report</error>');

                return 2;
            }
        }

        $auditPath = $input->getOption('audit');
        if (!is_string($auditPath) || $auditPath === '') {
            $output->writeln('<error>phpdup: phpdup audit requires --audit FILE — the audit document is this command\'s deliverable</error>');
            $output->writeln('Example: <info>phpdup audit src --audit=phpdup-audit.json</info>');

            return 2;
        }
        try {
            ControlText::reject($auditPath, 'audit', 'the --audit path');
        } catch (\RuntimeException $e) {
            // The message carries its own 'phpdup:' lead (ControlText voice).
            $output->writeln('<error>' . OutputFormatter::escape($e->getMessage()) . '</error>');

            return 2;
        }

        // ---- the drive: exactly analyze's admission + pipeline -------
        $admitted = $this->admitAnalysis($input, $output);
        if (isset($admitted['code'])) {
            return $admitted['code'];
        }
        $run = $this->runAnalysis($admitted['config'], $admitted['buildPipeline'], $admitted['diffCohort'], $output);
        $exit = $run['exit'];
        $state = $run['state'];

        if ($state->report === null) {
            // Cancelled/failed run: there is nothing to judge. The drive
            // already rendered its own honest line; pass the exit through.
            $output->writeln('<comment>phpdup audit: no report was produced — audit layer skipped (drive exit ' . $exit . ' stands)</comment>');

            return $exit;
        }

        // ---- the judgment layer ----------------------------------------
        $config = $admitted['config'];
        $notes = [];
        $packDocs = [];
        foreach ((new EvidencePackBuilder(
            new SourceTextStore($config->paths),
            $state->sequenceIndex?->helperAdoption()?->rows(),
        ))->build($state->report) as $packName => $packJson) {
            $decoded = json_decode((string) $packJson, true);
            if (!is_array($decoded)) {
                // Belt: the builder writes its own JSON; an undecodable
                // pack is a builder bug, disclosed and skipped, never a
                // silent truncation of the document.
                $notes[] = sprintf('evidence pack %s is not decodable — skipped', (string) $packName);

                continue;
            }
            $packDocs[] = $decoded;
        }
        $packs = $packDocs;

        $armed = $config->llmUrl !== '';
        $dryRun = (bool) $input->getOption('dry-run-packs');
        $client = null;
        $cache = null;
        $tokenPresent = false;
        if ($armed && !$dryRun) {
            $token = LlmClient::tokenFromEnv();
            $tokenPresent = $token !== null;
            if ($token === null) {
                // Loud degrade per the decided posture: armed endpoint but
                // no credential is the operator's config gap, never a
                // silent half-run.
                $notes[] = sprintf(
                    'llm_url is configured but %s is not set — annotation pass skipped; the un-annotated audit document ships and the drive exit is unchanged',
                    LlmClient::TOKEN_ENV,
                );
            } else {
                $client = new LlmClient(
                    baseUrl: $config->llmUrl,
                    model: $config->llmModel,
                    token: $token,
                    allowPrivate: $config->llmAllowPrivate,
                    transport: $this->transportOverride,
                );
                $cache = new LlmPackCache(
                    dir: $config->cacheDir === '' ? '.phpdup-cache/llm-annotations' : rtrim($config->cacheDir, '/') . '/llm-annotations',
                    host: $client->host(),
                    model: $client->model(),
                );
            }
        }

        $scopeCount = min(count($packs), max(1, $config->llmMaxFindings));
        if (!$armed && !$dryRun) {
            $notes[] = 'llm_url is not configured (config-file key, root phpdup.json) — the audit layer ran no LLM pass; the document lists every finding un-annotated. Set llm_url (and ' . LlmClient::TOKEN_ENV . ' for a bearer endpoint) to fill in the judgment fields.';
        }
        if (count($packs) > $scopeCount) {
            $notes[] = sprintf(
                'llm_max_findings=%d caps the annotation scope at %d of %d packs; the remaining findings are listed with annotation null (cost ceiling per §8.2)',
                $config->llmMaxFindings,
                $scopeCount,
                count($packs),
            );
        }

        /** @var array<string, array{key: string, origin: string|null, annotation: array<string, mixed>|null}> $rows keyed by finding_id */
        $rows = [];
        $cachedHits = 0;
        $llmCalls = 0;
        $pending = [];
        foreach ($packs as $i => $pack) {
            $findingId = (string) ($pack['finding_id'] ?? '');
            $key = '';
            if ($cache !== null) {
                try {
                    $key = $cache->packKey($pack);
                } catch (\RuntimeException $e) {
                    // m-2 (8.2 r1): the cache names an un-encodable pack a
                    // programming-error class (belt above its own throw);
                    // the audit posture is never-fatal, so the door that
                    // catches it is THIS degrade arm — the finding ships
                    // un-cached (empty pack_key in the document) with one
                    // loud note, and the fresh verdict simply never lands
                    // in a cache slot it has no key for.
                    $notes[] = sprintf(
                        'cache-key derivation for finding %s failed (%s) — served un-cached this run',
                        $findingId,
                        $e->getMessage(),
                    );
                }
            }
            $rows[$findingId] = ['key' => $key, 'origin' => null, 'annotation' => null];
            if ($i >= $scopeCount || $client === null) {
                continue;
            }
            if ($dryRun) {
                $pending[] = $pack;

                continue;
            }
            $cached = $cache?->get($key);
            if (is_array($cached) && LlmClient::annotationRowValid($cached)) {
                $rows[$findingId]['origin'] = 'cached';
                $rows[$findingId]['annotation'] = $cached;
                $cachedHits++;

                continue;
            }
            $pending[] = $pack;
        }

        if (!$dryRun && $client !== null && $pending !== []) {
            $chunks = array_chunk($pending, LlmClient::BATCH_SIZE);
            foreach ($chunks as $chunkNo => $chunk) {
                $annotated = $client->annotateBatch($chunk);
                $llmCalls++;
                if ($annotated === null) {
                    $notes[] = sprintf('annotation batch %d/%d failed (transport or shape refusal) — its findings stay pending; reruns pay only for changed packs', $chunkNo + 1, count($chunks));

                    continue;
                }
                $byFinding = [];
                foreach ($annotated as $annotation) {
                    $byFinding[(string) $annotation['finding_id']] = $annotation;
                }
                foreach ($chunk as $pack) {
                    $findingId = (string) ($pack['finding_id'] ?? '');
                    if (!isset($byFinding[$findingId])) {
                        continue; // endpoint skipped this finding: honestly pending
                    }
                    $rows[$findingId]['origin'] = 'llm';
                    $rows[$findingId]['annotation'] = $byFinding[$findingId];
                    // m-2 companion: a degraded pack (no cache key) has no
                    // slot to write to — the verdict simply ships un-cached.
                    if ($cache !== null && $rows[$findingId]['key'] !== ''
                        && !$cache->put($rows[$findingId]['key'], $byFinding[$findingId])) {
                        $notes[] = sprintf('cache write for finding %s failed — the verdict still ships in this document', $findingId);
                    }
                }
            }
        }

        // ---- theme pass: one call over the FULL in-scope verdict set ----
        $themesBlock = null;
        $themeOrigin = null;
        $annotatedRows = [];
        // n-4 (8.2 r1): the in-scope key set is collected BY ITERATION over
        // the same positional pack loop the theme gate counts coverage from
        // (builder guarantee: one pack per finding, content-derived unique
        // finding_id, iteration order == report rank order) — no slice of
        // $rows whose size depends on that uniqueness holding indirectly.
        $inScopeKeys = [];
        foreach ($packs as $i => $pack) {
            if ($i >= $scopeCount) {
                break;
            }
            $row = $rows[(string) ($pack['finding_id'] ?? '')];
            $inScopeKeys[] = $row['key'];
            $annotation = $row['annotation'];
            if ($annotation === null) {
                continue;
            }
            $annotatedRows[] = $annotation + ['class' => (string) ($pack['members'][0]['class'] ?? '')];
        }
        if ($cache !== null && $client !== null && !$dryRun && count($annotatedRows) === $scopeCount && $scopeCount > 0) {
            // The lock-step construction above (:client and :cache are assigned
            // in the same branch) makes both non-null here; the explicit
            // conjunctive guard keeps the invariant at the call site.
            $themeKey = $cache->themesKey($inScopeKeys);
            $storedThemes = $cache->get($themeKey);
            // m-1 (8.2 r1): symmetric discipline — a stored T- entry clears
            // the SAME normalizeThemes contract a fresh wire response clears
            // (operator-writable disk is never trusted on mere key
            // existence; the old isset-trio let a tampered half-entry reach
            // renderAuditSummary and crash the render on count()/string
            // shapes). Invalid stored entry = miss + one loud note.
            $normalizedThemes = is_array($storedThemes) ? LlmClient::normalizeThemes($storedThemes) : null;
            if (is_array($storedThemes) && $normalizedThemes === null) {
                $notes[] = 'cached theme verdict failed shape re-validation — re-running the merge pass (the rerun pays only for the theme call)';
            }
            if ($normalizedThemes !== null) {
                $themesBlock = $normalizedThemes;
                $themeOrigin = 'cached';
            } else {
                $merged = $client->mergeThemes($annotatedRows);
                $llmCalls++;
                if ($merged === null) {
                    $notes[] = 'theme-merge pass failed (transport or shape refusal) — themes/summary/landing_order stay null; the per-finding annotations still ship';
                } else {
                    $themesBlock = $merged;
                    $themeOrigin = 'llm';
                    $cache->put($themeKey, ['themes' => $merged['themes'], 'summary' => $merged['summary'], 'landing_order' => $merged['landing_order']]);
                }
            }
        } elseif (!$dryRun && $armed && $client !== null && $scopeCount > 0 && count($annotatedRows) < $scopeCount) {
            $notes[] = sprintf('theme pass skipped — %d of %d in-scope findings carry annotations; re-run once the endpoint fills the gaps (each rerun pays only for changed packs)', count($annotatedRows), $scopeCount);
        }

        // ---- audit document ---------------------------------------------
        $document = [
            'schema' => 'phpdup-audit',
            'audit_version' => '1',
            'llm' => [
                'configured' => $armed,
                'host' => $client !== null ? $client->host() : (
                    $armed ? (static function (string $url): string {
                        $host = parse_url($url, PHP_URL_HOST);

                        return is_string($host) ? strtolower($host) : '';
                    })($config->llmUrl) : ''
                ),
                'model' => $config->llmModel,
                'prompt_version' => LlmPrompts::PROMPT_VERSION,
                'token_present' => $tokenPresent,
                'dry_run' => $dryRun,
            ],
            'totals' => [
                'findings' => count($packs),
                'annotated' => count($annotatedRows),
                'cached_hits' => $cachedHits,
                'llm_calls' => $llmCalls,
                'theme_pass' => $themesBlock !== null,
            ],
            'findings' => array_values(array_map(
                static function (array $pack) use ($rows): array {
                    $findingId = (string) ($pack['finding_id'] ?? '');
                    $row = $rows[$findingId];

                    return [
                        'rank' => $pack['rank'] ?? null,
                        'finding_id' => $findingId,
                        'pack_key' => $row['key'],
                        'origin' => $row['origin'],
                        'annotation' => $row['annotation'],
                    ];
                },
                $packs,
            )),
            'themes' => $themesBlock['themes'] ?? null,
            'summary' => $themesBlock['summary'] ?? null,
            'landing_order' => $themesBlock['landing_order'] ?? null,
            'notes' => $notes,
        ];

        if ($dryRun) {
            $this->renderPackInventory($output, $packs, $scopeCount, $config, $armed);
        }

        if (!$this->writeAuditDocument($output, $auditPath, $document)) {
            return $exit === 0 ? 1 : $exit; // write failure is a runtime halt (exit 1 family), never a laundered success
        }

        $this->renderAuditSummary($output, $document, $auditPath);

        return $exit; // the drive's exit is unchanged by the judgment layer
    }

    /**
     * The exit-2 cost posture of --dry-run-packs: the operator sees exactly
     * what WOULD ship (rank, finding, pack bytes, batch plan) with zero
     * transport calls, then decides whether to arm the endpoint.
     *
     * @param list<array<string, mixed>> $packs
     */
    private function renderPackInventory(OutputInterface $output, array $packs, int $scopeCount, Config $config, bool $armed): void
    {
        $output->writeln('');
        $output->writeln('<info>phpdup audit — pack inventory (dry run; no LLM calls were made)</info>');
        $output->writeln(sprintf(
            '  llm_url: %s   llm_max_findings: %d   batches at %d packs: %d',
            $armed ? 'armed' : 'OFF (config llm_url empty)',
            $config->llmMaxFindings,
            LlmClient::BATCH_SIZE,
            $scopeCount > 0 ? (int) ceil($scopeCount / LlmClient::BATCH_SIZE) : 0,
        ));
        foreach ($packs as $i => $pack) {
            $bytes = strlen((string) json_encode($pack, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $output->writeln(sprintf(
                '  %3d  %s  %7d B%s',
                (int) ($pack['rank'] ?? $i + 1),
                OutputFormatter::escape((string) ($pack['finding_id'] ?? '?')),
                $bytes,
                $i >= $scopeCount ? '  <comment>[over llm_max_findings — not sent]</comment>' : '',
            ));
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    private function writeAuditDocument(OutputInterface $output, string $path, array $document): bool
    {
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $output->writeln('<error>phpdup audit: the audit document is not JSON-encodable: ' . OutputFormatter::escape(json_last_error_msg()) . '</error>');

            return false;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            $output->writeln('<error>phpdup audit: --audit directory does not exist: ' . OutputFormatter::escape($dir) . '</error>');

            return false;
        }
        $tmp = $dir . '/' . basename($path) . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json . "\n") === false) {
            $output->writeln('<error>phpdup audit: cannot write ' . OutputFormatter::escape($path) . '</error>');
            @unlink($tmp);

            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            $output->writeln('<error>phpdup audit: cannot write ' . OutputFormatter::escape($path) . '</error>');

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function renderAuditSummary(OutputInterface $output, array $document, string $path): void
    {
        $totals = $document['totals'];
        $output->writeln('');
        $output->writeln('<info>phpdup audit</info> — document written to ' . OutputFormatter::escape($path));
        $output->writeln(sprintf(
            '  findings %d · annotated %d (cache hits %d, LLM requests %d) · theme pass %s',
            (int) $totals['findings'],
            (int) $totals['annotated'],
            (int) $totals['cached_hits'],
            (int) $totals['llm_calls'],
            $totals['theme_pass'] === true ? 'yes' : 'no',
        ));
        foreach ($document['findings'] as $row) {
            if (!is_array($row['annotation'])) {
                continue;
            }
            $annotation = $row['annotation'];
            $output->writeln(sprintf(
                '  #%s %s  <comment>[%s/%s%s]</comment> %s — %s',
                (string) $row['rank'],
                OutputFormatter::escape((string) $row['finding_id']),
                OutputFormatter::escape((string) $annotation['risk']),
                OutputFormatter::escape((string) $annotation['effort']),
                $annotation['intentional'] === true ? ', intentional?' : '',
                OutputFormatter::escape((string) $annotation['title']),
                $row['origin'] === 'cached' ? '<comment>(cached)</comment>' : '',
            ));
        }
        if (is_array($document['themes'])) {
            $output->writeln('  <info>themes</info>');
            foreach ($document['themes'] as $theme) {
                $label = is_string($theme['label']) && $theme['label'] !== '' ? ' — ' . OutputFormatter::escape($theme['label']) : '';
                $output->writeln(sprintf(
                    '    %s%s (%d finding%s)',
                    OutputFormatter::escape((string) $theme['name']),
                    $label,
                    count($theme['members']),
                    count($theme['members']) === 1 ? '' : 's',
                ));
            }
        }
        if (is_string($document['summary']) && $document['summary'] !== '') {
            $output->writeln('  <info>summary</info> ' . OutputFormatter::escape($document['summary']));
        }
        if (is_array($document['landing_order']) && $document['landing_order'] !== []) {
            $output->writeln('  <info>landing order</info> ' . OutputFormatter::escape(implode(' → ', array_map('strval', $document['landing_order']))));
        }
        foreach ($document['notes'] as $note) {
            $output->writeln('  <comment>phpdup audit: ' . OutputFormatter::escape((string) $note) . '</comment>');
        }
    }
}
