<?php

declare(strict_types=1);

/**
 * verify.php — fail-loud constraint asserts for the DupBanner logo.
 * Any violation exits non-zero with the failing check named.
 */

require __DIR__ . '/DupBanner.php';

use Phpdup\Cli\DupBanner;

$failures = 0;

/**
 * Runs one named assertion, prints its verdict, returns 0/1 for tallying.
 * @param callable():bool $fn
 */
function check(string $name, callable $fn): int
{
    $ok = false;
    try {
        $ok = $fn();
    } catch (Throwable $e) {
        echo "FAIL  {$name}: threw " . $e->getMessage() . "\n";
        return 1;
    }
    if (!$ok) {
        echo "FAIL  {$name}\n";
        return 1;
    }
    echo "ok    {$name}\n";
    return 0;
}

$strip = static fn (string $s): string => (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]|\r/', '', $s);

$tc0 = DupBanner::frame(0);
$tcF = DupBanner::finalFrame();

$failures += check('in-process determinism (tick 0 + final twice)', static function () use ($tc0, $tcF): bool {
    return $tc0 === DupBanner::frame(0) && $tcF === DupBanner::finalFrame();
});

$failures += check('every tick reproducible in-process (all 45 frames twice)', static function (): bool {
    for ($t = 0; $t < DupBanner::FRAMES; $t++) {
        if (DupBanner::frame($t) !== DupBanner::frame($t)) {
            return false;
        }
    }
    return true;
});

$hashOf = static fn (string $arg): string => (string) trim((string) shell_exec(
    'php -r ' . escapeshellarg(
        'require "' . __DIR__ . '/DupBanner.php"; echo hash(\'sha256\', Phpdup\Cli\DupBanner::' . $arg . ');'
    )
));
$failures += check('cross-process determinism tick 0 (2 fresh php runs + in-process)', static function () use ($hashOf, $tc0): bool {
    $h = hash('sha256', $tc0);
    return $hashOf('frame(0)') === $h && $hashOf('frame(0)') === $h;
});
$failures += check('cross-process determinism final frame (2 fresh php runs)', static function () use ($hashOf, $tcF): bool {
    $h = hash('sha256', $tcF);
    return $hashOf('finalFrame()') === $h && $hashOf('finalFrame()') === $h;
});

$failures += check('final frame = 5 rows (<= 7)', static function () use ($tcF): bool {
    return count(explode("\n", rtrim($tcF, "\n"))) === DupBanner::ROWS;
});

$failures += check('final frame cols <= 56, every line \e[0m-terminated', static function () use ($tcF, $strip): bool {
    foreach (explode("\n", rtrim($tcF, "\n")) as $line) {
        if (mb_strwidth($strip($line), 'UTF-8') > DupBanner::COLS) {
            return false;
        }
        if (!str_ends_with($line, "\e[0m")) {
            return false;
        }
    }
    return true;
});

$failures += check('all frames <= 7 rows (no vertical overflow mid-flight)', static function (): bool {
    for ($t = 0; $t < DupBanner::FRAMES; $t++) {
        if (count(explode("\n", rtrim(DupBanner::frame($t), "\n"))) > 7) {
            return false;
        }
    }
    return true;
});

$failures += check('plain fallback: zero escape bytes (text + play)', static function (): bool {
    return !str_contains(DupBanner::text(), "\e")
        && !str_contains(DupBanner::play('plain'), "\e")
        && DupBanner::play('plain') === DupBanner::text();
});

$failures += check('tick budget <= 45 and <= 30 fps and one-shot <= 1.5 s', static function (): bool {
    // read via reflection so the budget is verified as a runtime value
    $rc     = new ReflectionClass(DupBanner::class);
    $frames = (int) $rc->getConstant('FRAMES');
    $tickMs = (int) $rc->getConstant('TICK_MS');
    return $frames <= 45 && $tickMs >= 33 && $frames * $tickMs <= 1500;
});

$failures += check('play = 45 frames, cursor hidden then shown, ends holding final frame', static function () use ($tcF): bool {
    $p = DupBanner::play('tc');
    return str_starts_with($p, "\e[?25l")
        && substr_count($p, "\e[" . DupBanner::ROWS . "A\r") === DupBanner::FRAMES - 1
        && str_ends_with($p, $tcF . "\e[?25h");
});

