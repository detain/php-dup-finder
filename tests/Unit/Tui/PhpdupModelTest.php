<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Tui;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\CandidateBudgetException;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Tui\Msg\RestartPipelineMsg;
use Phpdup\Tui\Msg\StagePumpedMsg;
use Phpdup\Tui\PhpdupModel;
use Phpdup\Tui\TuiRunner;
use Phpdup\Tui\ViewState;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\ProgressBarState;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\View;
use SugarCraft\Forms\Spinner\TickMsg as SpinnerTickMsg;

final class PhpdupModelTest extends TestCase
{
    public function testInitReturnsCmd(): void
    {
        $cmd = $this->buildModel()->init();
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testWindowSizeMsgUpdatesViewState(): void
    {
        $model = $this->buildModel();

        [$next, $cmd] = $model->update(new WindowSizeMsg(120, 40));

        $this->assertSame($model, $next);
        $this->assertNull($cmd);
        $this->assertSame(120, $model->viewState->cols);
        $this->assertSame(40, $model->viewState->rows);
    }

    public function testQKeyReturnsQuitCmd(): void
    {
        $model = $this->buildModel();
        [$_, $cmd] = $model->update(new KeyMsg(KeyType::Char, rune: 'q'));
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertInstanceOf(QuitMsg::class, $cmd());
    }

    public function testCtrlCReturnsQuitCmd(): void
    {
        $model = $this->buildModel();
        [$_, $cmd] = $model->update(new KeyMsg(KeyType::Char, rune: 'c', ctrl: true));
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertInstanceOf(QuitMsg::class, $cmd());
    }

    public function testArrowKeysCyclePaneFocus(): void
    {
        $model = $this->buildModel();
        $this->assertSame(0, $model->viewState->focusedPaneIndex);

        $model->update(new KeyMsg(KeyType::Down));
        $this->assertSame(1, $model->viewState->focusedPaneIndex);

        $model->update(new KeyMsg(KeyType::Down));
        $this->assertSame(2, $model->viewState->focusedPaneIndex);

        $model->update(new KeyMsg(KeyType::Up));
        $this->assertSame(1, $model->viewState->focusedPaneIndex);
    }

    public function testLowercaseTCyclesSortModeForward(): void
    {
        $model = $this->buildModel();
        $this->assertSame(ViewState::SORT_IMPACT, $model->viewState->sortMode);
        $model->update(new KeyMsg(KeyType::Char, rune: 't'));
        $this->assertSame(ViewState::SORT_CYCLE[1], $model->viewState->sortMode, 'one tap → next key in SORT_CYCLE');
    }

    public function testUppercaseTTogglesSortDirection(): void
    {
        $model = $this->buildModel();
        $this->assertSame('desc', $model->viewState->sortDirection, 'defaults to desc');
        $model->update(new KeyMsg(KeyType::Char, rune: 'T'));
        $this->assertSame('asc', $model->viewState->sortDirection);
        $model->update(new KeyMsg(KeyType::Char, rune: 'T'));
        $this->assertSame('desc', $model->viewState->sortDirection);
    }

    public function testUppercaseTDoesNotAffectSortMode(): void
    {
        $model = $this->buildModel();
        $original = $model->viewState->sortMode;
        $model->update(new KeyMsg(KeyType::Char, rune: 'T'));
        $this->assertSame($original, $model->viewState->sortMode, 'shift-T flips direction only, never the key');
    }

    public function testHKeyTogglesHelpExpanded(): void
    {
        $model = $this->buildModel();
        $this->assertFalse($model->viewState->helpExpanded);
        $model->update(new KeyMsg(KeyType::Char, rune: 'h'));
        $this->assertTrue($model->viewState->helpExpanded);
        $model->update(new KeyMsg(KeyType::Char, rune: 'h'));
        $this->assertFalse($model->viewState->helpExpanded);
    }

    public function testEscapeDismissesDetail(): void
    {
        $model = $this->buildModel();
        $model->viewState->detailClusterId = 'C123';
        $model->update(new KeyMsg(KeyType::Escape));
        $this->assertNull($model->viewState->detailClusterId);
    }

    public function testViewReturnsViewObjectWithBody(): void
    {
        $model = $this->buildModel();
        $view = $model->view();
        $this->assertInstanceOf(View::class, $view);
        $this->assertNotEmpty($view->body);
    }

    public function testViewAttachesTaskbarProgress(): void
    {
        $model = $this->buildModel();
        $view  = $model->view();
        $this->assertNotNull($view->progressBar);
        $this->assertSame(0, $view->progressBar->percent);

        $model->viewState->analysisComplete = true;
        $this->assertSame(100, $model->view()->progressBar->percent);
    }

    public function testIsAProgressListener(): void
    {
        $model = $this->buildModel();
        $this->assertInstanceOf(\Phpdup\Pipeline\ProgressListener::class, $model);

        $model->onStageStart(\Phpdup\Pipeline\Stage::Scanning);
        $this->assertSame(\Phpdup\Pipeline\Stage::Scanning, $model->state->stage);

        $model->onFileScanned(7, 12);
        $this->assertSame(7, $model->state->scannedFiles);
        $this->assertSame(12, $model->state->totalFiles);
    }

    /**
     * ui-grammar: the header banner and the Scanning pane both read
     * "1 file" on a lone-file run — the model has no other 'files'
     * surface, so the whole render must be free of the plural word.
     */
    public function testBannerAndScanningPaneSingularizeOneFile(): void
    {
        $model = $this->buildModel();
        $model->state->files = ['/tmp/only.php'];
        $model->state->totalFiles = 1;

        $body = $model->view()->body;
        $this->assertStringContainsString('phpdup — 1 file ·', $body);
        $this->assertStringContainsString('1 paths', $body);
        $this->assertStringNotContainsString('files', $body);
    }

    public function testBannerAndScanningPaneStayPluralAboveOneFile(): void
    {
        $model = $this->buildModel();
        $model->state->files = ['/tmp/a.php', '/tmp/b.php'];
        $model->state->totalFiles = 2;

        $body = $model->view()->body;
        $this->assertStringContainsString('phpdup — 2 files ·', $body);
        $this->assertGreaterThanOrEqual(2, substr_count($body, '2 files'), 'banner + Scanning pane both plural');
    }

    public function testLeftRightCycleClusterDetailWhenOpen(): void
    {
        $model = $this->buildModel();

        $cluster1 = $this->fakeCluster('C1');
        $cluster2 = $this->fakeCluster('C2');
        $cluster3 = $this->fakeCluster('C3');
        $model->state->clusters = [$cluster1, $cluster2, $cluster3];

        $model->viewState->detailClusterId = 'C1';

        $model->update(new KeyMsg(KeyType::Right));
        $this->assertSame('C2', $model->viewState->detailClusterId);

        $model->update(new KeyMsg(KeyType::Right));
        $this->assertSame('C3', $model->viewState->detailClusterId);

        $model->update(new KeyMsg(KeyType::Right));
        $this->assertSame('C1', $model->viewState->detailClusterId, 'wraps to first');

        $model->update(new KeyMsg(KeyType::Left));
        $this->assertSame('C3', $model->viewState->detailClusterId, 'wraps backwards');
    }

    public function testLeftRightDoNothingWhenDetailClosed(): void
    {
        $model = $this->buildModel();
        $model->state->clusters = [$this->fakeCluster('C1')];
        $this->assertNull($model->viewState->detailClusterId);

        $model->update(new KeyMsg(KeyType::Right));
        $this->assertNull($model->viewState->detailClusterId);
    }

    private function fakeCluster(string $id): \Phpdup\Clustering\Cluster
    {
        return new \Phpdup\Clustering\Cluster(id: $id, members: [], similarity: 1.0, exact: true);
    }

    public function testInitWithoutFactoryMarksAnalysisCompleteAndReturnsBatchedCmd(): void
    {
        $model = $this->buildModel();
        $cmd = $model->init();
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertTrue($model->viewState->analysisComplete);
    }

    public function testInitWithFactoryDoesNotMarkAnalysisCompleteYet(): void
    {
        $factory = function () {
            $state = new \Phpdup\Pipeline\PipelineState(
                \Phpdup\Cli\Config::defaults([__DIR__ . '/../../Fixtures/sql']),
            );
            $gen = (function () { yield \Phpdup\Pipeline\Stage::Scanning; yield \Phpdup\Pipeline\Stage::Scanning; })();
            return [$gen, $state];
        };
        $model = new \Phpdup\Tui\PhpdupModel(
            new \Phpdup\Pipeline\PipelineState(\Phpdup\Cli\Config::defaults([__DIR__])),
            new \Phpdup\Tui\ViewState(),
            \Phpdup\Tui\TuiRunner::resolveTheme('plain'),
            $factory,
        );
        $cmd = $model->init();
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertFalse($model->viewState->analysisComplete);
    }

    public function testStagePumpedMsgAdvancesGeneratorAndSchedulesNextPump(): void
    {
        $factory = function () {
            $state = new \Phpdup\Pipeline\PipelineState(
                \Phpdup\Cli\Config::defaults([__DIR__]),
            );
            $gen = (function () {
                yield \Phpdup\Pipeline\Stage::Scanning;
                yield \Phpdup\Pipeline\Stage::Preprocessing;
            })();
            return [$gen, $state];
        };
        $model = new \Phpdup\Tui\PhpdupModel(
            new \Phpdup\Pipeline\PipelineState(\Phpdup\Cli\Config::defaults([__DIR__])),
            new \Phpdup\Tui\ViewState(),
            \Phpdup\Tui\TuiRunner::resolveTheme('plain'),
            $factory,
        );
        $model->init();

        // First pump rewinds → generator at first yield. Schedules next pump.
        [$_, $cmd1] = $model->update(new \Phpdup\Tui\Msg\StagePumpedMsg());
        $this->assertInstanceOf(\Closure::class, $cmd1, 'first pump schedules another');
        $this->assertFalse($model->viewState->analysisComplete);

        // Second pump advances to second yield. Still scheduling.
        [$_, $cmd2] = $model->update(new \Phpdup\Tui\Msg\StagePumpedMsg());
        $this->assertInstanceOf(\Closure::class, $cmd2);

        // Third pump exhausts the generator. analysisComplete flips on; no more cmds.
        [$_, $cmd3] = $model->update(new \Phpdup\Tui\Msg\StagePumpedMsg());
        $this->assertNull($cmd3, 'no more pumps once generator is exhausted');
        $this->assertTrue($model->viewState->analysisComplete);
    }

    public function testRestartPipelineMsgRebuildsIteratorAndState(): void
    {
        $callCount = 0;
        $factory = function () use (&$callCount) {
            $callCount++;
            $state = new \Phpdup\Pipeline\PipelineState(
                \Phpdup\Cli\Config::defaults([__DIR__]),
            );
            $gen = (function () { yield \Phpdup\Pipeline\Stage::Scanning; })();
            return [$gen, $state];
        };
        $model = new \Phpdup\Tui\PhpdupModel(
            new \Phpdup\Pipeline\PipelineState(\Phpdup\Cli\Config::defaults([__DIR__])),
            new \Phpdup\Tui\ViewState(),
            \Phpdup\Tui\TuiRunner::resolveTheme('plain'),
            $factory,
        );
        $model->init();
        $this->assertSame(1, $callCount, 'init triggers one factory call');

        // Drain the first pipeline.
        $model->update(new \Phpdup\Tui\Msg\StagePumpedMsg());
        $model->update(new \Phpdup\Tui\Msg\StagePumpedMsg());
        $this->assertTrue($model->viewState->analysisComplete);

        // Restart fires a new factory call and resets analysisComplete.
        [$_, $cmd] = $model->update(new \Phpdup\Tui\Msg\RestartPipelineMsg(reload: 7));
        $this->assertSame(2, $callCount, 'restart triggers a fresh factory call');
        $this->assertSame(7, $model->reloadCount);
        $this->assertFalse($model->viewState->analysisComplete);
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testRestartPipelineMsgIsNoOpWithoutFactory(): void
    {
        $model = $this->buildModel(); // no factory
        [$_, $cmd] = $model->update(new \Phpdup\Tui\Msg\RestartPipelineMsg(reload: 1));
        $this->assertNull($cmd);
        $this->assertSame(0, $model->reloadCount);
    }

    /**
     * shell-S40 (php-dup-finder-shell-051): the RSS sample behind the four
     * progress callbacks is throttled to one allocator read per
     * PhpdupModel::RSS_SAMPLE_INTERVAL_SECONDS window — between windows the
     * last reading survives untouched (a stale gauge, not a syscall storm).
     * Fake clock via the injected seam: zero wall-clock, CI-safe.
     */
    public function testRssSamplingIsThrottledToTheIntervalWindow(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float { return $now; };

        $state = new PipelineState(Config::defaults([__DIR__]));
        $model = new PhpdupModel(
            $state,
            new ViewState(),
            TuiRunner::resolveTheme('plain'),
            null,
            $clock,
        );

        $model->onFileScanned(1, 2);
        $this->assertGreaterThan(0, $state->rssBytes, 'first callback samples the gauges');

        $state->rssBytes = 1; // poison: any re-sample overwrites with real usage
        $model->onPairScored(1, 2);
        $model->onFilePreprocessed(1, 0, 0);
        $model->onClusterRefactored(1, 1);
        $this->assertSame(1, $state->rssBytes, 'no allocator read inside the throttle window');

        $now += 0.25; // exactly the window: the next callback lands a fresh sample
        $model->onFileScanned(2, 2);
        $this->assertGreaterThan(1, $state->rssBytes, 'sampling resumes once the interval elapsed');
    }

    public function testRssThrottleResetsOnFreshRun(): void
    {
        // Watch-mode restarts hand the dashboard a fresh state; its first
        // progress callback must not be swallowed by the previous run's window.
        $now = 5000.0;
        $clock = static function () use (&$now): float { return $now; };

        $state = new PipelineState(Config::defaults([__DIR__]));
        $model = new PhpdupModel(
            $state,
            new ViewState(),
            TuiRunner::resolveTheme('plain'),
            static function () use ($state): array {
                $state->rssBytes = 0;
                return [
                    (static function (): \Generator { yield Stage::Scanning; })(),
                    $state,
                ];
            },
            $clock,
        );

        $model->init();
        $model->onFileScanned(1, 1);
        $this->assertGreaterThan(0, $state->rssBytes, 'first sample of a run lands immediately');

        $state->rssBytes = 1; // poison mid-window
        $model->update(new \Phpdup\Tui\Msg\RestartPipelineMsg(reload: 1));
        $model->onFileScanned(2, 2); // first callback after the restart
        $this->assertGreaterThan(1, $state->rssBytes, 'a fresh run zeroes the throttle window');
    }

    /**
     * once-S07 (php-dup-finder-once-007): a pump that throws must surface as
     * a loud halt — recorded reason/class, error panel in the body, Error
     * taskbar state, breadcrumb toast through the display door, and an
     * exit code the runner can propagate (1, not the old silent 0).
     */
    public function testPipelineHaltRecordsErrorShowsPanelAndFlipsExitCode(): void
    {
        $model = $this->buildThrowingModel(new \RuntimeException('TED budget blown'));
        $model->init();
        $model->update(new StagePumpedMsg()); // rewind → first yield
        $model->update(new StagePumpedMsg()); // advance → second yield
        [$_, $cmd] = $model->update(new StagePumpedMsg()); // advance → throw

        $this->assertNull($cmd, 'a halt stops the pump chain');
        $this->assertSame('TED budget blown', $model->pipelineError);
        $this->assertSame(\RuntimeException::class, $model->pipelineErrorClass);
        $this->assertTrue($model->viewState->analysisComplete);
        $this->assertSame(1, $model->exitCode(), 'halted TUI carries the CLI runtime-error exit');

        $view = $model->view();
        $this->assertStringContainsString('✗ phpdup: TED budget blown', $view->body);
        $this->assertSame(ProgressBarState::Error, $view->progressBar->state);

        $toasts = $model->viewState->toastQueue;
        $this->assertNotSame([], $toasts, 'halt leaves a toast breadcrumb');
        $this->assertStringContainsString('Pipeline error: TED budget blown', $toasts[count($toasts) - 1]);
    }

    /**
     * once-S07 acceptance: a budget halt shows the CLI's remedy text inside
     * the TUI. At a wide window both physical message lines survive verbatim;
     * at 80 columns the panel word-wraps instead of clipping, so every
     * remedy token is still on screen — whitespace-normalised, the full CLI
     * message is present in the body.
     */
    public function testBudgetHaltKeepsTheCliRemedyVisible(): void
    {
        $failure = CandidateBudgetException::exceeded(1_000, 500, 10_000);
        [$lineOne, $lineTwo] = explode("\n", $failure->getMessage());

        $model = $this->buildThrowingModel($failure);
        $model->update(new WindowSizeMsg(400, 60));
        $model->init();
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg());

        $wide = $model->view()->body;
        $this->assertStringContainsString('phpdup: ' . $lineOne, $wide, 'wide window keeps source line 1 verbatim');
        $this->assertStringContainsString($lineTwo, $wide, 'wide window keeps the remedy line verbatim');

        // Same halt in the default narrow viewport: wrapped, never truncated.
        $narrow = $this->buildThrowingModel($failure);
        $narrow->init();
        $narrow->update(new StagePumpedMsg());
        $narrow->update(new StagePumpedMsg());
        $narrow->update(new StagePumpedMsg());

        $collapse = static fn(string $text): string => (string) preg_replace('/\s+/u', ' ', $text);
        $body = $narrow->view()->body;
        $this->assertStringContainsString(
            $collapse($failure->getMessage()),
            $collapse($body),
            'word-wrap (not clip) keeps the whole CLI message readable at 80 cols',
        );
        foreach (['lower --max-df', 'raise --candidate-budget', 'MAX_CANDIDATE_PAIRS'] as $token) {
            $this->assertStringContainsString($token, $body, "remedy token {$token} visible at 80 cols");
        }
    }

    /**
     * The clean path must keep its old posture: exit 0, no panel, Normal
     * taskbar — the halt arms are false-positive-free.
     */
    public function testCleanRunExitsZeroWithoutPanel(): void
    {
        $factory = static function (): array {
            $gen = (static function (): \Generator {
                yield Stage::Scanning;
                yield Stage::Preprocessing;
            })();
            return [$gen, new PipelineState(Config::defaults([__DIR__]))];
        };
        $model = new PhpdupModel(
            new PipelineState(Config::defaults([__DIR__])),
            new ViewState(),
            TuiRunner::resolveTheme('plain'),
            $factory,
        );
        $model->init();
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg()); // exhausts → completion

        $this->assertNull($model->pipelineError);
        $this->assertSame(0, $model->exitCode());
        $this->assertTrue($model->viewState->analysisComplete);
        $this->assertStringNotContainsString('✗ phpdup', $model->view()->body);
        $this->assertSame(ProgressBarState::Normal, $model->view()->progressBar->state);
    }

