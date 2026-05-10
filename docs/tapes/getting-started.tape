Output docs/media/getting-started.gif

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
Type "# 1. Probe the corpus, let phpdup pick sensible defaults"
Sleep 400ms
Enter
Type "bin/phpdup analyze tests/Fixtures --auto-tune --summary-only --plain"
Sleep 400ms
Enter
Sleep 4s

Type "# 2. Drill in: top 3 clusters, by impact"
Sleep 400ms
Enter
Type "bin/phpdup analyze tests/Fixtures --auto-tune --clusters --limit 3 --plain"
Sleep 400ms
Enter
Sleep 4s

Type "# 3. Generate refactor patches for review"
Sleep 400ms
Enter
Type "bin/phpdup analyze tests/Fixtures --auto-tune --refactor-patch /tmp/patches --plain --summary-only"
Sleep 400ms
Enter
Sleep 4s
