<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Ml;

use Phpdup\Ml\LlmPrompts;
use PHPUnit\Framework\TestCase;

/**
 * Item 8.2 — the prompt contract is the wire: field names, vocabularies
 * and the strict-JSON demand are parsed by LlmClient's shape guards, so
 * drift here silently degrades every audit. These pins are the fence.
 */
final class LlmPromptsTest extends TestCase
{
    public function testPromptVersionIsPinned(): void
    {
        $this->assertSame('1', LlmPrompts::PROMPT_VERSION);
    }

    public function testAnnotatePromptDemandsExactlyTheLettersFields(): void
    {
        $prompt = LlmPrompts::annotatePrompt([['finding_id' => 'F1'], ['finding_id' => 'F2']]);

        $this->assertStringContainsString('2 duplicate-code findings', $prompt, 'the pack count is echoed for the endpoint');
        foreach (['"title"', '"proposal"', '"risk"', '"effort"', '"intentional"'] as $field) {
            $this->assertStringContainsString($field, $prompt, $field . ' is one of the five fields the letter names');
        }
        $this->assertStringContainsString('low|medium|high', $prompt);
        $this->assertStringContainsString('S|M|L', $prompt);
        $this->assertStringContainsString('STRICT JSON', $prompt);
        $this->assertStringContainsString('finding_id', $prompt, 'rows are joined back by echoed finding_id');
        // The pack payload itself rides the request body, not the prompt.
        $this->assertStringNotContainsString('F1', $prompt);
    }

    public function testThemesPromptIsTheSemanticLabelVenue(): void
    {
        $prompt = LlmPrompts::themesPrompt([['finding_id' => 'F1']]);

        // The docs deferred semantic labels ("outbound HTTP" as a phrase)
        // to item 8.2 — this pass is that venue, and it must SAY so.
        $this->assertStringContainsString('SEMANTIC label', $prompt);
        $this->assertStringContainsString('"outbound HTTP retry"', $prompt);
        $this->assertStringContainsString('summary', $prompt);
        $this->assertStringContainsString('landing_order', $prompt);
        $this->assertStringContainsString('STRICT JSON', $prompt);
    }

    public function testVocabulariesMatchTheClientGuards(): void
    {
        $this->assertSame(['low', 'medium', 'high'], LlmPrompts::RISKS);
        $this->assertSame(['S', 'M', 'L'], LlmPrompts::EFFORTS);
    }

    public function testPromptsAreDeterministicFunctionsOfInput(): void
    {
        $a = LlmPrompts::annotatePrompt([['finding_id' => 'X']]);
        $b = LlmPrompts::annotatePrompt([['finding_id' => 'Y']]);
        $this->assertSame($a, $b, 'only the count participates — same-count inputs emit identical instructions');
        $this->assertNotSame($a, LlmPrompts::annotatePrompt([['finding_id' => 'X'], ['finding_id' => 'Y']]));
    }
}
