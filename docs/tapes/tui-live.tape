Output docs/media/tui-live.gif

Set Shell "bash"
Set FontSize 14
Set Width 1100
Set Height 720
Set Theme "Catppuccin Mocha"
Set TypingSpeed 35ms
Set PlaybackSpeed 1.0

Hide
Type "clear" Enter
Show

Sleep 500ms
Type "bin/phpdup analyze tests/Fixtures --tui --theme=catppuccin --no-cache"
Sleep 400ms
Enter
Sleep 5s
Type "q"
Sleep 500ms
