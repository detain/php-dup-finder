Output docs/media/watch-mode.gif

Set Shell "bash"
Set FontSize 13
Set Width 1100
Set Height 540
Set Theme "Catppuccin Mocha"
Set TypingSpeed 30ms
Set PlaybackSpeed 1.0

Hide
Type "clear" Enter
Type "rm -rf /tmp/phpdup-watch && cp -r tests/Fixtures/sql /tmp/phpdup-watch"
Enter
Type "touch -d '2020-01-01' /tmp/phpdup-watch/*.php"
Enter
Show

Sleep 400ms
Type "bin/phpdup analyze /tmp/phpdup-watch --plain --no-cache --watch --min-impact=10 &"
Sleep 200ms
Enter
Sleep 4s
Hide
Type "touch /tmp/phpdup-watch/Repos.php"
Enter
Show
Sleep 4s
Hide
Type "kill %1 2>/dev/null; rm -rf /tmp/phpdup-watch"
Enter
