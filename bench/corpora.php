<?php
/**
 * Corpus discovery, download & PIN-ENFORCED materialization for the bench.
 *
 * Fetches a curated set of public PHP repos (shallow clone) into
 * `bench/corpora/`. Each entry caps wall time individually and the
 * whole script obeys an overall 5-minute budget so it never hangs
 * CI. Already-downloaded corpora are skipped only AFTER their digests
 * match `bench/corpus-pins.txt`.
 *
 * Supply-chain law (once-S86 / php-dup-finder-once-108): everything
 * materialized here is verified against the pinned tag→commit id and
 * sorted-list/content digests in `bench/corpus-pins.txt` AT
 * MATERIALIZE TIME — and the cached copy is re-verified on every
 * re-run, so "first fetch cached forever" cannot outlive its byte
 * truth. A digest mismatch is a HARD FAILURE (exit 1), never an
 * advisory note. Refreshing a pin means re-deriving it with the exact
 * recipe documented in the pin file and committing the new value.
 *
 * The synthetic corpus is always (re)generated here by the planter
 * below — NOT by Phpdup\Testing\FuzzCorpusGenerator (that generator
 * renders one fixed method shape regardless of template name, which
 * made every "template" fake-diverse and the whole corpus a single
 * clone class). The planter writes structurally distinct clone
 * FAMILIES — one ground-truth cluster per family — plus negative
 * control files that share the families' vocabulary but have no true
 * clone class, so precision has teeth. Paths in the ground-truth
 * manifest are corpus-relative; the ground truth lives next to the
 * files in `.ground-truth.json` for `bench/score.php`. The generated
 * manifest and corpus are checked against the [synthetic-fuzz] pins,
 * making planter drift a loud failure instead of a silently
 * zero-scored benchmark.
 *
 * Usage:
 *   php bench/corpora.php                   # download missing, regenerate synthetic, verify all
 *   php bench/corpora.php --synthetic-only  # regenerate synthetic only (no network for OSS)
 *   php bench/corpora.php --list            # print the planned corpora and exit
 *   php bench/corpora.php --refresh         # force re-clone everything
 *   php bench/corpora.php --verify-only     # verify what exists; clone nothing, plant nothing
 *
 * Importing this file with `PHPDUP_CORPORA_LIB` defined exposes the
 * helpers (planter, pin reader, digests) WITHOUT running anything —
 * tests/Fuzz/DetectionRateTest.php uses that seam to plant a fresh
 * deterministic corpus from the very code the bench ships.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * The corpus plan. Every public corpus MUST have an [oss-<name>]
 * block in bench/corpus-pins.txt pinning the tag's commit id and the
 * php file-list digest — an unpinned plan entry is a materialization
 * failure (mutable tags alone are not trusted, once-108).
 *
 * @return list<array{name:string, url:string, ref:string, sparse:?string, max_mb:int}>
 */
function corpusPlan(): array
{
    return [
        [
            'name'   => 'symfony-console',
            'url'    => 'https://github.com/symfony/console.git',
            'ref'    => 'v6.4.0',
            'sparse' => null,
            'max_mb' => 50,
        ],
        [
            'name'   => 'phpunit',
            'url'    => 'https://github.com/sebastianbergmann/phpunit.git',
            'ref'    => '11.5.0',
            'sparse' => 'src',
            'max_mb' => 50,
        ],
        [
            'name'   => 'laravel-framework-illuminate-http',
            'url'    => 'https://github.com/laravel/framework.git',
            'ref'    => 'v11.0.0',
            'sparse' => 'src/Illuminate/Http',
            'max_mb' => 30,
        ],
        [
            'name'   => 'wordpress-core-wp-includes',
            'url'    => 'https://github.com/WordPress/WordPress.git',
            'ref'    => '6.4.2',
            'sparse' => 'wp-includes',
            'max_mb' => 100,
        ],
    ];
}

/**
 * @param array<int,string> $argv
 */
