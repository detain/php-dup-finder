<?php
declare(strict_types=1);

namespace Phpdup\Tui;

use Phpdup\Pipeline\PipelineState;
use React\EventLoop\LoopInterface;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Kit\Theme;

/**
 * Boots a SugarCraft {@see Program} for a {@see PhpdupModel}.
 *
 * Two modes:
 *   - "post-hoc" — pipeline ran first, model holds the final state, TUI is just an
 *     interactive viewer. {@see run()} / {@see runWithModel()}.
 *   - "live" — model receives an iterator factory and drives the cooperative
 *     pipeline from inside the runtime via StagePumpedMsg. {@see buildLiveModel()}.
 */
final class TuiRunner
{
    public function run(PipelineState $state, string $themeName, bool $useAltScreen = true): int
    {
        $view = new ViewState();
        $view->analysisComplete = true;
        $model = new PhpdupModel($state, $view, self::resolveTheme($themeName));
        return $this->runWithModel($model, $useAltScreen);
    }

    /**
     * Boot the program with a pre-built model. Used both for post-hoc viewing of a
     * completed pipeline and for live mode where the model carries its own
     * iterator factory.
     */
    public function runWithModel(PhpdupModel $model, bool $useAltScreen = true, ?LoopInterface $loop = null): int
    {
        $options = new ProgramOptions(
            useAltScreen: $useAltScreen,
            catchInterrupts: true,
            hideCursor: true,
            loop: $loop,
        );

        (new Program($model, $options))->run();

        return 0;
    }

    /**
     * Build a {@see Program} bound to a pre-built model without running it.
     * Used by watch-mode where the caller wants to keep a reference to send
     * messages while the loop runs.
     */
    public function makeProgram(PhpdupModel $model, bool $useAltScreen = true, ?LoopInterface $loop = null): Program
    {
        return new Program($model, new ProgramOptions(
            useAltScreen: $useAltScreen,
            catchInterrupts: true,
            hideCursor: true,
            loop: $loop,
        ));
    }

    public function buildModel(PipelineState $state, string $themeName): PhpdupModel
    {
        return new PhpdupModel($state, new ViewState(), self::resolveTheme($themeName));
    }

    /**
     * Build a model that drives the cooperative pipeline from inside the runtime.
     *
     * @param \Closure(): array{0: \Generator, 1: PipelineState} $iteratorFactory
     */
    public function buildLiveModel(string $themeName, \Closure $iteratorFactory): PhpdupModel
    {
        [, $initialState] = $iteratorFactory();
        return new PhpdupModel(
            $initialState,
            new ViewState(),
            self::resolveTheme($themeName),
            $iteratorFactory,
        );
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
