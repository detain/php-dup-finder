Output docs/media/validate-config.gif

Set Shell "bash"
Set FontSize 14
Set Width 1100
Set Height 280
Set Padding 10
Set Margin 0
Set Theme "Catppuccin Mocha"
Set TypingSpeed 32ms
Set PlaybackSpeed 1.0

Hide
Type "clear" Enter
# Single-quoted Type so the literal JSON double quotes survive into bash.
Type 'printf %s "{\"normalization_mode\":\"loose\",\"similarity_threshold\":1.5}" > /tmp/bad.json'
Enter
Show

Sleep 400ms
Type "bin/phpdup analyze --config=/tmp/bad.json --validate-config"
Sleep 400ms
Enter
Sleep 3s

Hide
Type "rm -f /tmp/bad.json"
Enter