function main(array $argv): int
{
    $root       = dirname(__DIR__);
    $corporaDir = $root . '/bench/corpora';
    $pinsFile   = $root . '/bench/corpus-pins.txt';

    if (in_array('--list', $argv, true)) {
        foreach (corpusPlan() as $p) {
            printf("%-40s %s @ %s%s\n", $p['name'], $p['url'], $p['ref'], $p['sparse'] ? " (sparse: {$p['sparse']})" : '');
        }
        echo "synthetic-fuzz                           (generated locally)\n";
        return 0;
    }

    $pins = readPins($pinsFile);
    if ($pins === []) {
        fwrite(STDERR, "[fail] cannot read pin data from {$pinsFile} — enforcement has nothing to verify against\n");
        return 1;
    }

    $verifyOnly    = in_array('--verify-only', $argv, true);
    $syntheticOnly = in_array('--synthetic-only', $argv, true);
    $forceRefresh  = in_array('--refresh', $argv, true);
    /** @var list<string> $failures */
    $failures = [];

    if (!$verifyOnly) {
        @mkdir($corporaDir, 0o775, true);
    }

    $globalBudget = 300; // seconds
    $globalStart  = microtime(true);

    foreach (corpusPlan() as $p) {
        $name   = $p['name'];
        $target = $corporaDir . '/' . $name;
        $pin    = $pins['oss-' . $name] ?? null;
        if ($pin === null) {
            echo "[fail] {$name} has no [oss-{$name}] block in bench/corpus-pins.txt — refusing\n";
            $failures[] = "{$name}: unpinned corpus (add an [oss-{$name}] block with commit + php-filelist-sha256)";
            continue;
        }

        $present = is_dir($target) && countPhpFiles($target) > 0;

        if ($verifyOnly || $syntheticOnly) {
            if ($present) {
                $err = verifyPinnedTree($pin, $name, $target);
                if ($err !== null) {
                    echo "[fail] {$err}\n";
                    $failures[] = $err;
                } else {
                    echo "[ok] {$name} present and pin-verified\n";
                }
            } else {
                echo "[skip] {$name} " . ($verifyOnly ? 'not materialized (--verify-only)' : 'untouched (--synthetic-only)') . "\n";
            }
            continue;
        }

        if (!$forceRefresh && $present) {
            if (verifyPinnedTree($pin, $name, $target) === null) {
                echo "[skip] {$name} already present and pin-verified\n";
                continue;
            }
            echo "[fail] {$name} present but pin verification FAILED — removing and re-materializing from the pinned commit\n";
            passthru('rm -rf ' . escapeshellarg($target));
        }

        if ((microtime(true) - $globalStart) > $globalBudget) {
            echo "[budget] global download budget exhausted; {$name} skipped\n";
            $failures[] = "{$name}: global download budget exhausted before materialization";
            continue;
        }
        cloneVerified($p, $pin, $target, $failures, max(10, $globalBudget - (int)(microtime(true) - $globalStart)));
    }

    if ($verifyOnly) {
        verifySynthetic($pins, $corporaDir . '/synthetic-fuzz', $failures);
    } else {
        generateSynthetic($corporaDir . '/synthetic-fuzz');
        verifySynthetic($pins, $corporaDir . '/synthetic-fuzz', $failures);
    }

    verifyReportDigests($pinsFile, $failures);
    verifyHostRepoPins($pins, $failures);

    if ($failures !== []) {
        fwrite(STDERR, "[FAIL] corpus materialization failed (" . count($failures) . " problem(s)):\n");
        foreach ($failures as $f) {
            fwrite(STDERR, "  - {$f}\n");
        }
        return 1;
    }
    echo "[ok] corpora ready under {$corporaDir} — every digest verified against bench/corpus-pins.txt\n";
    return 0;
}

/**
 * Parse the `key = value` lines of a corpus-pins.txt into
 * block → key → first-token value. Comment lines, free-form vendor
 * listing lines (no '=') and the richer [reports] lines (parsed by
 * parseReportPins) all pass through harmlessly.
 *
 * @return array<string,array<string,string>>
 */
function readPins(string $file): array
{
    $lines = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
    $pins  = [];
    $block = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^\[(.+)\]$/', $line, $m) === 1) {
            $block = $m[1];
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $hashPos = strpos($val, ' #');
        if ($hashPos !== false) {
            $val = substr($val, 0, $hashPos);
        }
        $tok = preg_split('/\s+/', trim($val));
        $pins[$block][trim($key)] = (string)($tok[0] ?? '');
    }
    return $pins;
}

/**
 * The [reports] block pins the EXACT bytes extraction adapters parse:
 * `name = path <N> B sha256:<hex64>`.
 *
 * @return array<string,array{path:string,bytes:int,sha256:string}>
 */
