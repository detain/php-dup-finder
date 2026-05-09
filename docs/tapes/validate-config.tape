Output docs/media/validate-config.gif

Set Shell "bash"
Set FontSize 14
Set Width 1100
Set Height 360
Set Theme "Catppuccin Mocha"
Set TypingSpeed 32ms
Set PlaybackSpeed 1.0

Hide
Type "clear" Enter
Type "printf %s '{"normalization_mode":"loose","similarity_threshold":1.5}' > /tmp/bad.json"
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
