<?php

declare(strict_types=1);

/**
 * play — watch the held-raw logo.ansi byte stream at real 30 fps pacing.
 * (The class also exposes LensBanner::play() for live, paced playback.)
 *
 *   php play.php [path-to-ansi]
 */

$depth = getenv('DUP_DEPTH');
if ($depth === 'plain') {
    require __DIR__ . '/LensBanner.php';
    echo LensBanner::plain();
    return;
}
require __DIR__ . '/LensBanner.php';
if (($argv[1] ?? '') === '--live') {
    // live playback straight from the class (no byte stream needed)
    LensBanner::play();
    return;
}
$path = $argv[1] ?? __DIR__ . '/logo.ansi';
$stream = (string) file_get_contents($path);
if ($stream === '') {
    fwrite(STDERR, 'empty stream' . PHP_EOL);
    exit(1);
}

// split into frames on the cursor-up rewind marker used by render()
$frames = explode("\e[" . LensBanner::ROWS . 'A' . "\r", $stream);
$frames[0] = preg_replace('/\A\e\[\?25l/', '', $frames[0]) ?? $frames[0];
$last = (string) end($frames);
$frames = array_slice($frames, 0, count($frames) - 1);

foreach ($frames as $frame) {
    echo $frame;
    usleep(LensBanner::TICK_US);
}
echo "\e[" . LensBanner::ROWS . 'A' . "\r" . $last;
echo "\e[?25h";
