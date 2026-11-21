<?php
/**
 * Corpus discovery & download for the bench harness.
 *
 * Fetches a curated set of public PHP repos (shallow clone) into
 * `bench/corpora/`. Each entry caps wall time individually and the
 * whole script obeys an overall 5-minute budget so it never hangs
 * CI. Already-downloaded corpora are skipped.
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
 * files in `.ground-truth.json` for `bench/score.php`.
 *
 * Usage:
 *   php bench/corpora.php                   # download missing, regenerate synthetic
 *   php bench/corpora.php --synthetic-only  # regenerate synthetic only (no network)
 *   php bench/corpora.php --list            # print the planned corpora and exit
 *   php bench/corpora.php --refresh         # force re-clone everything
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$corporaDir = dirname(__DIR__) . '/bench/corpora';
@mkdir($corporaDir, 0o775, true);

/** @var list<array{name:string, url:string, ref:string, sparse:?string, max_mb:int}> $plan */
$plan = [
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

if (in_array('--list', $argv, true)) {
    foreach ($plan as $p) {
        printf("%-40s %s @ %s%s\n", $p['name'], $p['url'], $p['ref'], $p['sparse'] ? " (sparse: {$p['sparse']})" : '');
    }
    echo "synthetic-fuzz                           (generated locally)\n";
    exit(0);
}

$forceRefresh = in_array('--refresh', $argv, true);
$globalBudget = 300; // seconds
$globalStart  = microtime(true);

if (in_array('--synthetic-only', $argv, true)) {
    echo "[skip] --synthetic-only: OSS clones untouched\n";
} else {
    foreach ($plan as $p) {
        $target = $corporaDir . '/' . $p['name'];
        if (!$forceRefresh && is_dir($target) && countPhpFiles($target) > 0) {
            echo "[skip] {$p['name']} already present\n";
            continue;
        }
        if ((microtime(true) - $globalStart) > $globalBudget) {
            echo "[budget] global download budget exhausted; stopping\n";
            break;
        }
        if ($forceRefresh && is_dir($target)) {
            passthru('rm -rf ' . escapeshellarg($target));
        }
        cloneRepo($p, $target, $globalBudget - (int)(microtime(true) - $globalStart));
    }
}

generateSynthetic($corporaDir . '/synthetic-fuzz');

echo "[ok] corpora ready under {$corporaDir}\n";

/**
 * @param array{name:string, url:string, ref:string, sparse:?string, max_mb:int} $p
 */
function cloneRepo(array $p, string $target, int $budget): void
{
    $budget = max(10, $budget);
    echo "[clone] {$p['name']} ← {$p['url']} @ {$p['ref']} (≤{$budget}s)\n";
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
        echo "[fail] {$p['name']} clone exit {$rc} — skipping\n";
        @passthru('rm -rf ' . escapeshellarg($tmp));
        return;
    }
    if ($p['sparse'] !== null) {
        $src = $tmp . '/' . $p['sparse'];
        if (!is_dir($src)) {
            echo "[warn] sparse path {$p['sparse']} missing in {$p['name']}; using whole repo\n";
            $src = $tmp;
        }
        @mkdir($target, 0o775, true);
        passthru('cp -r ' . escapeshellarg($src) . '/. ' . escapeshellarg($target));
        passthru('rm -rf ' . escapeshellarg($tmp));
    } else {
        rename($tmp, $target);
    }
    // Strip git metadata to keep the corpus directory diff-clean.
    passthru('rm -rf ' . escapeshellarg($target . '/.git'));
    $sizeMb = (int)round(diskUsageBytes($target) / (1024 * 1024));
    echo "[ok] {$p['name']} ({$sizeMb} MB, " . countPhpFiles($target) . " PHP files)\n";
    if ($sizeMb > $p['max_mb']) {
        echo "[warn] {$p['name']} exceeded soft cap {$p['max_mb']} MB\n";
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