function parseReportPins(string $file): array
{
    $lines = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
    $out   = [];
    $block = '';
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || str_starts_with($t, '#')) {
            continue;
        }
        if (preg_match('/^\[(.+)\]$/', $t, $m) === 1) {
            $block = $m[1];
            continue;
        }
        if ($block !== 'reports') {
            continue;
        }
        if (preg_match('/^(\S+)\s*=\s*(\S+)\s+(\d+)\s+B\s+sha256:([0-9a-fA-F]{64})/', $t, $m) === 1) {
            $out[$m[1]] = ['path' => $m[2], 'bytes' => (int)$m[3], 'sha256' => strtolower($m[4])];
        }
    }
    return $out;
}

/** @return list<string> relative paths of regular *.php files, byte-sorted */
function collectPhpPaths(string $dir): array
{
    $paths = [];
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        if ($item->isLink() || !$item->isFile()) {
            continue; // matches `find -type f` semantics: symlinks excluded
        }
        if (!str_ends_with($item->getFilename(), '.php')) {
            continue;
        }
        $paths[] = substr($item->getPathname(), strlen($dir) + 1);
    }
    usort($paths, 'strcmp');
    return $paths;
}

/**
 * sha256 over the newline-terminated, byte-sorted list of relative
 * *.php paths — the same recipe `find … -name '*.php' | sed … |
 * LC_ALL=C sort | sha256sum` produces.
 */
function phpFileListDigest(string $dir): string
{
    return hash('sha256', implode("\n", collectPhpPaths($dir)) . "\n");
}

/** sha256 over `relative-name "\n" bytes` concatenated in byte-sorted name order. */
function phpContentDigest(string $dir): string
{
    $h = hash_init('sha256');
    foreach (collectPhpPaths($dir) as $rel) {
        hash_update($h, $rel . "\n" . (string)file_get_contents($dir . '/' . $rel));
    }
    return hash_final($h);
}

/**
 * @param array<string,string> $pin
 * @return ?string null when the tree matches every pinned digest
 */
function verifyPinnedTree(array $pin, string $name, string $target): ?string
{
    if (!is_dir($target)) {
        return "{$name}: tree missing at {$target}";
    }
    $paths        = collectPhpPaths($target);
    $listDigest   = hash('sha256', implode("\n", $paths) . "\n");
    $expectedList = strtolower($pin['php-filelist-sha256'] ?? '');
    if ($expectedList === '' || !hash_equals($expectedList, $listDigest)) {
        return "{$name}: php file-list digest mismatch (found " . count($paths)
            . " files, {$listDigest}; pinned {$expectedList}) — stale or tampered materialization";
    }
    if (isset($pin['php-files']) && (int)$pin['php-files'] !== count($paths)) {
        return "{$name}: php file count " . count($paths) . " != pinned {$pin['php-files']}";
    }
    if (isset($pin['php-content-sha256']) && !hash_equals(strtolower($pin['php-content-sha256']), phpContentDigest($target))) {
        return "{$name}: aggregate content digest mismatch despite a matching file list";
    }
    return null;
}

/**
 * Resolve a tag to its commit id WITHOUT downloading: annotated tags
 * peel via `refs/tags/<ref>^{}`, lightweight tags answer directly.
 * Returns null when unresolvable (offline host, moved/absent ref).
 */
function resolveTagCommit(string $url, string $ref): ?string
{
    foreach (["refs/tags/{$ref}^{}", "refs/tags/{$ref}"] as $query) {
        $cmd = sprintf(
            'timeout 60 git ls-remote %s %s 2>/dev/null',
            escapeshellarg($url),
            escapeshellarg($query),
        );
        $rc = 0;
        $out = [];
        exec($cmd, $out, $rc);
        if ($rc === 0 && $out !== []) {
            $tok = preg_split('/\s+/', trim($out[0]));
            if (is_array($tok) && preg_match('/^[0-9a-f]{40}$/', $tok[0]) === 1) {
                return $tok[0];
            }
        }
    }
    return null;
}

