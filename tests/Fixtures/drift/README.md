# tests/Fixtures/drift — item 4.1 drift analyzer fixtures

Synthetic clusters for the DriftAnalyzer predicates (improve_logic_info.md
§4.1). Each directory is one cluster: files are members; the DriftAnalyzer
test unifies them with the real AntiUnifier and asserts findings.

Fire sets: guard (4-member), guard_pair (2-member Start.php analog),
guard_trio (replacement-statement tie case), guard_five (optional-cap
tuning), auth (non-guard shape via the auth vocabulary), sanitizer,
options, literal, odd.

Control sets (MUST produce zero findings under the full vocabulary):
guard_uniform, sanitizer_uniform, options_uniform, literal_uniform,
odd_split.

Shared guard strings deliberately carry no module identity words so the
copy-paste-literal predicate stays silent outside `literal/`, and divergent
literals sit outside call `args` paths wherever the option arm must not
fire.
