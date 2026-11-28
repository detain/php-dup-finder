<?php

declare(strict_types=1);

namespace Phpdup\Ml;

/**
 * Item 8.2 — the deterministic instruction text `phpdup audit` sends with
 * every request. Two prompts, both pure functions of their (already
 * content-hashed) inputs: identical packs always produce identical bytes,
 * which is what makes the annotation cache a content-addressed store.
 *
 * The response shapes below are the wire contract documented in
 * docs/CLI.md's `phpdup audit` section; every field is re-validated on the
 * receive side (LlmClient shape guards), so a non-conforming endpoint
 * degrades the run to un-annotated output instead of poisoning it.
 */
final class LlmPrompts
{
    /**
     * Folded into every cache key. Bump on ANY instruction-text change so
     * stale verdicts from an older contract can never be replayed onto a
     * newer prompt (verdict semantics move with the words).
     */
    public const PROMPT_VERSION = '1';

    public const RISKS = ['low', 'medium', 'high'];
    public const EFFORTS = ['S', 'M', 'L'];

    /**
     * @param list<array<string, mixed>> $packs evidence-pack documents (EvidencePackBuilder::build() shape)
     */
    public static function annotatePrompt(array $packs): string
    {
        $n = count($packs);

        return "You are the judgment layer of phpdup audit (prompt v" . self::PROMPT_VERSION . ").\n"
            . "Below are {$n} duplicate-code findings, each as an evidence pack: the anti-unified template,\n"
            . "per-member differences, structural metadata, drift flags and history. Judge ONLY from the pack.\n"
            . "\n"
            . "For every pack produce exactly these fields:\n"
            . '- "title": one short phrase naming the duplicated logic (what it does, not where it is).\n'
            . '- "proposal": the concrete refactor to perform (extract which function/class, parameterize what).\n'
            . '- "risk": one of low|medium|high — blast radius and behavioural subtlety of performing the proposal.\n'
            . '- "effort": one of S|M|L — rough work to perform it.\n'
            . "- \"intentional\": true when the duplication looks deliberate (framework-mandated shapes, parallel\n"
            . "  adapters kept apart on purpose, near-dupes whose differences are the point); otherwise false.\n"
            . "\n"
            . "Reply with STRICT JSON only, no prose, no fences:\n"
            . '{"annotations":[{"finding_id":"<echoed verbatim>","title":"…","proposal":"…","risk":"low|medium|high","effort":"S|M|L","intentional":true|false}]}'
            . "\nEvery finding_id from the input must appear exactly once. Input packs follow.\n";
    }

    /**
     * @param list<array<string, mixed>> $annotated annotation rows (finding_id,title,risk,effort,intentional)
     */
    public static function themesPrompt(array $annotated): string
    {
        $n = count($annotated);

        return "You are the merge layer of phpdup audit (prompt v" . self::PROMPT_VERSION . ").\n"
            . "Below are {$n} annotated findings (title, risk, effort, intentional, structural class).\n"
            . "\n"
            . "Group them into at most 8 themes a team could schedule one at a time. Name each theme with a\n"
            . "short SEMANTIC label (an English phrase such as \"outbound HTTP retry\" — not a directory path).\n"
            . "Write a summary (a few sentences, plain prose) and a landing order: which themes to fix first\n"
            . "(low risk, high impact, not intentional) and which to leave last (intentional or high risk).\n"
            . "A finding may belong to at most one theme; findings you would rather not group may stand alone.\n"
            . "\n"
            . "Reply with STRICT JSON only, no prose, no fences:\n"
            . '{"themes":[{"name":"…","label":"<semantic phrase>","members":["<finding_id>", …]}],'
            . '"summary":"…","landing_order":["<theme name or finding_id>", …]}'
            . "\nEvery member id must come from the input. Findings digest follows.\n";
    }
}
