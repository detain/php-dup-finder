# drift/guard — item 4.1 guard-drift fixture (4-member cluster)

Three members share the identical `is_numeric` guard; `GuardMissing` lacks
it. DriftAnalyzer's guard-drift predicate must name `GuardMissing` as the
verifiably-absent member. Strings in the shared guard carry no module
identity words, so the copy-paste predicate stays silent by design.