/**
 * Clone one public corpus at its pinned tag, enforcing provenance at
 * EVERY step: the tag must still peel to the pinned commit (mutable
 * tag disease), the checked-out HEAD must equal it, and the resulting
 * tree must match the pinned file-list digest. Any failure is added
 * to $failures — a silent partial corpus is exactly what once-108
 * prohibited.
 *
 * @param array{name:string, url:string, ref:string, sparse:?string, max_mb:int} $p
 * @param array<string,string> $pin
 * @param list<string> $failures
 */
function cloneVerified(array $p, array $pin, string $target, array &$failures, int $budget): void
{
    $name = $p['name'];

    // 1. the mutable tag must still resolve to the pinned commit
    $commit = resolveTagCommit($p['url'], $p['ref']);
    $pinned = strtolower($pin['commit'] ?? '');
    if ($pinned === '') {
        echo "[fail] {$name}: [oss-{$name}] block carries no commit pin — refusing\n";
        $failures[] = "{$name}: missing commit pin";
        return;
    }
    if ($commit === null) {
        echo "[fail] {$name}: cannot resolve tag {$p['ref']} to a commit (offline host?) — refusing to materialize unverified\n";
        $failures[] = "{$name}: tag {$p['ref']} unresolvable (pin {$pinned})";
        return;
    }
    if (!hash_equals($pinned, $commit)) {
        echo "[fail] {$name}: tag {$p['ref']} now points at {$commit}, pinned at {$pinned} — upstream moved; NOT materializing\n";
        $failures[] = "{$name}: tag moved (remote {$commit} != pinned {$pinned})";
        return;
    }

    // 2. shallow clone at the tag; HEAD must be the pinned commit
    echo "[clone] {$name} ← {$p['url']} @ {$p['ref']} (≤{$budget}s, pinned commit {$pinned})\n";
    $tmp = $target . '.tmp';
    @passthru('rm -rf ' . escapeshellarg($tmp));
    $cmd = sprintf(
        'timeout %d git clone --depth 1 --branch %s %s %s 2>&1',
        $budget,
        escapeshellarg($p['ref']),
        escapeshellarg($p['url']),
        escapeshellarg($tmp),
    );
    $rc = 0;
    passthru($cmd, $rc);
    if ($rc !== 0 || !is_dir($tmp)) {
        echo "[fail] {$name} clone exit {$rc} — corpus materialization FAILED\n";
        $failures[] = "{$name}: clone failed (exit {$rc})";
        @passthru('rm -rf ' . escapeshellarg($tmp));
        return;
    }
    $out = [];
    $rc = 0;
    exec('git -C ' . escapeshellarg($tmp) . ' rev-parse HEAD 2>/dev/null', $out, $rc);
    $head = $rc === 0 && isset($out[0]) ? strtolower(trim($out[0])) : '';
    if (!hash_equals($pinned, $head)) {
        echo "[fail] {$name}: cloned HEAD {$head} != pinned commit {$pinned} — inconsistent tree, refusing\n";
        $failures[] = "{$name}: clone HEAD {$head} != pinned {$pinned}";
        @passthru('rm -rf ' . escapeshellarg($tmp));
        return;
    }

    // 3. materialize (whole tree or sparse subtree), strip git metadata
    if ($p['sparse'] !== null) {
        $src = $tmp . '/' . $p['sparse'];
        if (!is_dir($src)) {
            echo "[fail] {$name}: sparse path {$p['sparse']} missing at the pinned commit — refusing\n";
            $failures[] = "{$name}: sparse path {$p['sparse']} missing at pinned commit";
            @passthru('rm -rf ' . escapeshellarg($tmp));
            return;
        }
        @mkdir($target, 0o775, true);
        passthru('cp -r ' . escapeshellarg($src) . '/. ' . escapeshellarg($target));
        passthru('rm -rf ' . escapeshellarg($tmp));
    } else {
        rename($tmp, $target);
    }
    passthru('rm -rf ' . escapeshellarg($target . '/.git'));

    // 4. content digest of the materialized tree must match the pin
    $err = verifyPinnedTree($pin, $name, $target);
    if ($err !== null) {
        echo "[fail] {$err}\n";
        $failures[] = $err;
        return;
    }
    $sizeMb = (int)round(diskUsageBytes($target) / (1024 * 1024));
    echo "[ok] {$name} materialized at pinned commit {$pinned} ({$sizeMb} MB, " . countPhpFiles($target) . " PHP files, digests verified)\n";
    if ($sizeMb > $p['max_mb']) {
        echo "[warn] {$name} exceeded soft cap {$p['max_mb']} MB (size is advisory; digests are not)\n";
    }
}

