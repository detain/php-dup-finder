Output docs/media/live-refactor.gif

Set Shell "bash"
Set FontSize 14
Set Width 1100
Set Height 720
Set Padding 10
Set Margin 0
Set Theme "Catppuccin Mocha"
Set TypingSpeed 35ms
Set PlaybackSpeed 1.0

Hide
Type "clear" Enter
Show

Sleep 500ms
Type "# Generate per-cluster refactor patches + PHPUnit test skeletons"
Sleep 400ms
Enter
Type "bin/phpdup analyze tests/Fixtures --plain --refactor-patch /tmp/patches --refactor-tests /tmp/refactor-tests --summary-only"
Sleep 400ms
Enter
Sleep 4s

Type "# Inspect a generated patch"
Sleep 400ms
Enter
Type "head -25 /tmp/patches/$(ls /tmp/patches | head -1)"
Sleep 400ms
Enter
Sleep 4s

Type "# Inspect a generated test skeleton"
Sleep 400ms
Enter
Type "head -25 /tmp/refactor-tests/$(ls /tmp/refactor-tests | head -1)"
Sleep 400ms
Enter
Sleep 4s
