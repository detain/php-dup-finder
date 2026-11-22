<?php
declare(strict_types=1);

namespace Phpdup\Cli;

use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Phpdup\Util\Http;
use Phpdup\Util\Version;
use Phar;

/**
 * Self-update / upgrade command for the phpdup phar.
 *
 * Integrity model (once-S05, php-dup-finder-once-005 — fail closed):
 *
 *   1. Fetch the GitHub Releases JSON API for the latest tag. The HTTP
 *      status is part of the reply (once-S91): only a 2xx response body
 *      is ever decoded as a release payload — a 401/403/404/5xx error
 *      reply is surfaced as the transport failure it is, with any
 *      Retry-After guidance reported.
 *   2. Download `phpdup.phar`, then the signed release manifest
 *      (`phpdup.release.json`) and its HMAC-SHA256 signature
 *      (`phpdup.release.json.sig`). A download counts as complete only
 *      when the byte count agrees with the declared Content-Length, and
 *      a partial-content (206/Content-Range) reply is refused outright
 *      (once-S91, php-dup-finder-shell-017).
 *   3. Verify the HMAC over the MANIFEST bytes against the pinned
 *      verification key (raw-string form — identical on both sides).
 *   4. Validate the manifest: schema 1, artifact name, tag binding
 *      against the API response, and a well-formed sha256.
 *   5. The downloaded artifact must hash to the sha256 pinned by the
 *      verified manifest. The plain `phpdup.phar.sha256` asset is NOT
 *      consulted — a checksum fetched from the same response as the
 *      artifact proves nothing about authenticity. It ships in GNU
 *      coreutils checksum format (`<hash>  phpdup.phar`, two spaces)
 *      so manual installs can verify it with `sha256sum --check`
 *      verbatim (once-S12); this command still ignores it.
 *   6. Replace the running phar atomically (temp rename), keeping the
 *      previous phar as a backup.
 *   7. Run the post-install check over the file at its FINAL path and
 *      only then discard the backup (once-S91). A failed check restores
 *      the previous phar automatically — rollback is honest because it
 *      is sequenced: the good binary outlives the check, not the other
 *      way around.
 *
 * A placeholder/unset verification key REFUSES verification with an
 * explicit error on every non-dry path. Unsigned installs are possible
 * only through `--dev-allow-unsigned`, which prints a loud banner on
 * both the output and stderr, and is meant for development rigs only.
 *
 * An offline mode (`--verify-artifact` + `--verify-manifest` +
 * `--verify-signature`) runs the same code path against local files —
 * this is what the release workflow's sign→verify round-trip gate
 * exercises before anything is published.
 *
 * Aliases registered in bin/phpdup:
 *   - `self:update`  → UpdateCommand (primary)
 *   - `upgrade`      → UpdateCommand (alias)
 *   - `self-update`  → UpdateCommand (alias)
 */
class UpdateCommand extends SymfonyCommand
{
    private const GITHUB_RELEASES_API = 'https://api.github.com/repos/detain/php-dup-finder/releases/latest';

    private const PHAR_ASSET_NAME = 'phpdup.phar';

    /** Tag-bound JSON manifest; the signature covers exactly these bytes. */
    private const MANIFEST_ASSET_NAME = 'phpdup.release.json';

    /** HMAC-SHA256 (hex) over the manifest file bytes. */
    private const MANIFEST_SIG_ASSET_NAME = 'phpdup.release.json.sig';

    /** Manifest schema this client accepts. */
    private const MANIFEST_SCHEMA = 1;

    /**
     * Build placeholder that ships in the tree. Verification REFUSES while
     * the resolved key still equals it (fail closed — php-dup-finder-once-005).
     *
     * Public so the once-S05 known-plaintext fixture (UpdateCommandTest) can
     * sign a manifest under the EXACT string the primitive must refuse —
     * a copied literal in the test could drift and re-vacuate that pin.
     */
    public const VERIFICATION_KEY_PLACEHOLDER = 'REPLACE_WITH_REAL_PUBLIC_KEY';

    /**
     * HMAC-SHA256 verification key, in its RAW string form.
     *
     * This is deliberately NOT a public key: HMAC is symmetric, so the
     * "verification key" is the release signing secret itself. The release
     * workflow HMACs with the raw `HMAC_SECRET_KEY` string — no base64
     * transform on either side ever again (the old workflow signed with
     * base64_decode(secret) while this side used the raw constant, so the
     * two could never agree).
     *
     * The placeholder must be replaced with the actual key string before
     * the first signed release (the CI round-trip gate in
     * .github/workflows/release.yml proves sign→verify agreement with the
     * real secret before the `publish` job may attach any assets).
     * Asymmetric minisign/ed25519 migration is an OPEN owner action — see
     * docs/RELEASE.md "Security notes".
     *
     * Key rotation: update this constant and tag a new release.
     *
     * @see docs/RELEASE.md
     */
    private const VERIFICATION_KEY = self::VERIFICATION_KEY_PLACEHOLDER;

    /** Dev/CI override for the verification key (raw string, never a path). */
    private const VERIFICATION_KEY_ENV = 'PHPDUP_UPDATE_VERIFICATION_KEY';