/**
 * The planter regenerates the synthetic corpus on every run; pinning
 * the manifest + file list + aggregate content digest makes any drift
 * between the planter, the committed ground truth and the pins a hard
 * materialization failure (the old silent-zero disease).
 *
 * @param array<string,array<string,string>> $pins
 * @param list<string> $failures
 */
function verifySynthetic(array $pins, string $dir, array &$failures): void
{
    $pin = $pins['synthetic-fuzz'] ?? null;
    if ($pin === null) {
        echo "[fail] synthetic-fuzz has no [synthetic-fuzz] pin block — refusing\n";
        $failures[] = 'synthetic-fuzz: no [synthetic-fuzz] block in bench/corpus-pins.txt';
        return;
    }
    $gtFile = $dir . '/.ground-truth.json';
    if (!is_file($gtFile)) {
        echo "[absent] synthetic-fuzz not materialized (--verify-only before a real run?)\n";
        return;
    }
    $gtSha = hash_file('sha256', $gtFile);
    if (!hash_equals(strtolower($pin['ground-truth-sha256'] ?? ''), $gtSha)) {
        echo "[fail] synthetic ground-truth digest mismatch (got {$gtSha}, pinned " . ($pin['ground-truth-sha256'] ?? '') . ")\n";
        $failures[] = 'synthetic-fuzz: .ground-truth.json digest mismatch — planter drift vs committed truth';
    }
    $err = verifyPinnedTree($pin, 'synthetic-fuzz', $dir);
    if ($err !== null) {
        echo "[fail] {$err}\n";
        $failures[] = $err;
    } elseif ($gtSha !== '') {
        echo "[ok] synthetic-fuzz manifest + file list + content digests verified\n";
    }
}

/**
 * [reports] digests: the exact bytes the audit-truth adapters parse.
 * Checked whenever the path exists on this host; a stale digest is a
 * materialization failure (the once-108 home-my pin silently rotted on
 * disk for weeks precisely because nothing compared it).
 *
 * @param list<string> $failures
 */
function verifyReportDigests(string $pinsFile, array &$failures): void
{
    foreach (parseReportPins($pinsFile) as $rname => $r) {
        if (!is_file($r['path'])) {
            echo "[absent] report pin {$rname}: {$r['path']} not on this host (skipped)\n";
            continue;
        }
        $bytes = (int)filesize($r['path']);
        $sha   = (string)hash_file('sha256', $r['path']);
        if ($bytes !== $r['bytes'] || !hash_equals($r['sha256'], $sha)) {
            $msg = "report pin {$rname}: {$r['path']} is stale (pinned {$r['bytes']} B/{$r['sha256']}, on disk {$bytes} B/{$sha})"
                . " — refresh bench/corpus-pins.txt if the new bytes are the intended capture";
            echo "[fail] {$msg}\n";
            $failures[] = $msg;
        } else {
            echo "[ok] report pin {$rname} verified ({$bytes} B)\n";
        }
    }
}

/**
 * [phlix-server]/[phlix-hub]/[home-my] pin git file-list digests of
 * host corpora never materialized here. Checked whenever the repo is
 * reachable from this machine (re-pinned 2026-11-22 to the
 * locale-independent `LC_ALL=C` byte-sort recipe — the old values were
 * captured under default-locale collation and could not be trusted on
 * another host).
 *
 * @param array<string,array<string,string>> $pins
 * @param list<string> $failures
 */
