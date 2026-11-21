<?php
declare(strict_types=1);

namespace Phpdup\Cli;

use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Phar;

/**
 * Self-update / upgrade command for the phpdup phar.
 *
 * Fetches the latest release tarball from GitHub, verifies its SHA-256
 * checksum against the JSON metadata endpoint, and replaces the running
 * phar in-place.  Rollback is automatic: if any step fails after the
 * old phar has been overwritten, the error is logged and the user is
 * told how to recover manually.
 *
 * Aliases registered in bin/phpdup:
 *   - `self:update`  → UpdateCommand (primary)
 *   - `upgrade`      → UpdateCommand (alias)
 *   - `self-update`  → UpdateCommand (alias)
 */
class UpdateCommand extends SymfonyCommand
{
    private const GITHUB_RELEASES_API = 'https://api.github.com/repos/detain/php-dup-finder/releases/latest';

    /** HMAC-SHA256 signature file attached to each release. */
    private const SIGNATURE_ASSET_NAME = 'phpdup.phar.sig';

    private const CHECKSUM_ASSET_NAME = 'phpdup.phar.sha256';
    private const PHAR_ASSET_NAME     = 'phpdup.phar';

    /**
     * Base64-encoded HMAC-SHA256 public verification key.
     *
     * Set via HMAC_SECRET_KEY in GitHub Actions secrets; the public key
     * is embedded here so phpdup can verify signatures without any
     * external key-server dependency.
     *
     * Replace the placeholder with the actual base64-encoded key before
     * the first signed release. Key rotation: update this constant and
     * tag a new release.
     *
     * @see docs/RELEASE.md
     */
    private const VERIFICATION_KEY = 'REPLACE_WITH_REAL_PUBLIC_KEY';

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
  1. Fetch the GitHub Releases JSON API for the latest tag.
  2. Download <fg=yellow>phpdup.phar.sig</> (HMAC-SHA256 signature).
  3. Download <fg=yellow>phpdup.phar</> and verify its HMAC-SHA256 signature
     against the pinned public key before installation.
  4. Fall back to SHA-256 checksum verification if signature unavailable.
  5. Replace the running phar atomically (temp rename).
  6. On any error after the old phar is removed, print recovery steps.

Requires: <fg=yellow>ext-curl</>, <fg=yellow>ext-hash</>, and network access.
Does NOT require composer or vendor/ — works with the standalone phar.

<fg=yellow>NOTE:</> This command only works when phpdup is running as a phar.
When invoked from a git clone (bin/phpdup) it will print a helpful message
pointing to the upgrade path for source-based installs.
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
                'allow-unsigned',
                null,
                InputOption::VALUE_NONE,
                'Allow installation even when signature verification fails (not recommended)',
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

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

        $release = $this->fetchJson(self::GITHUB_RELEASES_API, $headers);
        if ($release === null) {
            return $this->failed($io, 'Could not fetch release info from GitHub. Check your network / API rate limits.');
        }