    #[\Override]
    protected function configure(): void
    {
        $this->setName('self:update')
            // Aliases — the three conventional self-update names.
            ->setAliases(['upgrade', 'self-update'])
            ->setDescription('Replace this phpdup phar with the latest release from GitHub')
            ->setHelp(
                <<<'HELP'
Downloads the latest tagged release from
<fg=cyan>https://github.com/detain/php-dup-finder</> and replaces the
running phar with it.

Steps performed:
  1. Fetch the GitHub Releases JSON API for the latest tag. The HTTP
     status is checked: a 401/403/404/5xx reply is reported as itself
     (with any Retry-After guidance) and never parsed as release data.
  2. Download <fg=yellow>phpdup.phar</>, the signed release manifest
     <fg=yellow>phpdup.release.json</> and its HMAC-SHA256 signature
     <fg=yellow>phpdup.release.json.sig</>. A download is complete only
     when the bytes received agree with the declared Content-Length; a
     partial-content reply is refused on sight.
  3. Verify the HMAC over the manifest bytes against the pinned
     verification key, then pin the artifact's SHA-256 to the value the
     verified manifest declares. The plain <fg=yellow>phpdup.phar.sha256</>
     asset is never trusted for verification (same-response checksums
     prove nothing). It ships in <fg=yellow>sha256sum --check</> format
     ("<hash>  phpdup.phar", two spaces) for manual spot-checks only.
  4. Replace the running phar atomically (temp rename), keeping the
     previous phar as a backup.
  5. Check the installed file at its final path (readable, non-empty,
     and on a verified install still hashing to the manifest pin) —
     only then is the backup removed. If the check fails, the previous
     phar is restored automatically; if that restore fails too, manual
     recovery steps are printed.

Private repositories: the <fg=yellow>GITHUB_TOKEN</> environment
variable, when set, is sent on the release-API request only. Release
asset downloads carry no authentication of any kind, so self:update
does NOT support private releases — download through an authenticated
client instead (see docs/RELEASE.md).

A placeholder/unset verification key refuses to verify (exit 1) instead
of passing. There is no flag that turns that "pass" back on: the only
unchecked path is <fg=yellow>--dev-allow-unsigned</>, which is confined to
development rigs and prints a loud warning on stderr.

Offline verification of already-downloaded files (the release workflow
round-trip gate drives this exact code path):
  phpdup self:update --verify-artifact=phpdup.phar \
      --verify-manifest=phpdup.release.json --verify-signature=phpdup.release.json.sig
Exit 0 on a verified pair, exit 1 on tamper or placeholder key.

Requires: <fg=yellow>ext-hash</>, and network access.
Does NOT require composer or vendor/ — works with the standalone phar.

<fg=yellow>NOTE:</> Installation only works when phpdup is running as a phar.
When invoked from a git clone (bin/phpdup) it will print a helpful message
pointing to the upgrade path for source-based installs — except in the
offline --verify-* mode above, which runs fine from a source tree.
HELP
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Fetch release info and print what would be downloaded, but do not replace anything',
            )
            ->addOption(
                'pre',
                null,
                InputOption::VALUE_NONE,
                'Also consider pre-release (alpha/beta/RC) versions',
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Write the downloaded phar to this path instead of replacing the running one',
            )
            ->addOption(
                'dev-allow-unsigned',
                null,
                InputOption::VALUE_NONE,
                'DEV ONLY — install without integrity verification (loud stderr banner; never for production)',
            )
            // Offline-verification trio (once-S05 CI round-trip gate):
            // local files in, exit 0/1 out, no network and no phar required.
            // (Kept visible: the installed symfony/console predates the 7.1
            // hidden-option support, and these flags are user-facing audit
            // tools per docs/RELEASE.md anyway.)
            ->addOption(
                'verify-artifact',
                null,
                InputOption::VALUE_REQUIRED,
                'Offline verify: path to the artifact (phar) to check',
            )
            ->addOption(
                'verify-manifest',
                null,
                InputOption::VALUE_REQUIRED,
                'Offline verify: path to the release manifest JSON',
            )
            ->addOption(
                'verify-signature',
                null,
                InputOption::VALUE_REQUIRED,
                'Offline verify: path to the manifest signature file',
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // -----------------------------------------------------------------
        // 0. Offline verify mode (release-workflow round-trip gate): runs
        //    the same manifest-signature + artifact-hash code path against
        //    local files, before the phar guard, without any network.
        // -----------------------------------------------------------------
        if (
            $input->getOption('verify-artifact') !== null
            || $input->getOption('verify-manifest') !== null
            || $input->getOption('verify-signature') !== null
        ) {
            return $this->executeOfflineVerify($input, $io);
        }

        // -----------------------------------------------------------------
        // Guard: must be running from inside a phar
        // -----------------------------------------------------------------
        $pharPath = $this->runningPharPath();
        if ($pharPath === '') {
            $io->note(
                'phpdup was not invoked from a phar. ' .
                'To upgrade a source-based install, run: <fg=yellow>git pull && composer update</>',
            );
            $io->text(
                'To build a fresh phar from source: <fg=yellow>php -d phar.readonly=0 build-phar.php</>',
            );
            return 0;
        }

        $dryRun   = (bool)$input->getOption('dry-run');
        $pre      = (bool)$input->getOption('pre');
        $outPath  = $input->getOption('output');

        $io->title('phpdup self-update');

        // -----------------------------------------------------------------
        // 1. Fetch latest release metadata
        // -----------------------------------------------------------------
        $io->section('Fetching release metadata…');
        $token = $this->githubToken();
        $headers = [
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent'           => 'phpdup/' . $this->runningVersion(),
        ];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        // once-S91 (php-dup-finder-once-114): the transport reports the HTTP
        // status next to the body, and only a TRUSTED reply — an HTTP 2xx,
        // or a seam transport with no HTTP layer at all — is ever handed to
        // the release logic as a payload. The status-blind leg used to feed
        // an api.github.com error body (rate limit, 404, 5xx) straight into
        // the release shape, so a rate-limited caller saw the misleading
        // "Asset phpdup.phar not found in release unknown" instead of the
        // honest refusal the live once-S12 r1 probe recorded.
        $response = $this->fetchJson($this->releasesApiUrl(), $headers);
        $release  = $response['json'];

        if ($release === null) {
            $status = $response['status'];
            if ($status === null) {
                return $this->failed($io, 'Could not fetch release info from GitHub: the API request did not complete (no HTTP reply from api.github.com).');
            }
            if ($status === 404) {
                return $this->noReleaseYet($io, $dryRun);
            }
            if ($status >= 200 && $status < 300) {
                return $this->failed($io, "GitHub release API replied HTTP {$status} without a usable JSON release body." . $this->retryHint($response['retryAfter']));
            }
            return $this->failed($io, $this->apiStatusMessage($status, $response['retryAfter']));
        }

        // A 2xx reply that nevertheless carries GitHub's "Not Found" error
        // shape ({"message":"Not Found","status":"404"}) — a proxy or mirror
        // rewriting the status — stays refused: the body itself says there
        // is no release, the same check once-S05 shipped, now as the second
        // line of defence behind the honest status.
        if (
            !isset($release['tag_name'])
            && (
                ($release['status'] ?? '') === '404'
                || ($release['message'] ?? '') === 'Not Found'
            )
        ) {
            return $this->noReleaseYet($io, $dryRun);
        }

        $tagName   = $release['tag_name']    ?? 'unknown';
        $prerelease = (bool)($release['prerelease'] ?? false);
        $draft      = (bool)($release['draft']      ?? false);

        if ($prerelease && !$pre) {
            $io->text([
                "  Latest tag:  <fg=yellow>{$tagName}</>  (pre-release, skipped)",
                '  To install anyway: <fg=yellow>phpdup self:update --pre</>',
            ]);
            return 0;
        }
        if ($draft) {
            return $this->failed($io, "Latest release is a draft and cannot be installed.");
        }

        $io->text([
            "  Latest tag:  <fg=green>{$tagName}</>",
            '  Published:  ' . ($release['published_at'] ?? 'unknown'),
        ]);

        // -----------------------------------------------------------------
        // 2. Resolve asset URLs
        // -----------------------------------------------------------------
        $pharUrl      = null;
        $manifestUrl  = null;
        $signatureUrl = null;
        foreach (($release['assets'] ?? []) as $asset) {
            if (($asset['name'] ?? '') === self::PHAR_ASSET_NAME) {
                $pharUrl = $asset['browser_download_url'] ?? null;
            }
            if (($asset['name'] ?? '') === self::MANIFEST_ASSET_NAME) {
                $manifestUrl = $asset['browser_download_url'] ?? null;
            }
            if (($asset['name'] ?? '') === self::MANIFEST_SIG_ASSET_NAME) {
                $signatureUrl = $asset['browser_download_url'] ?? null;
            }
        }

        if ($pharUrl === null) {
            return $this->failed(
                $io,
                "Asset <fg=yellow>" . self::PHAR_ASSET_NAME . "</> not found in release {$tagName}. " .
                'Release assets are attached by the release workflow ' .
                '(.github/workflows/release.yml, triggered on a v* tag push) — see docs/RELEASE.md. ' .
                'A tag published outside that workflow carries no assets.',
            );
        }

        if ($dryRun) {
            $io->section('Dry-run — release assets');
            $io->text([
                '  phar:     present',
                '  manifest: ' . ($manifestUrl !== null ? 'present' : 'MISSING — a live install would be refused'),
                '  sig:      ' . ($signatureUrl !== null ? 'present' : 'MISSING — a live install would be refused'),
                '  key:      ' . ($this->verificationKeyIsPlaceholder()
                    ? 'PLACEHOLDER — a live install would be refused'
                    : 'configured (raw-string form)'),
            ]);
            $io->success('Dry-run complete — no files were written.');
            return 0;
        }

        $devAllowUnsigned = (bool)$input->getOption('dev-allow-unsigned');

        // -----------------------------------------------------------------
        // 2b. Fail closed BEFORE transferring anything: a verified install
        //     needs the signed manifest pair and a real (non-placeholder)
        //     key. --dev-allow-unsigned is the only way past either check,
        //     and it says so loudly.
        // -----------------------------------------------------------------
        if (!$devAllowUnsigned) {
            if ($manifestUrl === null || $signatureUrl === null) {
                return $this->failed(
                    $io,
                    'Release carries no signed manifest (' . self::MANIFEST_ASSET_NAME . ' + '
                    . self::MANIFEST_SIG_ASSET_NAME . '). Refusing to install unverifiable bytes. '
                    . 'For a development-only unverified install: --dev-allow-unsigned.',
                );
            }
            if ($this->verificationKeyIsPlaceholder()) {
                return $this->failed($io, $this->placeholderKeyMessage());
            }
        }

        // -----------------------------------------------------------------
        // 3. Download phar to a temp file
        // -----------------------------------------------------------------
        $io->section("Downloading {$tagName}…");
        $tmpFile = $outPath ?? ($pharPath . '.tmp.' . bin2hex(random_bytes(8)));

        $ok = $this->downloadFile($pharUrl, $tmpFile, $io);
        if (!$ok) {
            @unlink($tmpFile);
            return $this->failed($io, 'Download of phar failed.');
        }

        // -----------------------------------------------------------------
        // 4. Integrity verification: HMAC over the signed manifest, then
        //    the artifact hash pinned by that manifest (once-S05).
        // -----------------------------------------------------------------
        // The pin the post-install check (step 5, once-S91) re-verifies at
        // the FINAL path. It stays null on the --dev-allow-unsigned leg,
        // where there is no signed pin to check — the weaker smoke there is
        // disclosed in the help text and docs/RELEASE.md.
        $pinnedSha256 = null;

        if (!$devAllowUnsigned) {
            $io->section('Verifying signed release manifest…');

            $tmpManifest = $tmpFile . '.manifest.json';
            if (!$this->downloadFile($manifestUrl, $tmpManifest, $io)) {
                @unlink($tmpManifest);
                @unlink($tmpFile);
                return $this->failed($io, 'Download of release manifest failed.');
            }

            $tmpSig = $tmpFile . '.manifest.sig';
            $sigOk = $this->downloadFile($signatureUrl, $tmpSig, $io);
            if (!$sigOk) {
                @unlink($tmpSig);
                @unlink($tmpManifest);
                @unlink($tmpFile);
                return $this->failed($io, 'Download of signature file failed.');
            }
            $expectedSig = trim((string)@file_get_contents($tmpSig));
            @unlink($tmpSig);

            if ($expectedSig === '' || !preg_match('/^[0-9a-f]{64}$/', $expectedSig)) {
                @unlink($tmpManifest);
                @unlink($tmpFile);
                return $this->failed($io, 'Signature file is malformed (expected 64-hex HMAC-SHA256).');
            }

            if (!$this->verifySignature($tmpManifest, $expectedSig)) {
                @unlink($tmpManifest);
                @unlink($tmpFile);
                return $this->failed(
                    $io,
                    'HMAC-SHA256 signature over the release manifest does not verify '
                    . 'against the pinned key. Refusing to install.',
                );
            }
            $io->text('  Manifest HMAC-SHA256: <fg=green>verified</>  ✓');

            $manifestBytes = @file_get_contents($tmpManifest);
            @unlink($tmpManifest);
            $decoded = is_string($manifestBytes) ? json_decode($manifestBytes, true) : null;
            $expectedSha256 = $this->validateManifest(is_array($decoded) ? $decoded : null, (string)$tagName, $io);
            if ($expectedSha256 === null) {
                @unlink($tmpFile);
                return 1;
            }

            $actualSha256 = hash_file('sha256', $tmpFile);
            if ($actualSha256 === false || !hash_equals($expectedSha256, $actualSha256)) {
                @unlink($tmpFile);
                return $this->failed($io, sprintf(
                    "SHA-256 does not match the signed manifest.\n  Manifest pins: %s\n  Downloaded:    %s",
                    $expectedSha256,
                    $actualSha256 === false ? 'unreadable' : $actualSha256,
                ));
            }
            $io->text("  SHA-256: <fg=green>{$actualSha256}</>  ✓  (pinned to the signed manifest)");
            $pinnedSha256 = $expectedSha256;
        } else {
            $this->devUnsignedBanner($io, $output);
            $actualSha256 = hash_file('sha256', $tmpFile);
            $io->text('  SHA-256 (informational, UNVERIFIED): '
                . ($actualSha256 === false ? 'unreadable' : $actualSha256));
        }

        // -----------------------------------------------------------------
        // 5. Replace running phar
        // -----------------------------------------------------------------
        $io->section('Installing…');

        if ($outPath !== null) {
            if (!rename($tmpFile, $outPath)) {
                return $this->failed($io, "Could not move downloaded phar to: {$outPath}");
            }
            chmod($outPath, 0755 & ~umask());
            $io->success("phpdup {$tagName} written to: {$outPath}");
            $io->text("  Run: <fg=yellow>{$outPath} --version</>  to verify.");
            return 0;
        }

        $backupPath = $pharPath . '.backup.' . bin2hex(random_bytes(4));
        if (!rename($pharPath, $backupPath)) {
            @unlink($tmpFile);
            return $this->failed($io, "Could not rename old phar to: {$backupPath}");
        }

        if (!rename($tmpFile, $pharPath)) {
            $restored = rename($backupPath, $pharPath);
            @unlink($tmpFile);
            $io->error([
                'Failed to install the new phar — old phar has been restored.',
                'Recovery: check permissions / disk space.',
            ]);
            if ($restored) {
                $io->text("  (restored {$pharPath})");
            } else {
                $io->error([
                    "Could not restore old phar either.",
                    "  Manual recovery: move {$backupPath} → {$pharPath}",
                ]);
            }
            return 1;
        }

        chmod($pharPath, 0755 & ~umask());

        // once-S91 (php-dup-finder-once-114): the previous phar is STILL on
        // disk at $backupPath and stays there until the freshly installed
        // one passes a post-install check at its final path. The old order
        // deleted the backup the instant the rename succeeded — whatever the
        // result — so the "old phar has been restored" promise held only for
        // the rename failure above, never for a bad-but-installed artifact
        // (the finding's inverted-rollback complaint). Now the sequence makes
        // the promise true: check first, then discard or restore.
        $smokeFailure = $this->smokeInstalledPhar($pharPath, $pinnedSha256);
        if ($smokeFailure !== null) {
            @unlink($pharPath);
            $restored = rename($backupPath, $pharPath);
            @unlink($tmpFile);
            $io->error([
                "The installed phar failed its post-install check ({$smokeFailure}).",
                $restored
                    ? '  The previous phar was restored from the backup — nothing was lost.'
                    : '  Automatic restore FAILED.',
            ]);
            if (!$restored) {
                $io->error([
                    "Could not restore old phar either.",
                    "  Manual recovery: move {$backupPath} → {$pharPath}",
                ]);
            }
            return 1;
        }

        @unlink($backupPath);

        $io->success([
            "phpdup updated to {$tagName}",
            "  New version:  <fg=yellow>{$tagName}</>",
            '  Run: <fg=yellow>phpdup --version</>  to verify.',
        ]);
        $io->text('  Post-install check passed — the previous-phar backup was removed.');

        return 0;
    }

    // ------------------------------------------------------------------
    // Offline verification (once-S05 round-trip gate code path)
    // ------------------------------------------------------------------

    /**
     * Verify a local artifact against a local manifest + signature using
     * the same primitives as the install flow. No network, no phar.
     * Exit 0 = verified, 1 = refusal (tamper, malformed input, or the
     * placeholder key).
     */
    private function executeOfflineVerify(InputInterface $input, SymfonyStyle $io): int
    {
        $artifactPath  = $input->getOption('verify-artifact');
        $manifestPath  = $input->getOption('verify-manifest');
        $signaturePath = $input->getOption('verify-signature');

        if (!is_string($artifactPath) || !is_string($manifestPath) || !is_string($signaturePath)) {
            return $this->failed(
                $io,
                'Offline verification requires --verify-artifact, --verify-manifest and '
                . '--verify-signature together.',
            );
        }
        foreach (['artifact' => $artifactPath, 'manifest' => $manifestPath, 'signature' => $signaturePath] as $label => $path) {
            if (!is_file($path) || !is_readable($path)) {
                return $this->failed($io, "Offline verify: {$label} file is not readable: {$path}");
            }
        }

        $io->title('phpdup offline artifact verification');
        $io->text([
            "  Artifact:   {$artifactPath}",
            "  Manifest:   {$manifestPath}",
            "  Signature:  {$signaturePath}",
        ]);

        if ($this->verificationKeyIsPlaceholder()) {
            return $this->failed($io, $this->placeholderKeyMessage());
        }

        $expectedSig = trim((string)@file_get_contents($signaturePath));
        if ($expectedSig === '' || !preg_match('/^[0-9a-f]{64}$/', $expectedSig)) {
            return $this->failed($io, 'Signature file is malformed (expected 64-hex HMAC-SHA256).');
        }

        if (!$this->verifySignature($manifestPath, $expectedSig)) {
            return $this->failed(
                $io,
                'HMAC-SHA256 signature over the release manifest does not verify '
                . 'against the pinned key.',
            );
        }
        $io->text('  Manifest HMAC-SHA256: <fg=green>verified</>  ✓');

        $manifestBytes = @file_get_contents($manifestPath);
        $decoded = is_string($manifestBytes) ? json_decode($manifestBytes, true) : null;
        $expectedSha256 = $this->validateManifest(is_array($decoded) ? $decoded : null, null, $io);
        if ($expectedSha256 === null) {
            return 1;
        }

        $actualSha256 = hash_file('sha256', $artifactPath);
        if ($actualSha256 === false || !hash_equals($expectedSha256, $actualSha256)) {
            return $this->failed($io, sprintf(
                "SHA-256 does not match the signed manifest.\n  Manifest pins: %s\n  Artifact:      %s",
                $expectedSha256,
                $actualSha256 === false ? 'unreadable' : $actualSha256,
            ));
        }
        $io->text("  SHA-256: <fg=green>{$actualSha256}</>  ✓  (matches the signed manifest)");

        $io->success('Offline verification passed — artifact matches the signed manifest.');
        return 0;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Endpoint the release metadata is read from. Seam for tests (once-S91):
     * the status-honesty pins point it at a forked loopback HTTP server and
     * drive the REAL transport — no network group, no substituted fetch.
     */
    protected function releasesApiUrl(): string
    {
        return self::GITHUB_RELEASES_API;
    }

    /**
     * Fetch and decode a JSON URL, honest about the HTTP status layer
     * (once-S91, php-dup-finder-once-114).
     *
     * The old leg ran with ignore_errors and handed back whatever decoded,
     * so api.github.com's error replies — which ARE JSON bodies — reached the
     * release logic as plausible payloads: a rate-limited caller watched a
     * 403 error object flow into asset resolution and surface as
     * "Asset phpdup.phar not found in release unknown" (recorded live by the
     * once-S12 r1 probe). A transport must never launder a failure into a
     * plausible success (once-S46 doctrine). The contract now:
     *
     *   - `json` is non-null ONLY for a trusted reply: an HTTP 2xx, or a
     *     seam transport with no HTTP layer at all (file://; `status` stays
     *     null there);
     *   - `status` is the reply's HTTP status (0 when an http(s) reply
     *     carried no parsable status line — refused), null when no HTTP
     *     reply arrived at all (connect/timeout/TLS) or the scheme has none;
     *   - `retryAfter` carries the Retry-After guidance in whole seconds
     *     (delta-seconds or HTTP-date form); honored by REPORTING it —
     *     self:update is a one-shot command and never re-dials on its own;
     *   - the request never follows redirects: an `Authorization` header
     *     (GITHUB_TOKEN) must not be replayed onto a host the caller did not
     *     choose, so a 3xx surfaces as a refusal instead (once-S91).
     *
     * @param array<string, string> $headers
     * @return array{status: int|null, json: array<string, mixed>|null, retryAfter: int|null}
     */
    protected function fetchJson(string $url, array $headers): array
    {
        $refused = ['status' => null, 'json' => null, 'retryAfter' => null];

        $context = stream_context_create([
            'http' => [
                'method'           => 'GET',
                'header'           => implode("\r\n", array_map(
                    static fn($k, $v) => "{$k}: {$v}",
                    array_keys($headers),
                    array_values($headers),
                )),
                'timeout'          => 30,
                'ignore_errors'    => true,
                'follow_location'  => 0,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return $refused; // no reply at all — there is nothing to be honest about
        }

        // CI-85 dual read, same posture as downloadFile(): the magic
        // variable belongs to the scope that performed the wrapper call, so
        // it is read HERE — and only when the wrapper could have set it.
        $isHttp      = preg_match('#^https?://#i', $url) === 1;
        $respHeaders = $isHttp
            ? (function_exists('http_get_last_response_headers')
                ? (array) http_get_last_response_headers()
                : $http_response_header)
            : [];
        $status = $isHttp ? Http::parseStatusCode($respHeaders) : null;

        $decoded = json_decode($body, true);
        $trusted = $status === null || ($status >= 200 && $status < 300);

        return [
            'status'     => $status,
            // A non-2xx (or a reply with no parsable status line) never
            // yields a body the caller may treat as data.
            'json'       => $trusted && is_array($decoded) ? $decoded : null,
            'retryAfter' => self::parseRetryAfter($respHeaders),
        ];
    }

    /**
     * Retry-After in whole seconds, either form of the header. Unparseable
     * guidance is no guidance: null, never a fabricated number.
     *
     * @param array<array-key, mixed> $headers
     */
    private static function parseRetryAfter(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (!is_string($header) || preg_match('/^Retry-After:\s*(.+)$/i', trim($header), $m) !== 1) {
                continue;
            }
            $value = trim($m[1]);
            if (preg_match('/^\d+$/', $value) === 1) {
                return (int)$value;
            }
            $when = strtotime($value);

            return $when === false ? null : max(0, $when - time());
        }
        return null;
    }

    /**
     * The repository has no published, installable release yet — either a
     * genuine HTTP 404 from the API (once-S91) or a success reply carrying
     * GitHub's "Not Found" error shape. Dry-run treats it as informational;
     * an install refuses.
     */
    private function noReleaseYet(SymfonyStyle $io, bool $dryRun): int
    {
        $io->warning([
            'No releases have been published for this repository yet.',
            '  Repository: https://github.com/detain/php-dup-finder',
            '  Once a tag is pushed and the release workflow publishes',
            '  phpdup.phar + phpdup.release.json + phpdup.release.json.sig',
            '  as assets, this command will be able to update.',
        ]);
        // Dry-run treats this as informational, not an error.
        if ($dryRun) {
            $io->success('Dry-run complete — no release available yet, nothing to do.');
            return 0;
        }
        return $this->failed($io, 'No release available to install.');
    }

    /**
     * Honest wording for a non-2xx API reply (once-S91). The reply body is
     * never interpreted — the status is the whole story, plus whatever
     * Retry-After guidance the service sent.
     */
    private function apiStatusMessage(int $status, ?int $retryAfter): string
    {
        if ($status === 401) {
            return 'GitHub release API refused the request with HTTP 401 (bad or missing credentials).'
                . $this->retryHint($retryAfter)
                . ' The error reply was not read as release data. GITHUB_TOKEN, when set, is sent on'
                . ' this API request only; release asset downloads are never authenticated'
                . ' (see docs/RELEASE.md).';
        }
        if ($status === 403) {
            return 'GitHub release API refused the request with HTTP 403 (rate limit or permission).'
                . $this->retryHint($retryAfter)
                . ' The error reply was not read as release data.';
        }
        if ($status >= 500) {
            return "GitHub release API is unavailable (HTTP {$status}); try again later."
                . $this->retryHint($retryAfter)
                . ' The error reply was not read as release data.';
        }
        if ($status >= 300 && $status < 400) {
            return "GitHub release API replied HTTP {$status}; this transport does not follow redirects"
                . ' on a token-bearing request.' . $this->retryHint($retryAfter)
                . ' The reply was not read as release data.';
        }
        return "GitHub release API replied an unexpected HTTP {$status}."
            . $this->retryHint($retryAfter)
            . ' The reply was not read as release data.';
    }

    /**
     * Retry-After guidance phrased as a trailing sentence ('' when absent).
     */
    private function retryHint(?int $retryAfter): string
    {
        return $retryAfter === null ? '' : " Retry after {$retryAfter}s as the API asked.";
    }


    /**
     * Download a file with progress reporting.
     *
     * Returns true ONLY on a complete, honest transfer (php-dup-finder-
     * shell-017): a read that fails before the stream reaches EOF, a
     * rejected/short write, or a byte count that disagrees with a known
     * Content-Length all return false and surface a specific error via
     * $io — never a silent "success" over a torn download.
     */
    protected function downloadFile(string $url, string $dest, SymfonyStyle $io): bool
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 300,
                'follow_location' => true,
                'max_redirects' => 5,
            ],
            'ssl' => [
                'verify_peer'  => true,
                'verify_depth' => 5,
            ],
        ]);

        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            return false;
        }

        // CI-85: PHP 8.5 deprecates the $http_response_header magic-
        // variable read in favour of http_get_last_response_headers();
        // on 8.3/8.4 the function does not exist and the legacy branch
        // supplies byte-identical content. Read once, and only for the
        // http(s) schemes the wrapper actually sets headers for — a
        // local-path fopen leaves the magic variable unset.
        $respHeaders = [];
        if (preg_match('#^https?://#i', $url)) {
            $respHeaders = function_exists('http_get_last_response_headers')
                ? (array) http_get_last_response_headers()
                : $http_response_header;
        }

        // Content-Length is parsed once (was re-parsed every loop tick)
        // and kept as a hard completion bound, not just progress input.
        $expectedBytes = null;
        if (preg_match('/Content-Length:\s*(\d+)/i', implode("\n", $respHeaders), $m)) {
            $expectedBytes = (int)$m[1];
        }

        // once-S91 (php-dup-finder-once-114): a 206/Content-Range reply is a
        // lie about completeness — this client never sends a Range request,
        // and a range's Content-Length describes the RANGE, not the
        // artifact, so the honest-looking length check above would "complete"
        // a torn download. Refuse the partial reply on sight. (A non-2xx
        // reply needs no arm here: without ignore_errors the wrapper fails
        // the open itself — the transport cannot launder it either way.)
        $finalStatus = self::parseFinalStatusCode($respHeaders);
        if (
            $finalStatus === 206
            || preg_match('/^Content-Range:/im', implode("\n", $respHeaders)) === 1
        ) {
            fclose($handle);
            $io->error(sprintf(
                'Download refused: the server answered %s (partial content); this client never requests ranges.',
                $finalStatus === 206 ? 'HTTP 206' : 'a Content-Range header',
            ));
            return false;
        }

        $outHandle = fopen($dest, 'wb');
        if ($outHandle === false) {
            fclose($handle);
            return false;
        }

        $ok = $this->pipeStream($handle, $outHandle, $expectedBytes, $io);

        fclose($handle);

        return $ok;
    }

    /**
     * The LAST HTTP status line in a wrapper reply list (once-S91). A
     * followed redirect chain carries one status line per hop — the final
     * reply is the one that describes the bytes, unlike the metadata GET
     * (which follows nothing and reads the single status line via
     * {@see Http::parseStatusCode()}). null when no HTTP layer replied.
     *
     * @param array<array-key, mixed> $headers
     */
    private static function parseFinalStatusCode(array $headers): ?int
    {
        $status = null;
        foreach ($headers as $header) {
            if (is_string($header) && preg_match('#^HTTP/[\d.]+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int)$m[1];
            }
        }
        return $status;
    }

    /**
     * Copy an open input stream into an open output stream with honest
     * completion semantics (shell-S36 test seam over stream resources).
     *
     * Fails (returns false, emits a specific $io error) when:
     *   - fread returns false, or stalls at '' without EOF, mid-stream;
     *   - fwrite accepts fewer bytes than given (or fails outright) —
     *     the pinned lane choice is refusal, not a resume loop;
     *   - flushing or closing the destination stream fails (a deferred
     *     write error surfacing on the file at the end of the transfer);
     *   - $expectedBytes (a known Content-Length) does not match the
     *     number of bytes actually written.
     *
     * The output stream is flushed and closed here on every path; the
     * caller retains ownership of the input stream.
     *
     * @param resource $inHandle
     * @param resource $outHandle
     */
    protected function pipeStream($inHandle, $outHandle, ?int $expectedBytes, SymfonyStyle $io): bool
    {
        $start = microtime(true);
        $size  = 0;
        $lastPct = -1;

        while (!feof($inHandle)) {
            $chunk = fread($inHandle, 65536);
            if ($chunk === false || ($chunk === '' && !feof($inHandle))) {
                fclose($outHandle);
                $io->error(sprintf(
                    'Download interrupted: read failed before end of stream (received %s bytes so far).',
                    number_format($size),
                ));
                return false;
            }
            if ($chunk === '') {
                continue; // EOF race: feof() is set, loop exits next tick
            }

            $written = fwrite($outHandle, $chunk);
            if ($written === false || $written < strlen($chunk)) {
                fclose($outHandle);
                $io->error(sprintf(
                    'Download failed: could not write all received bytes to disk (%s of %s written).',
                    number_format($size + max(0, (int)$written)),
                    number_format($size + strlen($chunk)),
                ));
                return false;
            }
            $size += $written;

            // Progress every 10 %
            if ($expectedBytes !== null && $expectedBytes > 0) {
                $pct = (int)(($size / $expectedBytes) * 100 / 10) * 10;
                if ($pct !== $lastPct && $pct < 100) {
                    $elapsed = microtime(true) - $start;
                    $rate = $elapsed > 0 ? $size / $elapsed / 1024 / 1024 : 0;
                    $io->text(sprintf(
                        "  %3d%%  %s / %s  (%.1f MB/s)",
                        $pct,
                        number_format($size),
                        number_format($expectedBytes),
                        $rate,
                    ));
                    $lastPct = $pct;
                }
            }
        }

        // The fclose arm catches deferred write errors that surface at
        // close(2) on a regular destination file (e.g. ENOSPC on delayed
        // allocation). It stays as best-effort defence: PHP reports
        // fclose() true on a userland wrapper stream even when the
        // wrapper's stream_close fails, so no seam can flip that arm —
        // the regression test pins the fflush arm, which makes the
        // whole guard load-bearing; this note keeps the promise honest
        // about the seam-unreachable half.
        $flushed = fflush($outHandle);
        $closed  = fclose($outHandle);
        if ($flushed === false || $closed === false) {
            $io->error('Download failed: could not flush the downloaded file to disk.');
            return false;
        }

        if ($expectedBytes !== null && $size !== $expectedBytes) {
            $io->error(sprintf(
                'Download incomplete: expected %s bytes, received %s.',
                number_format($expectedBytes),
                number_format($size),
            ));
            return false;
        }

        return true;
    }

    /**
     * Post-install check on the path the new phar now lives at (once-S91,
     * php-dup-finder-once-114).
     *
     * Returns null when the install looks sound, or a short reason naming
     * what failed. On a verified install the bar is the strongest cheap one
     * available: the bytes AT THE FINAL PATH must still hash to what the
     * signed manifest pinned — a torn copy across a filesystem boundary, or
     * anything that disturbs the file between the rename and its use, is
     * caught HERE, while the previous phar still exists to fall back to. On
     * a --dev-allow-unsigned install no pin exists, so the bar is
     * deliberately weaker (present, readable, non-empty) — exactly as loud
     * as the mode itself.
     *
     * Override in tests to steer the outcome (the seam mirrors
     * downloadFile()/verifySignature()).
     */
    protected function smokeInstalledPhar(string $pharPath, ?string $pinnedSha256): ?string
    {
        if (!is_file($pharPath) || !is_readable($pharPath)) {
            return 'the installed file is not readable';
        }
        $size = @filesize($pharPath);
        if ($size === false || $size === 0) {
            return 'the installed file is empty';
        }
        if ($pinnedSha256 === null) {
            return null;
        }
        $hash = hash_file('sha256', $pharPath);
        if ($hash === false || !hash_equals($pinnedSha256, $hash)) {
            return 'its SHA-256 no longer matches the signed manifest';
        }
        return null;
    }

    /**
     * Absolute path of the running phar, '' when executing from a source
     * tree (shell-S36 seam: lets tests drive the real execute() download
     * caller leg against a fake phar path without ever touching the
     * checkout or the network — mock transport only, hard rule).
     */
    protected function runningPharPath(): string
    {
        return Phar::running();
    }

    protected function runningVersion(): string
    {
        $app = $this->getApplication();
        if ($app !== null) {
            return $app->getVersion();
        }
        // once-S30 (php-dup-finder-once-034, REL-11 half): the no-Application
        // fallback used to fabricate '0.0.0' — a version that exists nowhere,
        // shipped out onto the wire as the User-Agent. It joins the
        // single-source family now: the declared Phpdup\Util\Version::STRING
        // is exactly what a bare-instantiated command of this build is.
        return Version::STRING;
    }

    /** Read GITHUB_TOKEN from env, ignoring the logged-in gh CLI token. */
    protected function githubToken(): string
    {
        return (string)($_ENV['GITHUB_TOKEN'] ?? getenv('GITHUB_TOKEN') ?: '');
    }

    /**
     * Resolve the HMAC verification key: raw-string env override wins,
     * otherwise the compiled-in constant (once-S05).
     */
    protected function resolveVerificationKey(): string
    {
        $env = getenv(self::VERIFICATION_KEY_ENV);
        if (is_string($env) && $env !== '') {
            return $env;
        }
        return self::VERIFICATION_KEY;
    }

    /** True while the resolved key is the build placeholder or empty. */
    protected function verificationKeyIsPlaceholder(): bool
    {
        $key = $this->resolveVerificationKey();
        return $key === '' || $key === self::VERIFICATION_KEY_PLACEHOLDER;
    }

    private function placeholderKeyMessage(): string
    {
        return "Verification refused: this phpdup build carries the placeholder VERIFICATION_KEY ('"
            . self::VERIFICATION_KEY_PLACEHOLDER . "').\n"
            . '  No signature can be trusted until the release manager replaces it with the real '
            . "key (see docs/RELEASE.md; the raw secret also stays out of the phar only after the "
            . 'asymmetric-key owner action lands).\n'
            . '  For a development-only unverified install: --dev-allow-unsigned.';
    }

    /**
     * Loud stderr + output banner for --dev-allow-unsigned: this flag is
     * confined to development rigs and must be impossible to miss.
     */
    private function devUnsignedBanner(SymfonyStyle $io, OutputInterface $output): void
    {
        $lines = [
            '*** --dev-allow-unsigned: INSTALLING WITHOUT INTEGRITY VERIFICATION ***',
            'The artifact was NOT checked against a signed release manifest.',
            'A hostile or corrupted release would be installed verbatim.',
            'This flag is for development rigs only — never for production installs.',
        ];
        foreach ($lines as $line) {
            $io->writeln('<bg=red;fg=white>' . $line . '</>');
        }
        if ($output instanceof ConsoleOutputInterface) {
            $stderr = $output->getErrorOutput();
            foreach ($lines as $line) {
                $stderr->writeln($line);
            }
        }
    }

    /**
     * Verify the HMAC-SHA256 signature of a payload file (the release
     * manifest — once-S05 signs the manifest, not the phar, so the phar's
     * hash travels inside the signed envelope).
     *
     * Raw-string key contract: release.yml computes
     *   hash_hmac_file('sha256', 'phpdup.release.json', $HMAC_SECRET_KEY)
     * with NO base64 transform; this side must use the same raw key. Any
     * one-sided encoding silently guarantees a mismatch — that drift is
     * pinned by tests/Unit/Cli/UpdateCommandTest.php.
     *
     * A placeholder/empty key REFUSES (returns false), never passes.
     *
     * Override in tests to control verification outcome.
     */
    protected function verifySignature(string $payloadFile, string $expectedSig): bool
    {
        $key = $this->resolveVerificationKey();
        if ($key === '' || $key === self::VERIFICATION_KEY_PLACEHOLDER) {
            return false;
        }
        $actualSig = @hash_hmac_file('sha256', $payloadFile, $key);
        return is_string($actualSig) && hash_equals($expectedSig, $actualSig);
    }

    /**
     * Parse (don't validate downstream) the verified manifest envelope.
     * Returns the pinned artifact sha256, or null after emitting a
     * specific $io error — the manifest never reaches install logic in a
     * half-trusted shape.
     *
     * @param array<string, mixed>|null $manifest
     * @param string|null $releaseTag tag from the API response; null in
     *                                offline mode (binding noted instead)
     */
    private function validateManifest(?array $manifest, ?string $releaseTag, SymfonyStyle $io): ?string
    {
        if ($manifest === null) {
            $io->error('Release manifest is not valid JSON.');
            return null;
        }
        $schema = $manifest['schema'] ?? null;
        if ($schema !== self::MANIFEST_SCHEMA) {
            $io->error(sprintf(
                'Release manifest schema %s is not supported (this phpdup accepts schema %d only).',
                is_scalar($schema) ? (string)$schema : 'missing',
                self::MANIFEST_SCHEMA,
            ));
            return null;
        }
        if (($manifest['file'] ?? null) !== self::PHAR_ASSET_NAME) {
            $io->error(sprintf(
                'Release manifest pins an unexpected artifact: %s',
                is_string($manifest['file'] ?? null) ? $manifest['file'] : '(missing)',
            ));
            return null;
        }
        $tag = $manifest['tag'] ?? null;
        if (!is_string($tag) || $tag === '') {
            $io->error('Release manifest carries no tag.');
            return null;
        }
        if ($releaseTag !== null && $tag !== $releaseTag) {
            // Bind the manifest to THIS release metadata response: a signed
            // manifest for another tag must not be replayed onto this one.
            $io->error(sprintf(
                "Release manifest is pinned to tag %s but the release metadata names %s.",
                $tag,
                $releaseTag,
            ));
            return null;
        }
        if ($releaseTag === null) {
            $io->text("  Manifest tag: {$tag}  (offline mode — not bound to a live release)");
        }
        $sha = $manifest['sha256'] ?? null;
        if (!is_string($sha) || !preg_match('/^[0-9a-f]{64}$/', $sha)) {
            $io->error('Release manifest carries no well-formed sha256 (64-hex required).');
            return null;
        }
        return $sha;
    }

    private function failed(SymfonyStyle $io, string $message): int
    {
        $io->error($message);
        return 1;
    }
}