function verifyHostRepoPins(array $pins, array &$failures): void
{
    $checks = [
        'phlix-server' => ['commit' => 'scan-commit', 'digest' => 'src-filelist-sha256', 'dirs' => ['src']],
        'phlix-hub'    => ['commit' => 'scan-commit', 'digest' => 'src-filelist-sha256', 'dirs' => ['src']],
        'home-my'      => ['commit' => 'scan-pin',    'digest' => 'api-ajax-filelist-sha256', 'dirs' => ['include/Api', 'include/ajax']],
    ];
    foreach ($checks as $block => $c) {
        $pin  = $pins[$block] ?? null;
        $repo = $pin['repo'] ?? null;
        if ($pin === null || $repo === null || $repo === '') {
            $failures[] = "{$block}: missing repo/pin data in bench/corpus-pins.txt";
            continue;
        }
        if (!is_dir($repo)) {
            echo "[absent] host repo {$block} ({$repo}) — digest not checkable here\n";
            continue;
        }
        $commit = $pin[$c['commit']] ?? '';
        if (preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
            $failures[] = "{$block}: {$c['commit']} is not a 40-hex commit id";
            continue;
        }
        $rc = 0;
        exec('git -C ' . escapeshellarg($repo) . ' cat-file -t ' . escapeshellarg($commit) . ' 2>/dev/null', $tc, $rc);
        if ($rc !== 0) {
            echo "[absent] host repo {$block}: commit {$commit} unreachable in {$repo} (fresh clone?)\n";
            continue;
        }
        $dirArgs = implode(' ', array_map('escapeshellarg', $c['dirs']));
        $cmd = sprintf(
            'LC_ALL=C git -C %s ls-tree -r --name-only %s -- %s 2>/dev/null | LC_ALL=C sort | sha256sum',
            escapeshellarg($repo),
            escapeshellarg($commit),
            $dirArgs,
        );
        $out = [];
        $rc = 0;
        exec($cmd, $out, $rc);
        $got = $rc === 0 && isset($out[0]) ? strtolower(trim(explode(' ', $out[0])[0])) : '';
        $want = strtolower($pin[$c['digest']] ?? '');
        if ($want === '' || $got === '' || !hash_equals($want, $got)) {
            $msg = "{$block}: file-list digest mismatch at {$commit} (got {$got}, pinned {$want}) — repo moved under the pin or the pin needs re-derivation (LC_ALL=C recipe)";
            echo "[fail] {$msg}\n";
            $failures[] = $msg;
        } else {
            echo "[ok] host repo pin {$block} verified at {$commit}\n";
        }
    }
}

function countPhpFiles(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $rc = 0;
    $out = [];
    exec('find ' . escapeshellarg($dir) . ' -type f -name "*.php" | wc -l', $out, $rc);
    return (int)trim($out[0] ?? '0');
}

function diskUsageBytes(string $dir): int
{
    $out = [];
    @exec('du -sb ' . escapeshellarg($dir) . ' 2>/dev/null', $out);
    if ($out === []) {
        return 0;
    }
    return (int)trim(explode("\t", $out[0])[0]);
}

/**
 * Plant the synthetic corpus: 4 structurally distinct clone families of
 * 5 members each, plus 4 negative control files.
 *
 * Family members vary identifiers ONLY (class, namespace, and every
 * name carrying the `%s` suffix change per member; literals, structure
 * and the method name stay fixed), so under the default normalization's
 * identifier canonicalization the five members form exactly one type-2
 * clone family — and no two families share a control-flow shape, so
 * nothing may merge across family lines. Negative controls reuse the
 * families' method names and literals but each is structurally unique
 * (its own shape, unlike every family and unlike each other): a tool
 * that latches onto them is incurring a precision penalty the scorer
 * will actually see.
 *
 * Ground truth: one cluster per family, member ranges computed from the
 * rendered text (no magic line numbers), paths corpus-relative so the
 * manifest is machine-independent and safe to commit.
 */