$failures += check('truecolor stream SGR purity (only 38;2/48;2 valid triples + 0/39/49)', static function (): bool {
    $p = DupBanner::play('tc');
    if (preg_match_all('/\e\[([0-9;]*)m/', $p, $m) === false) {
        return false;
    }
    foreach ($m[1] as $seq) {
        if ($seq === '0' || $seq === '39' || $seq === '49') {
            continue;
        }
        if (preg_match('/^([34])8;2;(\d{1,3});(\d{1,3});(\d{1,3})$/', $seq, $g) !== 1) {
            return false;
        }
        for ($i = 2; $i <= 4; $i++) {
            if ((int) $g[$i] > 255) {
                return false;
            }
        }
    }
    return true;
});

$failures += check('256 stream SGR purity (only 38;5/48;5 0..255 + 0/39/49, no 38;2)', static function () use ($strip): bool {
    $p = DupBanner::play('256');
    if (str_contains($p, ';2;') || str_contains($p, '8;2')) {
        return false;
    }
    if (preg_match_all('/\e\[([0-9;]*)m/', $p, $m) === false) {
        return false;
    }
    foreach ($m[1] as $seq) {
        if ($seq === '0' || $seq === '39' || $seq === '49') {
            continue;
        }
        if (preg_match('/^([34])8;5;(\d{1,3})$/', $seq, $g) !== 1 || (int) $g[2] > 255) {
            return false;
        }
    }
    // final frame still well-formed at 256 depth
    foreach (explode("\n", rtrim(DupBanner::finalFrame('256'), "\n")) as $line) {
        if (!str_ends_with($line, "\e[0m") || mb_strwidth($strip($line), 'UTF-8') > DupBanner::COLS) {
            return false;
        }
    }
    return true;
});

$failures += check('depth/resolve chain via fresh subprocess per env case', static function (): bool {
    /** @var array<array{0: array<string,string>, 1: string}> $cases */
    $cases = [
        [[], 'plain'],
        [[ 'TERM' => 'xterm'], 'plain'],
        [[ 'TERM' => 'xterm-256color'], '256'],
        [[ 'TERM' => 'xterm-truecolor'], 'plain'],
        [[ 'TERM' => 'xterm-256color', 'COLORTERM' => 'truecolor'], 'tc'],
        [[ 'TERM' => 'xterm', 'COLORTERM' => '24bit'], 'tc'],
        [[ 'TERM' => 'dumb'], 'plain'],
        [[ 'NO_COLOR' => '1', 'TERM' => 'xterm-256color', 'COLORTERM' => 'truecolor'], 'plain'],
    ];
    $probe = 'require "' . __DIR__ . '/DupBanner.php"; echo \\Phpdup\\Cli\\DupBanner::resolveDepth();';
    foreach ($cases as [$env, $want]) {
        $cmd = 'env -i PATH=' . escapeshellarg((string) getenv('PATH'));
        foreach (['NO_COLOR', 'COLORTERM', 'TERM'] as $k) {
            if (isset($env[$k])) {
                $cmd .= ' ' . escapeshellarg($k . '=' . $env[$k]);
            }
        }
        $cmd .= ' ' . PHP_BINARY . ' -r ' . escapeshellarg($probe);
        if (trim((string) shell_exec($cmd)) !== $want) {
            return false;
        }
    }
    return true;
});

$failures += check('fail-fast: tick out of range throws', static function (): bool {
    foreach ([-1, 45, 999] as $t) {
        try {
            DupBanner::frame($t);
            return false;
        } catch (InvalidArgumentException) {
        }
    }
    try {
        DupBanner::render(1, 'bogus');
        return false;
    } catch (InvalidArgumentException) {
    }
    return true;
});

$failures += check('purity by source scan: no clock/rng calls in DupBanner.php', static function (): bool {
    $src = (string) file_get_contents(__DIR__ . '/DupBanner.php');
    return preg_match('/\b(microtime|hrtime|usleep|sleep|date|gmdate|time|rand|mt_rand|random_int|srand|array_rand|shuffle)\s*\(/', $src) !== 1;
});

// strip-based visual sanity: final frame contains the rune studs and 6 bricks
$failures += check('final frame content census: studs present, bricks present', static function () use ($tcF, $strip): bool {
    $vis = $strip($tcF);
    $blocks = substr_count($vis, '█') + substr_count($vis, '▀') + substr_count($vis, '▄');
    return $blocks > 180 && substr_count($tcF, '38;2;248;250;252') >= 10;
});

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
