#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * phpdup phar builder.
 *
 * Produces a self-contained `phpdup.phar` in the project root that can be
 * dropped anywhere on PATH and run without `composer install` or a vendor/
 * directory. The phar is stamped with its version from bin/phpdup.
 *
 * Usage:
 *   php -d phar.readonly=0 build-phar.php [--compress=gz|bz2] [--out=PATH] [--no-git]
 *
 * Stub boots the autoloader from within the phar, then dispatches to
 * Symfony Console the same way `bin/phpdup` does, so --version / --help
 * etc. all work correctly.
 *
 * --------------------------------------------------------------------------
 * SIZE NOTES
 * --------------------------------------------------------------------------
 * The expected phar size is 5–10 MB. Anything bigger usually means
 * `composer install` was run with dev dependencies (psalm, phpstan, phpunit
 * each weigh tens of megabytes). Always run:
 *
 *   composer install --no-dev --optimize-autoloader --no-interaction
 *
 * before invoking this builder.
 *
 * The builder also strips the following well-known cruft from inside vendor/
 * to keep the phar lean:
 *
 *   - tests/, Tests/, test/, examples/, docs/, doc/
 *   - phpunit.xml*, phpstan*, psalm*, .git*, composer.lock
 *   - *.md (READMEs, CHANGELOGs) — kept as LICENSE only is preserved
 *   - editorconfig / styleci / scrutinizer config noise
 *
 * To enable phar writes, set `phar.readonly=0` in php.ini, or pass
 * `-d phar.readonly=0` on the command line.
 */

const PHAR_NAME = 'phpdup.phar';
const STUB_NAME = 'phpdup-stub.php';
const DIST_INFO = 'dist-info.json';

$opts = getopt('', ['compress::', 'out:', 'no-git']);
$compress = $opts['compress'] ?? false;
$outPath = $opts['out'] ?? __DIR__ . '/phpdup.phar';
$skipGit = isset($opts['no-git']);

// ----------------------------------------------------------------------
// 1. Bootstrap
// ----------------------------------------------------------------------
if (\Phar::running() !== '') {
    fwrite(STDERR, "build-phar: must be run from the host PHP (not from inside a phar)\n");
    exit(1);
}
if (!is_dir(__DIR__ . '/vendor') || !is_file(__DIR__ . '/vendor/autoload.php')) {
    fwrite(STDERR, "build-phar: run `composer install --no-dev --optimize-autoloader` before building\n");
    exit(1);
}
if (ini_get('phar.readonly')) {
    fwrite(STDERR, "build-phar: phar.readonly is on — run `php -d phar.readonly=0 build-phar.php`\n");
    exit(1);
}

// Detect a dev-deps install and warn (vimeo/psalm and phpstan are huge —
// shipping them inside the phar inflates it from ~9 MB to ~85 MB).
$devMarkers = [
    'vendor/vimeo/psalm',
    'vendor/phpstan/phpstan',
    'vendor/phpunit/phpunit',
];
$foundDev = [];
foreach ($devMarkers as $marker) {
    if (is_dir(__DIR__ . '/' . $marker)) {
        $foundDev[] = $marker;
    }
}
if ($foundDev !== []) {
    fwrite(STDERR, "build-phar: WARNING — vendor/ contains dev dependencies:\n");
    foreach ($foundDev as $d) {
        fwrite(STDERR, "  - {$d}\n");
    }
    fwrite(STDERR, "  Re-run `composer install --no-dev --optimize-autoloader` for a slim phar.\n");
    fwrite(STDERR, "  Building anyway with dev deps will produce a phar ~10x larger than expected.\n\n");
}

// ----------------------------------------------------------------------
// 2. Version — read from bin/phpdup
// ----------------------------------------------------------------------
$binContent = (string)file_get_contents(__DIR__ . '/bin/phpdup');
if (preg_match("/new Application\('phpdup',\s*'([^']+)'\)/", $binContent, $m)) {
    $version = $m[1];
} else {
    $version = '0.0.0';
}

// ----------------------------------------------------------------------
// 3. Git commit — capture now before we're inside the phar
// ----------------------------------------------------------------------
$gitCommit = $skipGit ? 'unknown' : trim((string)@exec('git rev-parse --short=8 HEAD 2>/dev/null'));
$gitBranch = $skipGit ? 'unknown' : trim((string)@exec('git rev-parse --abbrev-ref HEAD 2>/dev/null'));
$buildTime = date('c');

if ($gitCommit === '') {
    $gitCommit = 'unknown';
}
if ($gitBranch === '') {
    $gitBranch = 'unknown';
}

