Output docs/media/output-formats.gif

Set Shell "bash"
Set FontSize 13
Set Width 1100
Set Height 720
Set Padding 10
Set Margin 0
Set Theme "Catppuccin Mocha"
Set TypingSpeed 28ms
Set PlaybackSpeed 1.0

Hide
Type "clear" Enter
Type "rm -rf /tmp/phpdup-out && mkdir -p /tmp/phpdup-out"
Enter
Show

Sleep 400ms
Type "bin/phpdup analyze tests/Fixtures/sql --plain --limit=1 --min-impact=20 --no-cache --sarif=/tmp/phpdup-out/out.sarif --gitlab-sast=/tmp/phpdup-out/gitlab.json --diff=/tmp/phpdup-out/diffs --checkstyle=/tmp/phpdup-out/checkstyle.xml --json=/tmp/phpdup-out/out.json"
Sleep 400ms
Enter
Sleep 6s

Type "ls /tmp/phpdup-out"
Enter
Sleep 2s

Hide
Type "rm -rf /tmp/phpdup-out"
Enter