function generateSynthetic(string $dir): void
{
    if (is_dir($dir)) {
        passthru('rm -rf ' . escapeshellarg($dir));
    }
    @mkdir($dir, 0o775, true);

    // slug => method body template; `%s` is the per-member identifier suffix.
    $familyTemplates = [
        'guard' => <<<'PHP'
    public function guard(int $score%s): string
    {
        if ($score%s < 40) {
            return 'low';
        }
        $bonus%s = $score%s * 2 + 7;
        if ($bonus%s > 100) {
            return 'capped';
        }
        return 'ok';
    }
PHP,
        'accumulate' => <<<'PHP'
    public function total(array $rows%s): float
    {
        $sum%s = 0.0;
        $count%s = 0;
        foreach ($rows%s as $row%s) {
            $sum%s += (float) $row%s['amount'];
            $count%s++;
        }
        if ($count%s === 0) {
            return 0.0;
        }
        return $sum%s / $count%s;
    }
PHP,
        'dispatch' => <<<'PHP'
    public function label(int $code%s): string
    {
        switch ($code%s) {
            case 1:
                return 'alpha';
            case 2:
                return 'beta';
            case 3:
                return 'gamma';
            default:
                return 'unknown';
        }
    }
PHP,
        'retry' => <<<'PHP'
    public function attempt(callable $work%s, int $limit%s): mixed
    {
        $tries%s = 0;
        do {
            try {
                return $work%s();
            } catch (RuntimeException $e) {
                $tries%s++;
                if ($tries%s >= $limit%s) {
                    throw $e;
                }
            }
        } while ($tries%s < $limit%s);
        return null;
    }
PHP,
    ];

    // Each negative control reuses family vocabulary (guard/total/label/
    // attempt, the same literals) but matches no family shape and no
    // other negative: planted code with no true clone class.
    $negativeTemplates = [
        <<<'PHP'
    public function guard(int $score): string
    {
        if ($score < 40) {
            return 'low';
        }
        return 'high';
    }
PHP,
        <<<'PHP'
    public function total(array $rows): float
    {
        $sum = 0.0;
        reset($rows);
        while ($row = current($rows)) {
            $sum += (float) $row['amount'];
            next($rows);
        }
        return $sum;
    }
PHP,
        <<<'PHP'
    public function label(int $code): string
    {
        if ($code === 1) {
            return 'alpha';
        } elseif ($code === 2) {
            return 'beta';
        }
        return 'gamma';
    }
PHP,
        <<<'PHP'
    public function attempt(callable $work, int $limit): mixed
    {
        for ($i = 0; $i < $limit; $i++) {
            try {
                return $work();
            } catch (RuntimeException $e) {
                usleep(1000 * $i);
            }
        }
        return null;
    }
PHP,
    ];

    $suffixes = ['', '_a', '_b', '_c', '_d'];
    $clusters = [];
    foreach ($familyTemplates as $slug => $template) {
        $members = [];
        foreach ($suffixes as $i => $suffix) {
            $members[] = plantMember($dir, $slug, $i, str_replace('%s', $suffix, $template));
        }
        $clusters[] = $members;
    }

    $negativeFiles = [];
    foreach ($negativeTemplates as $i => $methodText) {
        plantMember($dir, 'neg', $i, $methodText);
        $negativeFiles[] = sprintf('neg_%03d.php', $i);
    }

    file_put_contents(
        $dir . '/.ground-truth.json',
        json_encode([
            'description' => 'Synthetic bench corpus: 4 structurally distinct clone families x 5 members '
                . '(one ground-truth cluster per family; members vary identifiers only, so each family is '
                . 'a type-2 clone class and families share no shape). negative_files names planted code '
                . 'with no true clone class — detections there are false positives.',
            'clusters' => $clusters,
            'negative_files' => $negativeFiles,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );

    printf(
        "[ok] synthetic-fuzz generated (%d family files + %d negative controls, %d ground-truth clusters)\n",
        count($familyTemplates) * count($suffixes),
        count($negativeFiles),
        count($clusters)
    );
}

/**
 * Write one corpus file and return its ground-truth member entry, with
 * the planted method's range computed from the rendered text.
 *
 * @return array{file:string, start:int, end:int}
 */
function plantMember(string $dir, string $slug, int $index, string $methodText): array
{
    $class = ucfirst($slug) . $index;
    $fileName = sprintf('%s_%03d.php', $slug, $index);
    $text = "<?php\ndeclare(strict_types=1);\n\nnamespace Bench\\{$class};\n\nfinal class {$class}\n{\n"
        . $methodText . "\n}\n";

    $lines = explode("\n", $text);
    $start = 0;
    $end = 0;
    foreach ($lines as $i => $line) {
        if ($start === 0 && str_starts_with($line, '    public function ')) {
            $start = $i + 1;
        }
        if ($line === '    }') {
            $end = $i + 1;
        }
    }
    if ($start === 0 || $end < $start) {
        fwrite(STDERR, "[fail] could not locate planted method range in {$fileName}\n");
        exit(1);
    }

    file_put_contents($dir . '/' . $fileName, $text);
    return ['file' => $fileName, 'start' => $start, 'end' => $end];
}

// Executing this file runs materialization; defining
// PHPDUP_CORPORA_LIB first imports the helpers only (bench/run-all.sh
// pin reader, tests/Fuzz/DetectionRateTest.php planter seam).
if (!defined('PHPDUP_CORPORA_LIB')) {
    exit(main($argv));
}