// ----------------------------------------------------------------------
// 4. Composer lock / installed packages for attribution
// ----------------------------------------------------------------------
$installedPackages = [];
$lockFile = __DIR__ . '/composer.lock';
if (is_file($lockFile)) {
    $lock = json_decode((string)file_get_contents($lockFile), true);
    if (isset($lock['packages']) && is_array($lock['packages'])) {
        foreach ($lock['packages'] as $pkg) {
            $installedPackages[] = [
                'name'    => $pkg['name'] ?? 'unknown',
                'version' => $pkg['version'] ?? 'unknown',
                'license' => $pkg['license'] ?? [],
            ];
        }
    }
}

// ----------------------------------------------------------------------
// 5. Build the stub
// ----------------------------------------------------------------------
$stub = <<<STUB
#!/usr/bin/env php
<?php
// phpdup phar stub — generated by build-phar.php
declare(strict_types=1);

const PHPDUP_PHAR_VERSION  = '{{VERSION}}';
const PHPDUP_PHAR_BUILT    = '{{BUILT}}';
const PHPDUP_PHAR_COMMIT   = '{{COMMIT}}';
const PHPDUP_PHAR_BRANCH   = '{{BRANCH}}';

\Phar::interceptFileFuncs();

// Phar::running(true)  returns the phar:// stream URL ("phar:///path/foo.phar").
// Phar::running(false) returns just the on-disk filename ("/path/foo.phar").
// We want the on-disk path so we can build a clean autoload URL ourselves.
\$pharFs = \Phar::running(false);
if (\$pharFs === '') {
    fwrite(STDERR, "phpdup: must be run from inside the phar\\n");
    exit(1);
}

\$autoload = 'phar://' . \$pharFs . '/vendor/autoload.php';
if (!is_file(\$autoload)) {
    fwrite(STDERR, "phpdup: autoload not found inside phar (looked for {\$autoload})\\n");
    exit(1);
}

require \$autoload;

use Symfony\\Component\\Console\\Application;
use Phpdup\\Cli\\Command;
use Phpdup\\Cli\\CompletionCommand;
use Phpdup\\Cli\\ServeCommand;
use Phpdup\\Cli\\UpdateCommand;

\$app = new Application('phpdup', PHPDUP_PHAR_VERSION);
\$cmd = new Command();
\$app->add(\$cmd);
\$app->add(new UpdateCommand());
\$app->add(new CompletionCommand());
\$app->add(new ServeCommand());
\$app->setDefaultCommand(\$cmd->getName(), false);
exit(\$app->run());

__HALT_COMPILER();
STUB;

$stub = strtr($stub, [
    '{{VERSION}}' => $version,
    '{{BUILT}}'   => $buildTime,
    '{{COMMIT}}'  => $gitCommit,
    '{{BRANCH}}'  => $gitBranch,
]);

// ----------------------------------------------------------------------
// 6. Create the phar
// ----------------------------------------------------------------------
if (file_exists($outPath)) {
    unlink($outPath);
}
$phar = new \Phar($outPath, 0, basename($outPath));
$phar->setSignatureAlgorithm(\Phar::SHA256);
$phar->startBuffering();

// Counters for the build summary.
$counters = [
    'vendor_added'   => 0,
    'vendor_skipped' => 0,
    'src_added'      => 0,
    'bytes_skipped'  => 0,
];

// 6a. Add vendor/ — filtering out test/docs/CI cruft.
$baseVendor = __DIR__ . '/vendor';
$iterator = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($baseVendor, \FilesystemIterator::SKIP_DOTS),
    \RecursiveIteratorIterator::LEAVES_ONLY,
);

/** @var \SplFileInfo $file */
foreach ($iterator as $file) {
    if ($file->isDir()) {
        continue;
    }
    $localPath = 'vendor/' . $iterator->getSubPathName();
    if (shouldSkipVendorPath($localPath)) {
        $counters['vendor_skipped']++;
        $counters['bytes_skipped'] += $file->getSize();
        continue;
    }
    $phar->addFile($file->getPathname(), $localPath);
    $counters['vendor_added']++;
}

// 6b. Add src/ (everything PHP).
$srcDir = __DIR__ . '/src';
$iterator = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
    \RecursiveIteratorIterator::LEAVES_ONLY,
);
foreach ($iterator as $file) {
    if ($file->isDir() || !str_ends_with($file->getFilename(), '.php')) {
        continue;
    }
    $localPath = 'src/' . $iterator->getSubPathName();
    $phar->addFile($file->getPathname(), $localPath);
    $counters['src_added']++;
}