        // Detect a "no releases yet" / 404 response. The GitHub API returns
        // {"message":"Not Found","status":"404",...} as a 200-shaped JSON body
        // when ignore_errors is on, so we have to inspect the body.
        if (
            !isset($release['tag_name'])
            && (
                ($release['status'] ?? '') === '404'
                || ($release['message'] ?? '') === 'Not Found'
            )
        ) {
            $io->warning([
                'No releases have been published for this repository yet.',
                '  Repository: https://github.com/detain/php-dup-finder',
                '  Once a tag is pushed and the release workflow publishes',
                '  phpdup.phar + phpdup.phar.sha256 as assets, this command',
                '  will be able to update.',
            ]);
            // Dry-run treats this as informational, not an error.
            if ($dryRun) {
                $io->success('Dry-run complete — no release available yet, nothing to do.');
                return 0;
            }
            return $this->failed($io, 'No release available to install.');
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

        if ($dryRun) {
            $io->success('Dry-run complete — no files were written.');
            return 0;
        }

        // -----------------------------------------------------------------
        // 2. Resolve asset URLs
        // -----------------------------------------------------------------
        $pharUrl       = null;
        $checksumUrl   = null;
        $signatureUrl  = null;
        foreach (($release['assets'] ?? []) as $asset) {
            if (($asset['name'] ?? '') === self::PHAR_ASSET_NAME) {
                $pharUrl = $asset['browser_download_url'] ?? null;
            }
            if (($asset['name'] ?? '') === self::CHECKSUM_ASSET_NAME) {
                $checksumUrl = $asset['browser_download_url'] ?? null;
            }
            if (($asset['name'] ?? '') === self::SIGNATURE_ASSET_NAME) {
                $signatureUrl = $asset['browser_download_url'] ?? null;
            }
        }

        if ($pharUrl === null) {
            return $this->failed(
                $io,
                "Asset <fg=yellow>" . self::PHAR_ASSET_NAME . "</> not found in release. " .
                'Ensure the release was created with `phpdup release` (build-phar.php --release).',
            );
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
        // 4. Verify HMAC-SHA256 signature (if available)
        // -----------------------------------------------------------------
        $allowUnsigned = (bool)$input->getOption('allow-unsigned');
        $signatureVerified = false;
        if ($signatureUrl !== null) {
            $io->section('Verifying signature…');
            $tmpSig = $tmpFile . '.sig';
            $sigOk = $this->downloadFile($signatureUrl, $tmpSig, $io);
            if (!$sigOk) {
                @unlink($tmpSig);
                if ($allowUnsigned) {
                    $io->warning('Signature download failed — proceeding without verification (--allow-unsigned).');
                } else {
                    @unlink($tmpFile);
                    return $this->failed($io, 'Download of signature file failed. Use --allow-unsigned to install anyway (not recommended).');
                }
            } else {
                $expectedSig = trim((string)@file_get_contents($tmpSig)) ?: '';
                @unlink($tmpSig);
                if ($expectedSig === '') {
                    if ($allowUnsigned) {
                        $io->warning('Signature file is empty — proceeding without verification (--allow-unsigned).');
                    } else {
                        @unlink($tmpFile);
                        return $this->failed($io, 'Signature file is empty. Use --allow-unsigned to install anyway (not recommended).');
                    }
                } else {
                    if (!$this->verifySignature($tmpFile, $expectedSig)) {
                        if ($allowUnsigned) {
                            $io->warning('Signature mismatch — proceeding without verification (--allow-unsigned).');
                        } else {
                            @unlink($tmpFile);
                            return $this->failed($io, "HMAC-SHA256 signature mismatch.\nUse --allow-unsigned to install anyway (not recommended).");
                        }
                    } else {
                        $io->text("  HMAC-SHA256: <fg=green>verified</>  ✓");
                        $signatureVerified = true;
                    }
                }
            }
        } elseif (!$allowUnsigned) {
            @unlink($tmpFile);
            return $this->failed($io, 'No signature file found in release. Use --allow-unsigned to install anyway (not recommended).');
        } else {
            $io->warning('No signature file found — proceeding without verification (--allow-unsigned).');
        }

        // -----------------------------------------------------------------
        // 5. Verify SHA-256 checksum (fallback when no signature)
        // -----------------------------------------------------------------
        if (!$signatureVerified) {
            $io->section('Verifying checksum…');
            $expectedSha256 = null;
            if ($checksumUrl !== null) {
                $sslContext = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_depth' => 5]]);
                $checksumBody = @file_get_contents($checksumUrl, false, $sslContext);
                $expectedSha256 = $checksumBody !== false ? trim((string)$checksumBody) : null;
                if ($expectedSha256 !== null && preg_match('/^[a-f0-9]{64}/', $expectedSha256)) {
                    $expectedSha256 = preg_replace('/\s+.*/', '', $expectedSha256);
                }
            }
            $actualSha256 = hash_file('sha256', $tmpFile);
            if ($expectedSha256 !== null) {
                if (!hash_equals($expectedSha256, $actualSha256)) {
                    @unlink($tmpFile);
                    return $this->failed($io, sprintf(
                        "SHA-256 mismatch.\n  Expected: %s\n  Actual:   %s",
                        $expectedSha256,
                        $actualSha256,
                    ));
                }
                $io->text("  SHA-256: <fg=green>{$actualSha256}</>  ✓");
            } else {
                $io->text("  SHA-256: {$actualSha256}  (no reference to verify against)");
            }
        }

        // -----------------------------------------------------------------
        // 6. Replace running phar
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
        @unlink($backupPath);

        $io->success([
            "phpdup updated to {$tagName}",
            "  New version:  <fg=yellow>{$tagName}</>",
            '  Run: <fg=yellow>phpdup --version</>  to verify.',
        ]);

        return 0;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Fetch and decode a JSON URL. Returns null on failure.
     *
     * @param array<string, string> $headers
     * @return array<string, mixed>|null
     */
    protected function fetchJson(string $url, array $headers): ?array
    {
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
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
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
     * Copy an open input stream into an open output stream with honest
     * completion semantics (shell-S36 test seam over stream resources).
     *
     * Fails (returns false, emits a specific $io error) when:
     *   - fread returns false, or stalls at '' without EOF, mid-stream;
     *   - fwrite accepts fewer bytes than given (or fails outright) —
     *     the pinned lane choice is refusal, not a resume loop;
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
        return '0.0.0';
    }

    /** Read GITHUB_TOKEN from env, ignoring the logged-in gh CLI token. */
    protected function githubToken(): string
    {
        return (string)($_ENV['GITHUB_TOKEN'] ?? getenv('GITHUB_TOKEN') ?: '');
    }

    /**
     * Verify HMAC-SHA256 signature of a phar file.
     *
     * Override in tests to control verification outcome.
     */
    protected function verifySignature(string $pharFile, string $expectedSig): bool
    {
        $actualSig = hash_hmac_file('sha256', $pharFile, self::VERIFICATION_KEY);
        return hash_equals($expectedSig, $actualSig);
    }

    private function failed(SymfonyStyle $io, string $message): int
    {
        $io->error($message);
        return 1;
    }
}
