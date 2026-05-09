Output docs/media/optional-blocks.gif

Set Shell "bash"
Set FontSize 13
Set Width 1100
Set Height 900
Set Padding 10
Set Margin 0
Set Theme "Catppuccin Mocha"
Set TypingSpeed 30ms
Set PlaybackSpeed 1.0

Hide
Type "clear" Enter
# VHS doesn't honour \" escapes inside double-quoted Type strings, so wrap the
# whole shell command in single quotes and let the shell see the literal
# double-quoted JSON keys.
Type 'printf %s "{\"max_df\":0.5,\"min_block_size\":4,\"min_cluster_impact\":1}" > /tmp/phpdup.json'
Enter
Show

Sleep 400ms
Type "bin/phpdup analyze tests/Fixtures/optional --plain --no-cache --config=/tmp/phpdup.json"
Sleep 400ms
Enter
Sleep 7s

Hide
Type "rm -f /tmp/phpdup.json"
Enter