    /**
     * A watch-mode restart of a halted run wipes the halt: no stale panel,
     * exit code back to zero (a later crash re-arms it from scratch).
     */
    public function testRestartAfterHaltClearsErrorPanelAndExitCode(): void
    {
        $calls = 0;
        $factory = function () use (&$calls): array {
            $calls++;
            $gen = $calls === 1
                ? (static function (): \Generator {
                    yield Stage::Scanning;
                    throw new \RuntimeException('first run blows up');
                })()
                : (static function (): \Generator {
                    yield Stage::Scanning;
                })();
            return [$gen, new PipelineState(Config::defaults([__DIR__]))];
        };
        $model = new PhpdupModel(
            new PipelineState(Config::defaults([__DIR__])),
            new ViewState(),
            TuiRunner::resolveTheme('plain'),
            $factory,
        );
        $model->init();
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg()); // throw
        $this->assertSame(1, $model->exitCode());
        $this->assertStringContainsString('✗ phpdup: first run blows up', $model->view()->body);

        $model->update(new RestartPipelineMsg(reload: 1));

        $this->assertNull($model->pipelineError);
        $this->assertNull($model->pipelineErrorClass);
        $this->assertSame(0, $model->exitCode());
        $this->assertStringNotContainsString('✗ phpdup', $model->view()->body);
    }

    /**
     * The halt reason is attacker-influenced text (a message can embed
     * scanned paths): the panel and the toast must cross the same display
     * door as every other terminal cell — no raw control byte reaches the
     * screen, hostile bytes survive as printable notation.
     */
    public function testHostileHaltMessageIsDisplaySanitized(): void
    {
        $hostile = "boom \x1b[31mred\x07 and \x00 done";
        $model = $this->buildThrowingModel(new \RuntimeException($hostile));
        $model->init();
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg());

        $body = $model->view()->body;
        $this->assertStringNotContainsString("\x1b", $body, 'no raw ESC byte on screen');
        $this->assertStringNotContainsString("\x07", $body, 'no raw BEL byte on screen');
        $this->assertStringNotContainsString("\x00", $body, 'no raw NUL byte on screen');
        $this->assertStringContainsString("\u{FFFD}", $body, 'ESC/NUL render as the shared substitute marker');
        $this->assertStringContainsString('^G', $body, 'BEL renders as caret notation');

        $toast = $model->viewState->toastQueue[count($model->viewState->toastQueue) - 1];
        $this->assertStringNotContainsString("\x1b", $toast);
        $this->assertStringNotContainsString("\x00", $toast);
        $this->assertStringContainsString('Pipeline error: boom', $toast);
    }

    /**
     * Model whose live pipeline yields once, then throws the given failure —
     * the unit-scale stand-in for a ClusterStage halt.
     */
    private function buildThrowingModel(\Throwable $failure): PhpdupModel
    {
        $factory = static function () use ($failure): array {
            $gen = (static function () use ($failure): \Generator {
                yield Stage::Scanning;
                yield Stage::Clustering;
                throw $failure;
            })();
            return [$gen, new PipelineState(Config::defaults([__DIR__]))];
        };
        return new PhpdupModel(
            new PipelineState(Config::defaults([__DIR__])),
            new ViewState(),
            TuiRunner::resolveTheme('plain'),
            $factory,
        );
    }

    private function buildModel(): PhpdupModel
    {
        $state = new PipelineState(Config::defaults([__DIR__]));
        return new PhpdupModel($state, new ViewState(), TuiRunner::resolveTheme('plain'));
    }

    // ── once-S72: pump smoothness (php-dup-finder-once-087 A-20 / A-21) ─────

    /** @return \Generator<int, Stage> */
    private static function twoStepGenerator(): \Generator
    {
        yield Stage::Scanning;
        yield Stage::Preprocessing;
    }

    /** Pull the first spinner-tick message out of a batched Cmd, if any. */
    private static function spinnerTickOf(BatchMsg $batch): ?SpinnerTickMsg
    {
        foreach ($batch->cmds as $cmd) {
            $req = $cmd();
            if (!$req instanceof TickRequest) {
                continue;
            }
            $msg = ($req->produce)();
            if ($msg instanceof SpinnerTickMsg) {
                return $msg;
            }
        }
        return null;
    }

    /**
     * once-S72 A-21: the live model must spend EXACTLY ONE factory call per
     * boot — the old init path (a second construction after the host's
     * seeding call) is the double-build the finding names.
     */
    public function testInitCallsIteratorFactoryExactlyOncePerBoot(): void
    {
        $calls = 0;
        $factory = function () use (&$calls): array {
            $calls++;
            return [self::twoStepGenerator(), new PipelineState(Config::defaults([__DIR__]))];
        };
        $model = new PhpdupModel(
            new PipelineState(Config::defaults([__DIR__])),
            new ViewState(),
            TuiRunner::resolveTheme('plain'),
            $factory,
        );

        $model->init();
        $this->assertSame(1, $calls, 'boot costs one factory call');

        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg()); // exhausts
        $this->assertTrue($model->viewState->analysisComplete);
        $this->assertSame(1, $calls, '…and still one for the whole drained run');
    }

    /**
     * once-S72 A-21 acceptance shape: a host that front-loads the iterator
     * pair (the proposed TuiRunner::buildLiveModel leg) boots with ZERO
     * further factory calls and pumps the provided generator to completion.
     */
    public function testInitialRunIsAdoptedWithoutAFactoryCall(): void
    {
        $calls = 0;
        $factory = function () use (&$calls): array {
            $calls++;
            return [self::twoStepGenerator(), new PipelineState(Config::defaults([__DIR__]))];
        };
        $liveState = new PipelineState(Config::defaults([__DIR__]));
        $model = new PhpdupModel(
            new PipelineState(Config::defaults([__DIR__])),
            new ViewState(),
            TuiRunner::resolveTheme('plain'),
            $factory,
            null,
            [self::twoStepGenerator(), $liveState],
        );

        $cmd = $model->init();
        $this->assertSame(0, $calls, 'a front-loaded pair must not re-invoke the factory at init');
        $this->assertSame($liveState, $model->state, 'the adopted run installs the host-built state');
        $this->assertInstanceOf(\Closure::class, $cmd);

        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg());
        [$_, $tail] = $model->update(new StagePumpedMsg());
        $this->assertNull($tail, 'the adopted generator drains through the same pump chain');
        $this->assertTrue($model->viewState->analysisComplete);
        $this->assertSame(0, $calls, 'the factory never fired for this run');
    }

    /**
     * once-S72 A-20: completion kills the spinner tick chain — the last
     * already-scheduled tick lands once, paints, and reschedules nothing.
     * (The old chain fired ~12 idle wakeups per second forever after the
     * analysis finished, hidden behind the completion banner.)
     */
    public function testCompletionKillsTheSpinnerTickChain(): void
    {
        $factory = static fn(): array => [
            self::twoStepGenerator(),
            new PipelineState(Config::defaults([__DIR__])),
        ];
        $model = new PhpdupModel(
            new PipelineState(Config::defaults([__DIR__])),
            new ViewState(),
            TuiRunner::resolveTheme('plain'),
            $factory,
        );

        $batch = ($model->init())();
        $this->assertInstanceOf(BatchMsg::class, $batch);
        $tick = self::spinnerTickOf($batch);
        $this->assertInstanceOf(SpinnerTickMsg::class, $tick, 'init arms the spinner chain once');

        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg()); // exhausts → analysisComplete
        $this->assertTrue($model->viewState->analysisComplete);

        [$_, $after] = $model->update($tick); // the old chain's final scheduled tick lands
        $this->assertNull(
            $after,
            'once-S72 A-20: a spinner tick landing after completion reschedules NOTHING — '
            . 'this goes red while the chain self-reschedules past completion',
        );
    }

    /**
     * once-S72 A-20: a restart re-arms one spinner chain under a FRESH
     * spinner identity, so straggler ticks from the dead chain die at the id
     * guard instead of stacking into double-speed chains.
     */
    public function testRestartReArmsSpinnerUnderFreshIdentityAndKillsStragglers(): void
    {
        $model = new PhpdupModel(
            new PipelineState(Config::defaults([__DIR__])),
            new ViewState(),
            TuiRunner::resolveTheme('plain'),
            static fn(): array => [
                self::twoStepGenerator(),
                new PipelineState(Config::defaults([__DIR__])),
            ],
        );

        $tick1 = self::spinnerTickOf(($model->init())());
        $this->assertInstanceOf(SpinnerTickMsg::class, $tick1);

        [$_, $cmd] = $model->update(new RestartPipelineMsg(reload: 1));
        $this->assertInstanceOf(\Closure::class, $cmd);
        $restartBatch = $cmd();
        $this->assertInstanceOf(
            BatchMsg::class,
            $restartBatch,
            'once-S72: restart re-arms BOTH the spinner chain and the pump chain',
        );
        $tick2 = self::spinnerTickOf($restartBatch);
        $this->assertInstanceOf(SpinnerTickMsg::class, $tick2);
        $this->assertNotSame($tick1->id, $tick2->id, 'a fresh run mints a fresh spinner identity');

        [$_, $straggler] = $model->update($tick1); // old-chain tick arrives late
        $this->assertNull($straggler, 'the dead chain’s straggler cannot start a second living chain');
    }

    /**
     * once-S72 A-20: Phase-2 mode (pipeline already ran; nothing to pump)
     * arms no spinner chain at all — there is nothing for it to animate.
     */
    public function testInitWithoutFactoryArmsNoSpinnerChain(): void
    {
        $model = $this->buildModel(); // no factory
        $batch = $model->init()();

        $this->assertInstanceOf(BatchMsg::class, $batch);
        $this->assertSame(
            [],
            $batch->cmds,
            'once-S72 A-20: a born-complete dashboard burns zero spinner wakeups',
        );
        $this->assertTrue($model->viewState->analysisComplete);
    }

    // ── once-S96: finish-state honesty (php-dup-finder-once-120) ─────────────

    /**
     * once-120 U-07: the elapsed clock must FREEZE at the completion
     * instant. The old sparkline recomputed wall-time on every paint, so a
     * finished dashboard counted up forever behind a static banner. Fake
     * clock via the injected seam (the ticker reads the same door shell-S40
     * introduced for the RSS throttle): mid-run the figure tracks the clock,
     * after the final pump it is stamped dead — a ticks-after-done assert in
     * both the model's own figure and the painted label.
     */
    public function testElapsedClockFreezesAtCompletion(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float { return $now; };
        $model = new PhpdupModel(
            new PipelineState(Config::defaults([__DIR__])),
            new ViewState(),
            TuiRunner::resolveTheme('plain'),
            static fn(): array => [self::twoStepGenerator(), new PipelineState(Config::defaults([__DIR__]))],
            $clock,
        );

        $model->init();
        $model->onStageEnd(Stage::Clustering); // one measured bar so the elapsed row exists at all
        $model->update(new StagePumpedMsg());
        $now = 1042.5; // the run takes 42.5 seconds of fake time
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg()); // exhausts → completion stamps the clock

        $this->assertTrue($model->viewState->analysisComplete);
        $this->assertSame(42.5, $model->elapsedSeconds(), 'frozen at the completion instant');
        $this->assertStringContainsString('elapsed 42.50s', $model->view()->body, 'paint shows the frozen figure');

        $now = 1099.5; // ticking AFTER done is the defect: the run is over, the clock must not follow
        $this->assertSame(42.5, $model->elapsedSeconds(), 'once-120 U-07: no ticking after completion');
        $this->assertStringContainsString(
            'elapsed 42.50s',
            $model->view()->body,
            'post-done paints keep the frozen label, never a live ticker over a finished run',
        );
    }

    /**
     * once-120 U-07 companion arms: the freeze must not become a stop — the
     * ticker still tracks a pumping run — and {@see \Phpdup\Tui\PhpdupModel::beginRun()}
     * clears the stamp, so a watch-mode restart re-animates honestly.
     */
    public function testElapsedTicksWhileRunningAndUnfreezesOnRestart(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float { return $now; };
        $model = new PhpdupModel(
            new PipelineState(Config::defaults([__DIR__])),
            new ViewState(),
            TuiRunner::resolveTheme('plain'),
            static fn(): array => [self::twoStepGenerator(), new PipelineState(Config::defaults([__DIR__]))],
            $clock,
        );

        $model->init();
        $model->onStageEnd(Stage::Clustering);
        $now = 1007.5;
        $this->assertSame(7.5, $model->elapsedSeconds(), 'mid-run the ticker tracks the clock');

        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg()); // complete → frozen at 7.5
        $this->assertSame(7.5, $model->elapsedSeconds());

        $now = 1500.0;
        $model->update(new RestartPipelineMsg(reload: 1)); // beginRun: startedAt = 1500, stamp cleared
        $now = 1560.0;
        $this->assertSame(
            60.0,
            $model->elapsedSeconds(),
            'a fresh run un-freezes the ticker — beginRun clears the stamp with the old run',
        );
    }

    /**
     * once-120 U-10: Scanning and Reporting fire onStageEnd() like every
     * stage but no stage ever MEASURES a timing for them — the old match
     * defaulted them to 0.0 and drew two permanent fake "instant" bars on
     * every run. Discriminator at the surface: with only unmeasured stages
     * ended, the sparkline row (elapsed label) must be absent; a measured
     * stage brings it back. Under the old code the first arm is red — the
     * fake bars made the row render.
     */
    public function testSparklineBarsOnlyForMeasuredStages(): void
    {
        $model = $this->buildModel();

        $model->onStageEnd(Stage::Scanning);
        $model->onStageEnd(Stage::Reporting);
        $this->assertStringNotContainsString(
            'elapsed',
            $model->view()->body,
            'once-120 U-10: unmeasured Scanning/Reporting contribute no fake 0.0 bars',
        );

        $model->onStageEnd(Stage::Clustering); // timings carries a measured value → one honest bar
        $this->assertStringContainsString('elapsed', $model->view()->body);
    }

    /**
     * once-120 U-08 + U-11: the completed run must END ON A VERDICT — the
     * result sentence with CountPhrase law ("1 cluster found", never
     * "1 clusters"), and the same headline grammar on the banner. Red under
     * the old code twice over: no verdict row existed, and the banner wrote
     * '%d clusters' / '%d blocks' raw. Scope law: the headline surfaces
     * (banner + verdict) carry prose grammar; the pane cells are labelled
     * columnar stats, which the CountPhrase doctrine (and the existing
     * '1 paths' pin) keeps as fixed plural nouns.
     */
    public function testCompletionCarriesVerdictSentenceWithSingularGrammar(): void
    {
        $lines = explode("\n", $this->buildCompletedModel(1)->view()->body);
        $banner = $lines[0];
        $verdictRow = implode("\n", array_filter($lines, static fn(string $l): bool => str_contains($l, '✓')));

        $this->assertStringContainsString('✓ 1 cluster found', $verdictRow, 'once-120 U-08: the result verdict sentence');
        $this->assertStringNotContainsString('1 clusters', $banner, 'once-120 U-11: no "1 clusters" on the banner');
        $this->assertStringNotContainsString('1 blocks', $banner, 'once-120 U-11: no "1 blocks" on the banner');
        $this->assertStringContainsString('· 1 block · 1 cluster', $banner);
    }

    /** once-120 U-08/U-11 plural + empty arms. */
    public function testCompletionCarriesVerdictSentencePluralAndEmpty(): void
    {
        $this->assertStringContainsString('✓ 3 clusters found', $this->buildCompletedModel(3)->view()->body);
        $this->assertStringContainsString('no duplicates found', $this->buildCompletedModel(0)->view()->body,
            'a clean zero run says so instead of silently showing stopped panes');
        $this->assertStringNotContainsString('0 duplicates found', $this->buildCompletedModel(0)->view()->body);
    }

    /**
     * once-S07 exit-seam binding, finish-state side (once-120): a halted run
     * carries its ✗ panel — the honest verdict sentence must NOT also print,
     * or the screen dresses a failure up as a clean "0 clusters found".
     * "Looks clean while exit!=0" is exactly the once-120 class.
     */
    public function testHaltCarriesNoVerdictSentence(): void
    {
        $model = $this->buildThrowingModel(new \RuntimeException('boom during clustering'));
        $model->init();
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg()); // throws → halt

        $body = $model->view()->body;
        $this->assertSame(1, $model->exitCode());
        $this->assertStringContainsString('✗ phpdup', $body, 'halt panel is the honest terminal state');
        $this->assertStringNotContainsString('✓', $body, 'once-S96: a halted run never dresses up as a clean verdict');
    }

    /**
     * once-120 U-09 regression tripwire (GREEN-BY-DESIGN — disclosed, not a
     * re-fix): once-S61 made every row clip through clipToCells, which ends
     * a truncated run in an honest ellipsis. No S96 leg may regress it, so
     * the clipped headline is pinned here at a 20-col viewport.
     */
    public function testClippedHeadlineCarriesEllipsis(): void
    {
        $model = $this->buildCompletedModel(3);
        $model->update(new WindowSizeMsg(20, 40));

        $this->assertStringContainsString(
            '…',
            $model->view()->body,
            'once-120 U-09: a truncated headline ends in an ellipsis, never silent loss',
        );
    }

    /**
     * once-S96 finish-state law: the verdict is part of the honest frame,
     * not an auxiliary — on a row-starved terminal the clamp drops help,
     * sparkline and status BEFORE it. Red under the old code (no verdict
     * row existed at all).
     */
    public function testVerdictRowSurvivesTheVerticalClamp(): void
    {
        $model = $this->buildCompletedModel(2);
        $model->update(new WindowSizeMsg(80, 1));

        $this->assertStringContainsString(
            '2 clusters found',
            $model->view()->body,
            'the finish verdict is never clamped away — rows may scroll, the truth may not',
        );
    }

    /**
     * Drain a two-step generator to completion with a synthetic corpus of
     * $clusterCount clusters (plus matching files/blocks so the headline
     * plural sites are exercised) — the S96 finish-state surface under test.
     */
    private function buildCompletedModel(int $clusterCount): PhpdupModel
    {
        $model = new PhpdupModel(
            new PipelineState(Config::defaults([__DIR__])),
            new ViewState(),
            TuiRunner::resolveTheme('plain'),
            static fn(): array => [self::twoStepGenerator(), new PipelineState(Config::defaults([__DIR__]))],
        );

        $model->init();
        $model->state->files   = array_fill(0, max(1, $clusterCount), '/tmp/x.php');
        $model->state->blocks  = array_fill(0, max(1, $clusterCount), new \stdClass());
        $clusters              = [];
        for ($i = 0; $i < $clusterCount; $i++) {
            $clusters[] = $this->fakeCluster('C' . $i);
        }
        $model->state->clusters = $clusters;

        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg());
        $model->update(new StagePumpedMsg()); // exhausts → the finish state
        $this->assertTrue($model->viewState->analysisComplete, 'helper reaches completion');

        return $model;
    }
}
