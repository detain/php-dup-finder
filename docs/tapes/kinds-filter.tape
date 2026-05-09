Output docs/media/kinds-filter.gif

Set Shell "bash"
Set FontSize 14
Set Width 1100
Set Height 540
Set Theme "Catppuccin Mocha"
Set TypingSpeed 32ms
Set PlaybackSpeed 1.0

Hide
Type "clear" Enter
Show

Sleep 400ms
Type "bin/phpdup analyze tests/Fixtures --plain --no-cache --kinds=method --min-impact=20"
Sleep 400ms
Enter
Sleep 6s
