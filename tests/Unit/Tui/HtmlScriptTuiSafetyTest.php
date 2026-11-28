<?php

declare(strict_types=1);

namespace Phpdup\Tests\Unit\Tui;

use PhpParser\Node\Stmt\Echo_;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Tui\ViewState;
use Phpdup\Util\LineRange;
use PHPUnit\Framework\TestCase;

/**
 * Item 7.2b — TUI posture for html_script folds: ZERO new code, pinned as
 * a decision (the mission asked for "render safely or hide — choose,
 * disclose").
 *
 * The choice is HIDE-BY-EXISTENCE plus SAFE-IF-NAMED: no TUI surface ever
 * prints a block name or kind (grep of src/Tui proves it — the cluster
 * panes carry count phrases only), so html_script members ride the same
 * anonymous aggregates every other fold species already used. The one door
 * kind STRINGS cross is the config roster filter line, and it already runs
 * every config-file value through ViewState::displaySafe. These pins hold
 * both halves closed against the new token.
 */
final class HtmlScriptTuiSafetyTest extends TestCase
{
    public function testAnonymousHtmlScriptFoldRendersKindQualifiedNameOnlyAsFallback(): void
    {
        $block = new Block(
            'tpl.phtml',
            new LineRange(3, 9),
            BlockExtractor::HTML_SCRIPT_KIND,
            null,
            null,
            null,
            new Echo_([])
        );
        // '<html_script>' is the seed every consumer derives from — the TUI
        // never reaches it, but the format stays pinned for report parity.
        self::assertSame('<html_script>', $block->qualifiedName());
    }

    public function testRosterDoorScrubsHtmlScriptKindBytesLikeEveryOtherValue(): void
    {
        // displaySafe is the door the Scanning pane's "filtering:" line uses
        // for config-supplied kinds. Plain opt-in tokens must pass verbatim;
        // hostile bytes glued onto the token must surface in caret notation
        // rather than raw escape sequences.
        self::assertSame('html_script', ViewState::displaySafe('html_script'));
        self::assertSame('class, html_script', ViewState::displaySafe('class, html_script'));

        $hostile = ViewState::displaySafe("html_script\x01\x1b[31m");
        self::assertStringNotContainsString("\x01", $hostile);
        self::assertStringNotContainsString("\x1b", $hostile);
        self::assertStringContainsString('html_script', $hostile);
    }

    public function testDisplayLabelClipsWithinCellsForHtmlScriptToken(): void
    {
        $label = ViewState::displayLabel(ViewState::displaySafe('html_script'), 8);
        self::assertSame('html_sc…', $label);
    }
}
