# phpdup demo tape
# Render with: vhs docs/phpdup-demo.tape

Output docs/media/phpdup-demo.gif

Set Shell "bash"
Set FontSize 14
Set Width 1100
Set Height 720
Set Theme "Catppuccin Mocha"
Set TypingSpeed 50ms
Set PlaybackSpeed 1.0

Hide
Type "clear" Enter
Show

Sleep 500ms
Type@30ms "# phpdup — AST-based duplicate-logic detector for PHP"
Sleep 800ms
Enter
Type@30ms "# scan a fixture corpus, top 2 clusters, plain output:"
Enter
Sleep 500ms

Type@40ms "bin/phpdup analyze tests/Fixtures --plain --limit=2 --min-impact=20"
Sleep 600ms
Enter
Sleep 7s

Type@30ms "# now emit machine-readable formats for CI: SARIF, GitLab SAST, diff, checkstyle"
Enter
Sleep 500ms

Type@40ms "bin/phpdup analyze tests/Fixtures/sql --plain --limit=1 --min-impact=20 "
Sleep 200ms
Type@40ms "--sarif=/tmp/phpdup.sarif --gitlab-sast=/tmp/phpdup.gitlab.json "
Sleep 200ms
Type@40ms "--diff=/tmp/phpdup-diffs --checkstyle=/tmp/phpdup.xml"
Sleep 500ms
Enter
Sleep 5s

Type@30ms "# the SARIF output, ready for GitHub PR annotation:"
Enter
Sleep 400ms
Type@40ms "head -25 /tmp/phpdup.sarif"
Sleep 400ms
Enter
Sleep 5s

Type@30ms "# filter by block kind — only methods and closures, no if/for/foreach"
Enter
Sleep 400ms
Type@40ms "bin/phpdup analyze tests/Fixtures --plain --kinds=method,closure --min-impact=20 --limit=1"
Sleep 400ms
Enter
Sleep 6s

Type@30ms "# clean up"
Enter
Type@40ms "rm -rf /tmp/phpdup.sarif /tmp/phpdup.gitlab.json /tmp/phpdup-diffs /tmp/phpdup.xml"
Enter
Sleep 800ms
