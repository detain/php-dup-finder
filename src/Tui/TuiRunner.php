<?php
declare(strict_types=1);

namespace Phpdup\Tui;

use Phpdup\Pipeline\PipelineState;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Kit\Theme;

/**
 * Boots a SugarCraft {@see Program} for a completed {@see PipelineState}.
 *
 * In Phase 2 the pipeline runs synchronously to completion before this is
 * called; Phase 3 will reverse the relationship and let the runtime drive
 * pipeline progress through ticks.
 */
final class TuiRunner
{
    public function run(PipelineState $state, string $themeName, bool $useAltScreen = true): int
    {
        $theme   = self::resolveTheme($themeName);
        $view    = new ViewState();
        $view->analysisComplete = true;
        $model   = new PhpdupModel($state, $view, $theme);

        $options = new ProgramOptions(
            useAltScreen: $useAltScreen,
            catchInterrupts: true,
            hideCursor: true,
        );

        (new Program($model, $options))->run();

        return 0;
    }

    public static function resolveTheme(string $name): Theme
    {
        return match (strtolower($name)) {
            'plain'      => Theme::plain(),
            'charm'      => Theme::charm(),
            'dracula'    => Theme::dracula(),
            'nord'       => Theme::nord(),
            'catppuccin' => Theme::catppuccin(),
            default      => Theme::ansi(),
        };
    }

    /** @return list<string> */
    public static function knownThemes(): array
    {
        return ['ansi', 'plain', 'charm', 'dracula', 'nord', 'catppuccin'];
    }
}