// 6c. Add bin/phpdup for reference (the phar uses its own stub, but
// shipping the original entrypoint helps reproducibility).
$phar->addFile(__DIR__ . '/bin/phpdup', 'bin/phpdup');

// 6d. Embed the stub script as an in-phar file. createDefaultStub()
// generates a launcher that includes this file via phar:// — so it must
// be inside the phar, not on disk.
$phar->addFromString(STUB_NAME, $stub);

// 6e. Embed dist-info metadata.
$distInfo = json_encode([
    'version'   => $version,
    'built'     => $buildTime,
    'commit'    => $gitCommit,
    'branch'    => $gitBranch,
    'packages'  => $installedPackages,
], JSON_PRETTY_PRINT);
$phar->addFromString(DIST_INFO, (string)$distInfo);

// 6f. Set the launcher stub.
$phar->setStub($phar->createDefaultStub(STUB_NAME));
$phar->setMetadata([
    'version' => $version,
    'built'   => $buildTime,
    'commit'  => $gitCommit,
    'branch'  => $gitBranch,
]);
$phar->stopBuffering();

// ----------------------------------------------------------------------
// 7. Optional compression
// ----------------------------------------------------------------------
if ($compress) {
    $phar->compress($compress === 'bz2' ? \Phar::BZ2 : \Phar::GZ);
    $outPath .= '.' . $compress;
}

@chmod($outPath, 0755);

$size = filesize($outPath);
fwrite(STDOUT, sprintf(
    "build-phar: %s\n  size:    %s bytes (%.2f MB)\n  version: %s\n  commit:  %s\n  vendor:  %d files added, %d skipped (%s bytes saved)\n  src:     %d files added\n",
    $outPath,
    number_format((int)$size),
    $size / 1024 / 1024,
    $version,
    $gitCommit,
    $counters['vendor_added'],
    $counters['vendor_skipped'],
    number_format($counters['bytes_skipped']),
    $counters['src_added'],
));

if ($size > 10 * 1024 * 1024) {
    fwrite(STDERR, "\nbuild-phar: WARNING — phar exceeds 10 MB.\n");
    fwrite(STDERR, "  Likely cause: `composer install` was run with dev dependencies.\n");
    fwrite(STDERR, "  Fix:          composer install --no-dev --optimize-autoloader && rerun this script.\n");
}

exit(0);

// ======================================================================
// Vendor cruft filter
// ======================================================================

/**
 * Decide whether a `vendor/...` path is build-time / dev-only cruft that
 * does not belong in the runtime phar.
 *
 * Conservative: only skip well-known noise. When in doubt, keep the file.
 */
function shouldSkipVendorPath(string $localPath): bool
{
    // Path components for fast membership checks.
    $parts = explode('/', $localPath);
    $basename = $parts[array_key_last($parts)] ?? '';
    $lcBase = strtolower($basename);

    // Drop entire test / example / docs trees from any package.
    foreach ($parts as $segment) {
        $lc = strtolower($segment);
        if (in_array($lc, ['tests', 'test', 'examples', 'example', 'docs', 'doc'], true)) {
            return true;
        }
    }

    // Drop CI / static-analysis configuration files at any level.
    static $skipBasenames = [
        'phpunit.xml', 'phpunit.xml.dist',
        'phpstan.neon', 'phpstan.neon.dist', 'phpstan-baseline.neon',
        'psalm.xml', 'psalm.xml.dist', 'psalm-baseline.xml',
        'phpcs.xml', 'phpcs.xml.dist', '.php-cs-fixer.php', '.php-cs-fixer.dist.php',
        '.scrutinizer.yml', '.styleci.yml', '.travis.yml', '.editorconfig',
        '.gitignore', '.gitattributes', '.gitmodules',
        'composer.lock', 'package.json', 'package-lock.json',
        'codecov.yml', '.codecov.yml', 'sonar-project.properties',
    ];
    if (in_array($lcBase, $skipBasenames, true)) {
        return true;
    }

    // Drop Markdown documentation (README, CHANGELOG, UPGRADE, etc.)
    // but keep LICENSE / NOTICE / COPYING.
    if (str_ends_with($lcBase, '.md') || str_ends_with($lcBase, '.rst')) {
        return true;
    }

    // Drop hidden GitHub workflow / CI dirs (`.github/`, `.gitlab/`).
    foreach ($parts as $segment) {
        if ($segment === '.github' || $segment === '.gitlab') {
            return true;
        }
    }

    return false;
}
