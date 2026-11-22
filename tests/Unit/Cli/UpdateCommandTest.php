<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\UpdateCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * shell-S36 legs: honest download completion over stream seams + the real
 * execute() download-caller leg (mock file:// transport only).
 *
 * once-S91 legs (php-dup-finder-once-114): the metadata transport is honest
 * about HTTP status (401/403/404/5xx/204/301 surfaced, never decoded as a
 * release payload, Retry-After reported) — driven end to end through the
 * REAL fetchJson against a forked loopback HTTP server; the declared-length
 * enforcement is pinned over a real reply, not just a hand-passed bound; the
 * missing-asset arm carries its positive pin (once-S12 r1 LOW-1); and the
 * previous phar is kept as a backup until the post-install check passes.
 *
 * once-S05 legs (php-dup-finder-once-005, fail-closed integrity rebuild):
 * signed release manifest + tag binding + artifact hash pinned to the
 * manifest (never to the same-response sha256 sidecar), placeholder-key
 * REFUSAL, raw-vs-base64 key-form agreement with the release.yml signing
 * formula, the confined --dev-allow-unsigned banner, and the offline
 * --verify-* round-trip code path the CI gate drives (exit 0 good /
 * exit 1 tamper).
 */
final class UpdateCommandTest extends TestCase
{
    /** Raw-string key exactly as .github/workflows/release.yml would sign with it. */
    private const RAW_KEY = 'once-s05-raw-key-Zm9vYmFy (never base64-decoded either side)';

    private const TAG = 'v9.9.9-s05';

    private const KEY_ENV = 'PHPDUP_UPDATE_VERIFICATION_KEY';

    private ?string $s36Scratch = null;

    protected function tearDown(): void
    {
        putenv(self::KEY_ENV);
        unset($_ENV[self::KEY_ENV], $_SERVER[self::KEY_ENV]);
        if ($this->s36Scratch !== null) {
            foreach (glob($this->s36Scratch . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->s36Scratch);
            $this->s36Scratch = null;
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // once-S05 — signed-manifest verification through the REAL code path
    // ------------------------------------------------------------------

    public function testGoodSignedReleaseInstallsThroughRealVerifyPath(): void
    {
        $artifact = $this->makeArtifact();
        [$manifest, $sig] = $this->makeSignedManifestPair($artifact, self::TAG, self::RAW_KEY);
        $command = $this->s05Command($artifact, $manifest, $sig);
        $command->keyOverride = self::RAW_KEY;

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([]), $output);
        $messages = $output->fetch();

        $this->assertSame(0, $exit);
        $this->assertSame(1, $command->realVerifyCalls, 'real HMAC primitive must run exactly once');
        $this->assertStringContainsString('Manifest HMAC-SHA256', $messages);
        $this->assertStringContainsString('verified', $messages);
        $this->assertStringContainsString('pinned to the signed manifest', $messages);
        $this->assertStringEqualsFile($this->fakePharPath(), (string)file_get_contents($artifact));
        $this->assertSame([], $this->scratchGlob('*.tmp.*'));
    }

    public function testTamperedArtifactRefusedAgainstManifestPin(): void
    {
        $artifact = $this->makeArtifact();
        [$manifest, $sig] = $this->makeSignedManifestPair($artifact, self::TAG, self::RAW_KEY);
        // Tamper AFTER signing: the manifest pins the pristine hash, the
        // served bytes no longer match — the classic release-bucket swap.
        file_put_contents($artifact, (string)file_get_contents($artifact) . "\nTAMPERED");

        $command = $this->s05Command($artifact, $manifest, $sig);
        $command->keyOverride = self::RAW_KEY;

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([]), $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('SHA-256 does not match the signed manifest', $output->fetch());
        $this->assertStringEqualsFile($this->fakePharPath(), 'OLD-PHAR-CONTENT', 'tampered bytes must never hot-swap');
        $this->assertSame([], $this->scratchGlob('*.tmp.*'));
    }

    public function testSameResponseChecksumAssetIsNoLongerTrusted(): void
    {
        // The release response carries a sha256 sidecar with a WRONG value.
        // The old flow could fall back to it; the once-S05 flow ignores it
        // entirely and pins only what the signed manifest says — so the
        // install succeeds (manifest is authoritative) and, conversely, the
        // sidecar can never be the reason an install passes.
        $artifact = $this->makeArtifact();
        [$manifest, $sig] = $this->makeSignedManifestPair($artifact, self::TAG, self::RAW_KEY);
        $sidecar = $this->scratchFile('remote.phar.sha256');
        file_put_contents($sidecar, str_repeat('d', 64));

        $command = $this->s05Command($artifact, $manifest, $sig);
        $command->keyOverride = self::RAW_KEY;
        $command->releasePayload['assets'][] = [
            'name' => 'phpdup.phar.sha256',
            'browser_download_url' => 'file://' . $sidecar,
        ];

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([]), $output);

        $this->assertSame(0, $exit, 'a mismatched same-response sidecar must not affect the outcome');
        $this->assertStringNotContainsString('Verifying checksum', $output->fetch(), 'the legacy checksum-fallback section is gone');
        $this->assertStringEqualsFile($this->fakePharPath(), (string)file_get_contents($artifact));
    }

    public function testPlaceholderKeyRefusesInsteadOfPassing(): void
    {
        $artifact = $this->makeArtifact();
        [$manifest, $sig] = $this->makeSignedManifestPair($artifact, self::TAG, self::RAW_KEY);
        $command = $this->s05Command($artifact, $manifest, $sig);
        // keyOverride stays null and no env key is set → resolved key is the
        // shipped VERIFICATION_KEY placeholder → hard refusal BEFORE transfer.
        $this->assertTrue($command->keyIsPlaceholder());

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([]), $output);
        $messages = $output->fetch();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('placeholder', $messages);
        $this->assertStringContainsString('VERIFICATION_KEY', $messages);
        $this->assertSame(0, $command->realVerifyCalls, 'nothing may verify with the placeholder');
        $this->assertStringEqualsFile($this->fakePharPath(), 'OLD-PHAR-CONTENT');
        $this->assertSame([], $this->scratchGlob('*.tmp.*'), 'refusal must happen before any download residue');
    }

    public function testPlaceholderKeyVerifyPrimitiveRefusesDirectly(): void
    {
        $artifact = $this->makeArtifact();
        [$manifest, $sig] = $this->makeSignedManifestPair($artifact, self::TAG, self::RAW_KEY);
        $command = $this->s05Command($artifact, $manifest, $sig);

        // Even when driven straight (offline-style call sites), the real
        // verifySignature must REFUSE with the placeholder — never pass.
        $this->assertFalse($command->verifyDirect($manifest, trim((string)file_get_contents($sig))));

        // Fix r1 M-1(i): the pair above passes the assertFalse even with the
        // :848 placeholder arm deleted (plain HMAC mismatch). This second
        // pair is signed UNDER the publicly-known placeholder string itself —
        // the known-plaintext shape — so the raw HMAC MATCHES and only the
        // arm can refuse it. Deleting the `=== self::VERIFICATION_KEY_PLACEHOLDER`
        // check flips this assert red.
        [$plainTextManifest, $plainTextSig] = $this->makeSignedManifestPair(
            $artifact,
            'v0.0.0-known-plaintext',
            UpdateCommand::VERIFICATION_KEY_PLACEHOLDER,
        );
        $sigBytes = trim((string)file_get_contents($plainTextSig));
        $this->assertSame(
            $sigBytes,
            hash_hmac_file('sha256', $plainTextManifest, UpdateCommand::VERIFICATION_KEY_PLACEHOLDER),
            'fixture sanity: the pair must HMAC-match under the placeholder key, '
            . 'otherwise the arm below is not what refuses it (vacuity guard)',
        );
        $this->assertFalse(
            $command->verifyDirect($plainTextManifest, $sigBytes),
            'a signature that HMAC-matches under the committed placeholder string must still REFUSE',
        );
    }

    public function testRawKeyFormMatchesSigningFormulaBothSides(): void
    {
        $artifact = $this->makeArtifact();
        [$manifest, $sig] = $this->makeSignedManifestPair($artifact, self::TAG, self::RAW_KEY);
        $command = $this->s05Command($artifact, $manifest, $sig);
        $command->keyOverride = self::RAW_KEY;

        $this->assertTrue(
            $command->verifyDirect($manifest, trim((string)file_get_contents($sig))),
            'raw-string key on both sides must agree (release.yml formula)',
        );
    }

    public function testBase64DecodedKeySignatureIsRefused(): void
    {
        // Old release.yml signed with base64_decode(secret) while the client
        // verified with the raw constant — the forms could never agree. Pin
        // that this lane's raw/raw contract stays broken against the old
        // one-sided decode.
        $artifact = $this->makeArtifact();
        [$manifest, $sig] = $this->makeSignedManifestPair($artifact, self::TAG, base64_decode(self::RAW_KEY, true) ?: 'x');

        $command = $this->s05Command($artifact, $manifest, $sig);
        $command->keyOverride = self::RAW_KEY;

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([]), $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('does not verify', $output->fetch());
        $this->assertStringEqualsFile($this->fakePharPath(), 'OLD-PHAR-CONTENT');
    }

    public function testEnvOverrideSuppliesRawKey(): void
    {
        // Exactly what the roundtrip-verify job in release.yml does: set the
        // env key, no rebuild, verification uses it raw.
        $artifact = $this->makeArtifact();
        [$manifest, $sig] = $this->makeSignedManifestPair($artifact, self::TAG, self::RAW_KEY);
        putenv(self::KEY_ENV . '=' . self::RAW_KEY);

        $command = $this->s05Command($artifact, $manifest, $sig);

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([]), $output);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('pinned to the signed manifest', $output->fetch());
    }

    public function testManifestTagMismatchRefused(): void
    {
        // A signed manifest for another tag must not be replayed onto this
        // release: the tag is bound against the API metadata response.
        $artifact = $this->makeArtifact();
        [$manifest, $sig] = $this->makeSignedManifestPair($artifact, 'v0.0.0-evil', self::RAW_KEY);

        $command = $this->s05Command($artifact, $manifest, $sig);
        $command->keyOverride = self::RAW_KEY;

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([]), $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('pinned to tag v0.0.0-evil', $output->fetch());
        $this->assertStringEqualsFile($this->fakePharPath(), 'OLD-PHAR-CONTENT');
        $this->assertSame([], $this->scratchGlob('*.tmp.*'));
    }

    public function testMissingManifestAssetsRefuseInstall(): void
    {
        $artifact = $this->makeArtifact();
        $command = $this->s05Command($artifact, null, null);
        $command->keyOverride = self::RAW_KEY;

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([]), $output);
        $messages = $output->fetch();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no signed manifest', $messages);
        $this->assertStringContainsString('--dev-allow-unsigned', $messages, 'the refusal must name the only (loud, dev) way through');
        $this->assertSame(0, $command->realVerifyCalls);
        $this->assertStringEqualsFile($this->fakePharPath(), 'OLD-PHAR-CONTENT');
        $this->assertSame([], $this->scratchGlob('*.tmp.*'), 'the guard runs before any transfer');
    }

    public function testReleaseManifestDownloadFailureAbortsInstall(): void
    {
        $artifact = $this->makeArtifact();
        [$manifest, $sig] = $this->makeSignedManifestPair($artifact, self::TAG, self::RAW_KEY);
        $command = $this->s05Command($artifact, $manifest, $sig);
        $command->keyOverride = self::RAW_KEY;
        $command->failManifestDownload = true;

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([]), $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Download of release manifest failed.', $output->fetch());
        $this->assertSame(0, $command->realVerifyCalls);
        $this->assertStringEqualsFile($this->fakePharPath(), 'OLD-PHAR-CONTENT');
        $this->assertSame([], $this->scratchGlob('*.tmp.*'));
    }

    public function testMalformedSignatureFileRefused(): void
    {
        $artifact = $this->makeArtifact();
        [$manifest, $sig] = $this->makeSignedManifestPair($artifact, self::TAG, self::RAW_KEY);
        file_put_contents($sig, 'deadbeef-not-hex-at-all');

        $command = $this->s05Command($artifact, $manifest, $sig);
        $command->keyOverride = self::RAW_KEY;

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([]), $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Signature file is malformed', $output->fetch());
        $this->assertStringEqualsFile($this->fakePharPath(), 'OLD-PHAR-CONTENT');
    }

    public function testSignedButUnparseableManifestRefused(): void
    {
        // Signature math may PASS while the envelope is garbage: the parser
        // must still refuse — the manifest never reaches install logic in a
        // half-trusted shape (parse, don't validate downstream).
        $artifact = $this->makeArtifact();
        $manifest = $this->scratchFile('garbage.release.json');
        file_put_contents($manifest, "this is not json {\n");
        $sig = $manifest . '.sig';
        file_put_contents($sig, hash_hmac_file('sha256', $manifest, self::RAW_KEY));

        $command = $this->s05Command($artifact, $manifest, $sig);
        $command->keyOverride = self::RAW_KEY;

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([]), $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not valid JSON', $output->fetch());
        $this->assertStringEqualsFile($this->fakePharPath(), 'OLD-PHAR-CONTENT');
    }

    // ------------------------------------------------------------------
    // once-S05 — --dev-allow-unsigned (the confined funnel)
    // ------------------------------------------------------------------

    public function testAllowUnsignedFlagIsGone(): void
    {
        $definition = (new UpdateCommand())->getDefinition();
        $this->assertFalse($definition->hasOption('allow-unsigned'), 'bare --allow-unsigned must no longer exist');
        $this->assertTrue($definition->hasOption('dev-allow-unsigned'));
        $this->assertStringContainsString('DEV ONLY', (string)$definition->getOption('dev-allow-unsigned')->getDescription());
    }

    public function testDevAllowUnsignedInstallsWithLoudBanner(): void
    {
        $artifact = $this->makeArtifact();
        // No manifest/sig assets AND placeholder key — dev mode must still
        // install (that is its whole, loudly-disclosed purpose) — and say so.
        $command = $this->s05Command($artifact, null, null);

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput(['--dev-allow-unsigned' => true]), $output);
        $messages = $output->fetch();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('INSTALLING WITHOUT INTEGRITY VERIFICATION', $messages);
        $this->assertStringContainsString('UNVERIFIED', $messages);
        $this->assertSame(0, $command->realVerifyCalls);
        $this->assertStringEqualsFile($this->fakePharPath(), (string)file_get_contents($artifact));
    }

    public function testDevAllowUnsignedBannerLandsOnErrorStream(): void
    {
        // Fix r1 L-2: the acceptance says "loud stderr" — the existing banner
        // test drives a plain BufferedOutput, so only the in-band half is
        // asserted there. This pin drives a ConsoleOutputInterface double and
        // asserts the banner is written to the ERROR stream as well; deleting
        // the ConsoleOutputInterface branch in devUnsignedBanner flips it red.
        $artifact = $this->makeArtifact();
        $command = $this->s05Command($artifact, null, null);

        $stderr = new BufferedOutput();
        $output = new S05ConsoleOutput($stderr);
        $exit = $command->run(new ArrayInput(['--dev-allow-unsigned' => true]), $output);

        $this->assertSame(0, $exit);

        $errorStream = $stderr->fetch();
        $this->assertStringContainsString(
            '*** --dev-allow-unsigned: INSTALLING WITHOUT INTEGRITY VERIFICATION ***',
            $errorStream,
            'the banner must hit the error stream, not only the in-band output',
        );
        $this->assertStringContainsString('never for production installs', $errorStream);
        $this->assertStringNotContainsString('<', $errorStream, 'stderr lines are raw, no markup tags leak');

        // The in-band half is still present (S05ConsoleOutput is itself a
        // BufferedOutput; SymfonyStyle writes go to its main buffer).
        $this->assertStringContainsString('INSTALLING WITHOUT INTEGRITY VERIFICATION', $output->fetch());
    }

    // ------------------------------------------------------------------
    // once-S05 — offline --verify-* mode (CI round-trip code path)
    // ------------------------------------------------------------------

    public function testOfflineVerifyGoodArtifactExitsZero(): void
    {
        putenv(self::KEY_ENV . '=' . self::RAW_KEY);
        $artifact = $this->makeArtifact();
        [$manifest, $sig] = $this->makeSignedManifestPair($artifact, self::TAG, self::RAW_KEY);

        // Plain command: offline mode runs before the phar guard, so a source
        // tree (phpunit context, Phar::running() === '') is enough.
        $command = new UpdateCommand();
        $app = new Application('phpdup', '0.0.0');
        $app->add($command);

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([
            '--verify-artifact' => $artifact,
            '--verify-manifest' => $manifest,
            '--verify-signature' => $sig,
        ]), $output);
        $messages = $output->fetch();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Offline verification passed', $messages);
        $this->assertStringContainsString('not bound to a live release', $messages, 'offline mode must disclose the unbound tag');
    }

    public function testOfflineVerifyTamperedArtifactExitsOne(): void
    {
        putenv(self::KEY_ENV . '=' . self::RAW_KEY);
        $artifact = $this->makeArtifact();
        [$manifest, $sig] = $this->makeSignedManifestPair($artifact, self::TAG, self::RAW_KEY);
        file_put_contents($artifact, (string)file_get_contents($artifact) . "\nTAMPERED");

        $command = new UpdateCommand();
        $app = new Application('phpdup', '0.0.0');
        $app->add($command);

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([
            '--verify-artifact' => $artifact,
            '--verify-manifest' => $manifest,
            '--verify-signature' => $sig,
        ]), $output);

        $this->assertSame(1, $exit, 'tamper after signing must exit 1 (round-trip gate leg)');
        $this->assertStringContainsString('SHA-256 does not match the signed manifest', $output->fetch());
    }

    public function testOfflineVerifyPlaceholderKeyExitsOne(): void
    {
        // No env key: the third round-trip leg — placeholder must REFUSE.
        $artifact = $this->makeArtifact();
        [$manifest, $sig] = $this->makeSignedManifestPair($artifact, self::TAG, self::RAW_KEY);

        $command = new UpdateCommand();
        $app = new Application('phpdup', '0.0.0');
        $app->add($command);

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([
            '--verify-artifact' => $artifact,
            '--verify-manifest' => $manifest,
            '--verify-signature' => $sig,
        ]), $output);

        $this->assertSame(1, $exit);
        $messages = $output->fetch();
        $this->assertStringContainsString('placeholder', $messages);
        $this->assertStringContainsString('VERIFICATION_KEY', $messages);
    }

    public function testOfflineVerifyRequiresAllThreeOptions(): void
    {
        $artifact = $this->makeArtifact();
        $command = new UpdateCommand();
        $app = new Application('phpdup', '0.0.0');
        $app->add($command);

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput(['--verify-artifact' => $artifact]), $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('together', $output->fetch());
    }

    /**
     * Fix r1 M-1(ii): the four post-HMAC validateManifest arms. Each case is
     * a manifest signed with the REAL key (the HMAC leg PASSES — the refusal
     * must come from the arm itself, never from a signature mismatch), then
     * driven through the offline --verify-* path exactly as release.yml's
     * round-trip job does. Deleting any single arm lets its case fall
     * through to a different message or exit 0 — flipping that case red.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function malformedSignedManifestCases(): array
    {
        return [
            'schema arm'  => [['schema' => 2], 'schema 2 is not supported'],
            'file arm'    => [['file' => 'phpdup.evil.phar'], 'unexpected artifact: phpdup.evil.phar'],
            'tag arm'     => [['tag' => ''], 'carries no tag'],
            'sha arm'     => [['sha256' => 'nothex'], 'no well-formed sha256'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedSignedManifestCases')]
    public function testOfflineVerifyMalformedSignedManifestExitsOne(array $fieldOverrides, string $expectedMessage): void
    {
        putenv(self::KEY_ENV . '=' . self::RAW_KEY);
        $artifact = $this->makeArtifact();
        [$manifest, $sig] = $this->makeSignedManifestPair($artifact, self::TAG, self::RAW_KEY, $fieldOverrides);

        // Positive control: the envelope is genuinely HMAC-valid under
        // RAW_KEY — so any refusal below is the arm, not the signature.
        $this->assertSame(
            trim((string)file_get_contents($sig)),
            hash_hmac_file('sha256', $manifest, self::RAW_KEY),
        );

        $command = new UpdateCommand();
        $app = new Application('phpdup', '0.0.0');
        $app->add($command);

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([
            '--verify-artifact' => $artifact,
            '--verify-manifest' => $manifest,
            '--verify-signature' => $sig,
        ]), $output);

        $this->assertSame(1, $exit, 'malformed signed manifest (' . $expectedMessage . ') must exit 1');
        $this->assertStringContainsString(
            $expectedMessage,
            $output->fetch(),
            'each validateManifest arm must speak its own distinct message',
        );
    }

    // ------------------------------------------------------------------
    // once-S05 — dry-run honesty (reports, never verifies, never writes)
    // ------------------------------------------------------------------

    public function testDryRunReportsPlaceholderAndMissingAssets(): void
    {
        $artifact = $this->makeArtifact();
        $command = $this->s05Command($artifact, null, null);

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput(['--dry-run' => true]), $output);
        $messages = $output->fetch();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('PLACEHOLDER', $messages);
        $this->assertStringContainsString('MISSING', $messages);
        $this->assertStringEqualsFile($this->fakePharPath(), 'OLD-PHAR-CONTENT');
        $this->assertSame([], $this->scratchGlob('*.tmp.*'));
    }

    // ------------------------------------------------------------------
    // shell-S36 — honest download completion (php-dup-finder-shell-017)
    // ------------------------------------------------------------------

    public function testReadFailureBeforeEofFailsDownload(): void
    {
        S36FalseReadStream::$nextPayload = str_repeat('a', 600);
        $in = self::openWrapper('s36-false-read://x');
        $dest = $this->scratchFile('torn.bin');
        $out = $this->openOut($dest);
        $io = $this->style($buffer);

        $result = (new TestUpdateCommand(TestUpdateCommand::MODE_S36))->pipe($in, $out, null, $io);
        fclose($in);

        $this->assertFalse($result, 'fread === false before EOF must fail the download');
        $this->assertStringContainsString('read failed before end of stream', $buffer->fetch());
        // Torn partial bytes may linger; the caller unlinks $dest. Success is never claimed.
        $this->assertLessThan(1000, strlen((string)file_get_contents($dest)));
    }

    public function testStalledEmptyReadFailsDownloadWithoutSpinning(): void
    {
        $in = self::openWrapper('s36-stalled-read://x');
        $out = $this->openOut($this->scratchFile('stall.bin'));
        $io = $this->style($buffer);
        $start = microtime(true);

        $result = (new TestUpdateCommand(TestUpdateCommand::MODE_S36))->pipe($in, $out, null, $io);
        fclose($in);

        $this->assertFalse($result, 'a stalled empty read must fail, not loop forever');
        $this->assertLessThan(5.0, microtime(true) - $start, 'stall detection must be immediate');
        $this->assertStringContainsString('read failed before end of stream', $buffer->fetch());
    }

    public function testContentLengthMismatchFailsDownload(): void
    {
        $in = $this->tempStream(str_repeat('b', 500));
        $out = $this->openOut($this->scratchFile('cl.bin'));
        $io = $this->style($buffer);

        $result = (new TestUpdateCommand(TestUpdateCommand::MODE_S36))->pipe($in, $out, 1000, $io);
        fclose($in);

        $this->assertFalse($result);
        $messages = $buffer->fetch();
        $this->assertStringContainsString('Download incomplete', $messages);
        $this->assertStringContainsString('expected 1,000 bytes, received 500', $messages);
    }

    public function testPartialWriteFailsDownload(): void
    {
        // Non-blocking unix socketpair with a shrunken send buffer and no
        // reader: once full, fwrite() accepts fewer bytes than given — the
        // real short-write shape fwrite can exhibit on a stalling sink.
        // Pinned lane choice (shell-S36): refuse, do NOT resume-loop.
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair unavailable');
        }
        [$reader, $writer] = $pair;
        $sock = socket_import_stream($writer);
        if ($sock !== false) {
            @socket_set_option($sock, SOL_SOCKET, SO_SNDBUF, 4096);
        }
        stream_set_blocking($writer, false);
        $filler = str_repeat('x', 65536);
        for ($i = 0; $i < 50; $i++) {
            if (@fwrite($writer, $filler) === 0) {
                break; // send buffer is full
            }
        }

        $in = $this->tempStream($filler);
        $io = $this->style($buffer);

        $result = (new TestUpdateCommand(TestUpdateCommand::MODE_S36))->pipe($in, $writer, null, $io);
        fclose($in);
        fclose($reader);
        // $writer was closed by pipeStream itself.

        $this->assertFalse($result, 'a short fwrite (< chunk length) must fail the download');
        $this->assertStringContainsString('could not write all received bytes', $buffer->fetch());
    }

    public function testHappyPathWritesExactBytesWithProgress(): void
    {
        $payload = str_repeat('c', 131072); // two 64 KiB reads -> a 50 % tick
        $in = $this->tempStream($payload);
        $dest = $this->scratchFile('happy.bin');
        $out = $this->openOut($dest);
        $io = $this->style($buffer);

        $result = (new TestUpdateCommand(TestUpdateCommand::MODE_S36))->pipe($in, $out, 131072, $io);
        fclose($in);

        $this->assertTrue($result);
        $this->assertSame($payload, file_get_contents($dest), 'happy-path bytes unchanged');
        $messages = $buffer->fetch();
        $this->assertStringContainsString('50%', $messages, 'progress reporting kept');
        $this->assertStringContainsString('/ 131,072', $messages);
    }

    public function testFlushFailureFailsDownload(): void
    {
        // Destination sink whose stream_flush returns false while every
        // fwrite succeeds — the deferred write-error shape the final
        // flush/close guard exists to catch. (The fclose half of that
        // guard is seam-unreachable by PHP wrapper semantics: fclose()
        // reports true even when stream_close fails; see the best-effort
        // note on the guard in UpdateCommand::pipeStream.)
        $in = $this->tempStream(str_repeat('d', 500));
        $out = self::openWriteWrapper('s36-failing-flush://out.bin');
        $io = $this->style($buffer);

        $result = (new TestUpdateCommand(TestUpdateCommand::MODE_S36))->pipe($in, $out, null, $io);
        fclose($in); // $out was closed by pipeStream itself — never fclose twice

        $this->assertFalse($result, 'a failing flush must fail the download');
        $this->assertStringContainsString('could not flush the downloaded file to disk', $buffer->fetch());
    }

    public function testTornDownloadRefusesSignatureAndHotSwap(): void
    {
        $command = $this->s36Command(failPharDownload: true);
        $output = new BufferedOutput();

        $exit = $command->run(new ArrayInput([]), $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Download of phar failed.', $output->fetch());
        $this->assertSame(0, $command->verifySignatureCalls, 'signature check must not run on a torn download');
        $this->assertSame(
            'OLD-PHAR-CONTENT',
            file_get_contents($this->fakePharPath()),
            'the running (fake) phar must never be renamed onto a torn download',
        );
        $this->assertSame([], $this->scratchGlob('*.tmp.*'));
    }

    public function testSignatureDownloadFailureAbortsInstall(): void
    {
        $command = $this->s36Command(failSigDownload: true);
        $output = new BufferedOutput();

        $exit = $command->run(new ArrayInput([]), $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Download of signature file failed.', $output->fetch());
        $this->assertSame(0, $command->verifySignatureCalls);
        $this->assertSame('OLD-PHAR-CONTENT', file_get_contents($this->fakePharPath()));
        $this->assertSame([], $this->scratchGlob('*.tmp.*'), 'downloaded phar must be discarded');
    }

    public function testRealTransportHappyPathHotSwapsViaMockFileServer(): void
    {
        // Mock transport only: file:// URLs backed by scratch files. Never
        // point this at the network or at the checkout's own binaries.
        $command = $this->s36Command();
        $output = new BufferedOutput();

        $exit = $command->run(new ArrayInput([]), $output);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $command->verifySignatureCalls);
        $this->assertStringContainsString('verified', $output->fetch());
        $this->assertStringContainsString(
            'NEW-PHAR-BYTES',
            file_get_contents($this->fakePharPath()),
            'hot-swap replaces the phar only after a complete download',
        );
        $this->assertSame([], $this->scratchGlob('*.tmp.*'));
        $this->assertSame([], $this->scratchGlob('*.backup.*'));
    }

    // ------------------------------------------------------------------
    // once-S91 — status-honest metadata transport (php-dup-finder-once-114)
    // ------------------------------------------------------------------

    /**
     * The whole finding in one table: api.github.com answers EVERY failure
     * with a JSON *error body*, and the old status-blind leg handed those
     * bodies to the release logic — the once-S12 r1 probe watched a live
     * rate-limit reply surface as "Asset phpdup.phar not found in release
     * unknown". Each case drives the REAL fetchJson + REAL execute() against
     * a forked loopback HTTP server on an ephemeral port (house harness
     * mirrored from tests/Unit/Util/HttpTest.php): no seam substitutes the
     * fetch, no external network, no network group.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: int, 3: list<string>, 4: list<string>}>
     */
    public static function apiReplyCases(): array
    {
        $rateLimitBody = '{"message":"API rate limit exceeded for 203.0.113.7.'
            . ' (But here\'s the good news: Authenticated requests get a higher rate limit.'
            . ' Check out the documentation for more details.)",'
            . '"documentation_url":"https://docs.github.com/rest/overview/resources-in-the-rest-api#rate-limiting"}';
        $notFoundBody = '{"message":"Not Found","documentation_url":"https://docs.github.com/'
            . 'rest/releases/releases#get-the-latest-release","status":"404"}';

        return [
            'rate limit 403' => [
                self::httpReply('403 Forbidden', ['Retry-After' => '61', 'Content-Type' => 'application/json'], $rateLimitBody),
                [],
                1,
                ['HTTP 403', 'rate limit', 'Retry after 61s', 'not read as release data'],
                ['not found in release', 'unknown'],
            ],
            'unauthorized 401' => [
                self::httpReply('401 Unauthorized', ['Content-Type' => 'application/json'], '{"message":"Bad credentials","status":"401"}'),
                [],
                1,
                ['HTTP 401', 'GITHUB_TOKEN', 'not read'],
                ['not found in release'],
            ],
            'not found 404' => [
                self::httpReply('404 Not Found', ['Content-Type' => 'application/json'], $notFoundBody),
                [],
                1,
                ['No releases have been published', 'No release available to install'],
                ['did not complete', 'not found in release'],
            ],
            'not found 404 under --dry-run' => [
                self::httpReply('404 Not Found', ['Content-Type' => 'application/json'], $notFoundBody),
                ['--dry-run' => true],
                0,
                ['No releases have been published', 'no release available yet'],
                ['No release available to install'],
            ],
            'bad gateway 502' => [
                self::httpReply('502 Bad Gateway', ['Retry-After' => '3', 'Content-Type' => 'application/json'], '{"message":"Bad gateway"}'),
                [],
                1,
                ['HTTP 502', 'unavailable', 'Retry after 3s'],
                ['not found in release'],
            ],
            'no content 204' => [
                self::httpReply('204 No Content', [], ''),
                [],
                1,
                ['HTTP 204', 'without a usable JSON release body'],
                ['did not complete', 'not found in release'],
            ],
            'redirect 301 refused not followed' => [
                self::httpReply('301 Moved Permanently', ['Location' => 'https://api.github.com/repositories/1/releases/latest'], ''),
                [],
                1,
                ['HTTP 301', 'does not follow redirects'],
                ['not found in release'],
            ],
            'not-found error body behind a rewritten 200' => [
                self::httpReply('200 OK', ['Content-Type' => 'application/json'], $notFoundBody),
                [],
                1,
                ['No releases have been published'],
                ['not found in release'],
            ],
            'malformed success body' => [
                self::httpReply('200 OK', ['Content-Type' => 'application/json'], "not json at all {\n"),
                [],
                1,
                ['HTTP 200', 'without a usable JSON release body'],
                ['not found in release'],
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('apiReplyCases')]
    public function testApiErrorRepliesAreSurfacedNeverParsedAsRelease(
        string $reply,
        array $input,
        int $expectedExit,
        array $expectedSubstrings,
        array $forbiddenSubstrings,
    ): void {
        [$pid, $port, $server] = $this->forkHttpServer(static fn(string $request): string => $reply);
        try {
            $command = $this->s91Command(
                "http://127.0.0.1:$port/repos/detain/php-dup-finder/releases/latest"
            );

            $output = new BufferedOutput();
            $exit = $command->run(new ArrayInput($input), $output);
            $flat = self::flatten($output->fetch());

            $this->assertSame($expectedExit, $exit);
            foreach ($expectedSubstrings as $needle) {
                $this->assertStringContainsString($needle, $flat, 'the transport must surface: ' . $needle);
            }
            foreach ($forbiddenSubstrings as $banned) {
                $this->assertStringNotContainsString($banned, $flat, 'an error reply may never talk like release data: ' . $banned);
            }
            $this->assertStringEqualsFile($this->fakePharPath(), 'OLD-PHAR-CONTENT', 'a refused metadata reply installs nothing');
            $this->assertSame([], $this->scratchGlob('*.tmp.*'));
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    public function testNoReplyAtAllIsTheNetworkStoryNotAnInventedReleaseFailure(): void
    {
        // Bind, take the port, close the listener: a connect now is refused,
        // so the transport gets NO reply at all. The old leg's single generic
        // message blamed "API rate limits" for a dead socket; the honest
        // posture is the network story — and never a release-shaped failure
        // conjured out of nothing.
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped("loopback bind unavailable: $errstr");
        }
        $name = (string)stream_socket_get_name($server, false);
        $port = (int)substr($name, (int)strrpos($name, ':') + 1);
        fclose($server);

        $command = $this->s91Command("http://127.0.0.1:$port/repos/detain/php-dup-finder/releases/latest");
        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([]), $output);
        $flat = self::flatten($output->fetch());

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('did not complete', $flat);
        $this->assertStringNotContainsString('rate limit', $flat, 'a dead socket is not a rate limit');
        $this->assertStringNotContainsString('not found in release', $flat);
        $this->assertStringEqualsFile($this->fakePharPath(), 'OLD-PHAR-CONTENT');
    }

    public function testReleaseJsonServedOverLoopbackDrivesARealVerifiedInstall(): void
    {
        // The other half of status honesty: a genuine 2xx must still run the
        // whole flow — REAL transport for the metadata, real HMAC over the
        // manifest, real swap — from a served payload. Without this, the
        // refusal arms could "pass" behind a transport that accepts nothing.
        $artifact = $this->makeArtifact();
        [$manifest, $sig] = $this->makeSignedManifestPair($artifact, self::TAG, self::RAW_KEY);
        $payload = self::releasePayloadJson('file://' . $artifact, $manifest, $sig);
        $reply = self::httpReply('200 OK', ['Content-Type' => 'application/json'], $payload);

        [$pid, $port, $server] = $this->forkHttpServer(static fn(string $request): string => $reply);
        try {
            $command = $this->s91Command(
                "http://127.0.0.1:$port/repos/detain/php-dup-finder/releases/latest"
            );
            $command->keyOverride = self::RAW_KEY;

            $output = new BufferedOutput();
            $exit = $command->run(new ArrayInput([]), $output);

            $this->assertSame(0, $exit, 'a served 2xx release must install through the real path');
            $this->assertStringContainsString('pinned to the signed manifest', $output->fetch());
            $this->assertStringEqualsFile($this->fakePharPath(), (string)file_get_contents($artifact));
            $this->assertSame([], $this->scratchGlob('*.tmp.*'));
            $this->assertSame([], $this->scratchGlob('*.backup.*'), 'the backup is removed only after the post-install check passed');
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    public function testGithubTokenRidesTheApiRequestOnlyAndItsValueIsNeverEchoed(): void
    {
        // once-S91 documented-or-absent leg, pinned at the half that IS
        // shipped: GITHUB_TOKEN goes on the metadata request. The server
        // plays the release JSON only when the Authorization header arrived,
        // so a transport that dropped the token fails the install case. The
        // asset legs are file:// and carry no auth at all — private-repo
        // downloads stay documented-ABSSENT (docs/RELEASE.md); nothing here
        // ever prints the secret's value.
        $secret = 's91-secret-never-print';
        $artifact = $this->makeArtifact();
        [$manifest, $sig] = $this->makeSignedManifestPair($artifact, self::TAG, self::RAW_KEY);
        $payload = self::releasePayloadJson('file://' . $artifact, $manifest, $sig);

        $responder = static function (string $request) use ($secret, $payload): string {
            if (str_contains($request, 'Authorization: Bearer ' . $secret)) {
                return self::httpReply('200 OK', ['Content-Type' => 'application/json'], $payload);
            }
            return self::httpReply(
                '401 Unauthorized',
                ['Content-Type' => 'application/json'],
                '{"message":"Bad credentials","status":"401"}'
            );
        };
        [$pid, $port, $server] = $this->forkHttpServer($responder, 5, 2);
        $url = "http://127.0.0.1:$port/repos/detain/php-dup-finder/releases/latest";
        try {
            $withToken = $this->s91Command($url);
            $withToken->keyOverride = self::RAW_KEY;
            $withToken->tokenValue  = $secret;

            $out1 = new BufferedOutput();
            $this->assertSame(0, $withToken->run(new ArrayInput([]), $out1), 'GITHUB_TOKEN must ride the metadata request');
            $this->assertStringNotContainsString($secret, $out1->fetch(), 'names printed, never values');

            $noToken = $this->s91Command($url);
            $out2 = new BufferedOutput();
            $this->assertSame(1, $noToken->run(new ArrayInput([]), $out2), 'an unauthenticated dial of an auth-gated API must refuse');
            $this->assertStringContainsString('HTTP 401', self::flatten($out2->fetch()));
            $this->assertStringNotContainsString($secret, $out2->fetch());
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    // ------------------------------------------------------------------
    // once-S91 — declared length enforced over a REAL reply (fake server)
    // ------------------------------------------------------------------

    public function testShortBodyUnderDeclaredContentLengthFailsOverTheWire(): void
    {
        // shell-S36's pipeStream legs pass the bound by hand; the once-S91
        // acceptance asks for the truncation failure path against a fake
        // server — proof that downloadFile PARSES the declared Content-Length
        // from an HTTP reply and ENFORCES it end to end (stream-socket
        // harness, no network group).
        $raw = "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\n"
            . "Content-Length: 5000\r\n\r\n" . str_repeat('p', 1200);

        [$pid, $port, $server] = $this->forkHttpServer(static fn(string $request): string => $raw);
        $command = $this->s91Command('http://127.0.0.1:' . $port . '/phpdup.phar');
        $dest = $this->scratchFile('torn-over-the-wire.bin');
        $io = $this->style($buffer);
        try {
            $ok = $command->download('http://127.0.0.1:' . $port . '/phpdup.phar', $dest, $io);
            $messages = self::flatten($buffer->fetch());
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }

        $this->assertFalse($ok, 'a body shorter than its declared Content-Length is never a completed download');
        $this->assertStringContainsString('Download incomplete', $messages);
        $this->assertStringContainsString('expected 5,000 bytes, received 1,200', $messages);
        $this->assertSame(1200, strlen((string)file_get_contents($dest)), 'torn residue exists for the caller to unlink — success was not claimed');
    }

    /**
     * once-S91 r1 LOW-1: the 206/Content-Range refusal is a two-term OR, so
     * each term gets a fixture that carries ONLY that signal. One fixture
     * with both signals lets a refactor drop either leg and stay green; with
     * the split, removing the status term reddens the 206-without-header case
     * (and the header phrase the both-signals case's message assertion
     * expects), and removing the header term reddens the 200-laundering case.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function partialContentReplyCases(): array
    {
        return [
            // The realistic laundering shape: a range slice served behind a
            // plain 200 with an honest length — only the header term can see it.
            'range slice laundered behind a plain 200' => [
                "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\n"
                . "Content-Length: 1200\r\nContent-Range: bytes 0-1199/5000\r\n\r\n" . str_repeat('r', 1200),
                'a Content-Range header',
                'partial-200-with-range-header.bin',
            ],
            // The status term alone: a bare 206 with an honest range length
            // and no Content-Range header to lean on.
            'bare 206 with no Content-Range header' => [
                "HTTP/1.1 206 Partial Content\r\nContent-Type: application/octet-stream\r\n"
                . "Content-Length: 1200\r\n\r\n" . str_repeat('r', 1200),
                'HTTP 206',
                'partial-206-without-range-header.bin',
            ],
            // The original both-signals fixture, kept for its refusal-message
            // precedence pin (a surviving 206 must be named HTTP 206).
            '206 with an honest-length Content-Range slice' => [
                "HTTP/1.1 206 Partial Content\r\nContent-Type: application/octet-stream\r\n"
                . "Content-Length: 1200\r\nContent-Range: bytes 0-1199/5000\r\n\r\n" . str_repeat('r', 1200),
                'HTTP 206',
                'partial-over-the-wire.bin',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('partialContentReplyCases')]
    public function testPartialContentReplyIsRefusedEvenWithAHonestLength(
        string $raw,
        string $expectedTrigger,
        string $destName,
    ): void {
        // The declared-length trap in its lying form: a partial reply whose
        // Content-Length matches the served RANGE completes a slice of the
        // artifact. This client never sends a Range request, so the partial
        // reply is refused on sight — before the destination file is even
        // opened (asserted by the file never existing).
        [$pid, $port, $server] = $this->forkHttpServer(static fn(string $request): string => $raw);
        try {
            $command = $this->s91Command('http://127.0.0.1:' . $port . '/phpdup.phar');
            $dest = $this->scratchFile($destName);
            $io = $this->style($buffer);

            $ok = $command->download('http://127.0.0.1:' . $port . '/phpdup.phar', $dest, $io);
            $messages = self::flatten($buffer->fetch());
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }

        $this->assertFalse($ok, 'a partial-content reply must never count as the whole artifact');
        $this->assertStringContainsString('Download refused', $messages);
        $this->assertStringContainsString($expectedTrigger, $messages);
        $this->assertStringContainsString('partial content', $messages);
        $this->assertFileDoesNotExist($dest, 'refusal happens before any bytes are written to the destination');
    }

    public function testCompleteTransferOverTheWireMatchesDeclaredLength(): void
    {
        // Positive control for the same leg: an honest reply transfers whole.
        $body = str_repeat('q', 3000);
        $raw = "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;

        [$pid, $port, $server] = $this->forkHttpServer(static fn(string $request): string => $raw);
        try {
            $command = $this->s91Command('http://127.0.0.1:' . $port . '/phpdup.phar');
            $dest = $this->scratchFile('whole-over-the-wire.bin');
            $io = $this->style($buffer);

            $ok = $command->download('http://127.0.0.1:' . $port . '/phpdup.phar', $dest, $io);
            $messages = $buffer->fetch();
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }

        $this->assertTrue($ok);
        $this->assertSame($body, (string)file_get_contents($dest));
        $this->assertStringNotContainsString('Download incomplete', $messages);
        $this->assertStringNotContainsString('Download refused', $messages);
    }

    // ------------------------------------------------------------------
    // once-S91 — the previous phar outlives the post-install check
    // ------------------------------------------------------------------

    public function testMissingPharAssetNamesTheTagAndRefuses(): void
    {
        // once-S12 r1 LOW-1 (delivered to this step as a banked leg): the
        // $pharUrl === null arm had no positive pin anywhere. A release
        // payload WITHOUT a phpdup.phar asset must exit 1 and name the tag
        // it looked in — the interpolation is the promise, so it is locked.
        $artifact = $this->makeArtifact();
        $command = $this->s05Command($artifact, null, null);
        $command->releasePayload['assets'] = [];   // tag present, phar absent

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([]), $output);
        $flat = self::flatten($output->fetch());

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not found in release ' . self::TAG, $flat, 'the refusal must interpolate the real tag');
        $this->assertStringContainsString('phpdup.phar', $flat);
        $this->assertStringContainsString('A tag published outside that workflow carries no assets.', $flat, 'the once-S12 workflow-truth sentence is part of the promise');
        $this->assertStringNotContainsString('phpdup release', $flat, 'the purged phantom command must never come back');
        $this->assertStringEqualsFile($this->fakePharPath(), 'OLD-PHAR-CONTENT', 'a missing asset refuses before any transfer');
        $this->assertSame([], $this->scratchGlob('*.tmp.*'));
    }

    public function testPostInstallCheckFailureRestoresThePreviousPhar(): void
    {
        // The finding's inverted order: the swap used to delete the backup
        // the instant the rename succeeded — whatever the installed bytes
        // were, which made "rollback is automatic" a promise the code only
        // kept for half the failure shapes. Now a failed post-install check
        // puts the previous binary back, and the reason is on the output.
        $artifact = $this->makeArtifact();
        [$manifest, $sig] = $this->makeSignedManifestPair($artifact, self::TAG, self::RAW_KEY);
        $command = $this->s05Command($artifact, $manifest, $sig);
        $command->keyOverride = self::RAW_KEY;
        $command->smokeFailure = 'simulated post-install failure (once-S91 pin)';

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([]), $output);
        $flat = self::flatten($output->fetch());

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('failed its post-install check', $flat);
        $this->assertStringContainsString('simulated post-install failure (once-S91 pin)', $flat, 'the reason must reach the user, not only the exit code');
        $this->assertStringContainsString('previous phar was restored', $flat);
        $this->assertStringEqualsFile($this->fakePharPath(), 'OLD-PHAR-CONTENT', 'a bad install must not replace the working binary');
        $this->assertSame([], $this->scratchGlob('*.backup.*'), 'the backup is consumed by the restore, not left behind');
        $this->assertSame([], $this->scratchGlob('*.tmp.*'));
    }

    public function testGoodInstallDiscardsTheBackupOnlyAfterTheCheckPasses(): void
    {
        // Positive control on the same ordering: the REAL smoke (a re-hash at
        // the final path against the manifest pin) passes, so the backup is
        // gone and the new bytes stand. A smokeInstalledPhar() that always
        // refused would fail this and pass the test above only by luck of
        // steering — see the primitive pin below for the other direction.
        $artifact = $this->makeArtifact();
        [$manifest, $sig] = $this->makeSignedManifestPair($artifact, self::TAG, self::RAW_KEY);
        $command = $this->s05Command($artifact, $manifest, $sig);
        $command->keyOverride = self::RAW_KEY;

        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput([]), $output);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Post-install check passed', self::flatten($output->fetch()));
        $this->assertStringEqualsFile($this->fakePharPath(), (string)file_get_contents($artifact));
        $this->assertSame([], $this->scratchGlob('*.backup.*'));
        $this->assertSame([], $this->scratchGlob('*.tmp.*'));
    }

    public function testSmokeArmRefusesBytesThatDriftedAtTheFinalPath(): void
    {
        // The primitive itself (no steering): sound bytes pass; bytes that no
        // longer hash to the manifest pin, an empty file, and a missing file
        // each refuse with their own reason. Vacuity guard for the ordering
        // legs above — a smokeInstalledPhar() that only ever returned null
        // would pass them all.
        $path = $this->scratchFile('smoke.phar');
        file_put_contents($path, 'SMOKE-BYTES');
        $hash = hash_file('sha256', $path);
        $this->assertIsString($hash);

        $command = new S91UpdateCommand();
        $this->assertNull($command->smokePublic($path, $hash), 'sound bytes at the final path must pass their own check');
        $this->assertStringContainsString('SHA-256', (string)$command->smokePublic($path, str_repeat('0', 64)), 'a drifted pin must refuse');

        $empty = $this->scratchFile('smoke-empty.phar');
        file_put_contents($empty, '');
        $this->assertStringContainsString('empty', (string)$command->smokePublic($empty, null), 'a zero-byte install must refuse even without a pin (dev-unsigned leg)');

        $this->assertStringContainsString('not readable', (string)$command->smokePublic($this->scratchFile('never-created.phar'), null));
    }

    // -- helpers ---------------------------------------------------------

    private function scratchDir(): string
    {
        if ($this->s36Scratch === null) {
            $this->s36Scratch = sys_get_temp_dir() . '/phpdup-s36-' . bin2hex(random_bytes(6));
            mkdir($this->s36Scratch, 0700);
        }
        return $this->s36Scratch;
    }

    private function scratchFile(string $name): string
    {
        return $this->scratchDir() . '/' . $name;
    }

    /** @return list<string> */
    private function scratchGlob(string $pattern): array
    {
        return glob($this->scratchDir() . '/' . $pattern) ?: [];
    }

    /** @return resource */
    private function tempStream(string $contents)
    {
        $h = fopen('php://temp', 'r+b');
        $this->assertIsResource($h);
        $this->assertGreaterThan(0, fwrite($h, $contents));
        rewind($h);
        return $h;
    }

    /** @return resource */
    private function openOut(string $path)
    {
        $h = fopen($path, 'wb');
        $this->assertIsResource($h);
        return $h;
    }

    private function style(BufferedOutput &$buffer = null): SymfonyStyle
    {
        $buffer ??= new BufferedOutput();
        return new SymfonyStyle(new ArrayInput([]), $buffer);
    }

    private function fakePharPath(): string
    {
        return $this->scratchFile('phpdup-running.phar');
    }

    // -- once-S05 fixture builders ----------------------------------------

    private function makeArtifact(string $name = 'remote.phar'): string
    {
        $path = $this->scratchFile($name);
        file_put_contents($path, 'S05-PHAR-BYTES-' . str_repeat('q', 5000));
        return $path;
    }

    /**
     * Build + sign a manifest for the artifact, byte-for-byte the release.yml
     * formula: JSON envelope (schema 1, tag, file, sha256) and
     * hash_hmac_file over the manifest FILE bytes with the RAW key string.
     *
     * $fieldOverrides (fix r1 M-1; replaces the previously-unused raw
     * $shaOverride knob) lets one builder mint MALFORMED envelopes — e.g.
     * ['schema' => 2] or ['sha256' => 'nothex'] — still signed so the HMAC
     * leg passes and validateManifest arms are what refuses.
     *
     * @param array<string, mixed>|null $fieldOverrides
     * @return array{0: string, 1: string} [manifestPath, signaturePath]
     */
    private function makeSignedManifestPair(string $artifactPath, string $tag, string $key, ?array $fieldOverrides = null): array
    {
        $manifest = $this->scratchFile(($fieldOverrides === null ? '' : 'malformed-') . $tag . '.release.json');
        $sha = hash_file('sha256', $artifactPath);
        if ($sha === false) {
            $this->fail('cannot hash artifact');
        }
        file_put_contents($manifest, json_encode(array_merge([
            'schema' => 1,
            'tag'    => $tag,
            'file'   => 'phpdup.phar',
            'sha256' => $sha,
        ], $fieldOverrides ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $sig = $this->scratchFile($tag . '.release.json.sig');
        file_put_contents($sig, hash_hmac_file('sha256', $manifest, $key));
        return [$manifest, $sig];
    }

    private function s05Command(
        string $artifactPath,
        ?string $manifestPath,
        ?string $sigPath,
        string $tag = self::TAG,
    ): S05UpdateCommand {
        file_put_contents($this->fakePharPath(), 'OLD-PHAR-CONTENT');

        $command = new S05UpdateCommand();
        $command->fakePharPath = $this->fakePharPath();

        $assets = [['name' => 'phpdup.phar', 'browser_download_url' => 'file://' . $artifactPath]];
        if ($manifestPath !== null) {
            $command->manifestUrl = 'file://' . $manifestPath;
            $assets[] = ['name' => 'phpdup.release.json', 'browser_download_url' => $command->manifestUrl];
        }
        if ($sigPath !== null) {
            $assets[] = ['name' => 'phpdup.release.json.sig', 'browser_download_url' => 'file://' . $sigPath];
        }
        $command->releasePayload = [
            'tag_name'     => $tag,
            'prerelease'   => false,
            'draft'        => false,
            'published_at' => '2026-01-01T00:00:00Z',
            'assets'       => $assets,
        ];

        $app = new Application('phpdup', '0.0.0');
        $app->add($command);
        return $command;
    }

    // -- shell-S36 fixture builder -----------------------------------------

    private function s36Command(bool $failPharDownload = false, bool $failSigDownload = false): TestUpdateCommand
    {
        $pharSrc = $this->scratchFile('remote.phar');
        $sigSrc = $this->scratchFile('remote.phar.sig');
        $manifestSrc = $this->scratchFile('remote.release.json');
        $pharBytes = 'NEW-PHAR-BYTES-' . str_repeat('z', 70000);
        file_put_contents($pharSrc, $pharBytes);
        // once-S05 asset model: a well-formed manifest (its sha256 matches
        // the served bytes — validateManifest + the hash pin are REAL in
        // this leg too) and a 64-hex signature file (the format guard is
        // real; the HMAC check itself stays mocked via the seam).
        file_put_contents($manifestSrc, json_encode([
            'schema' => 1,
            'tag'    => 'v9.9.9-s36',
            'file'   => 'phpdup.phar',
            'sha256' => hash('sha256', $pharBytes),
        ], JSON_PRETTY_PRINT) . "\n");
        file_put_contents($sigSrc, str_repeat('ab', 32));
        file_put_contents($this->fakePharPath(), 'OLD-PHAR-CONTENT');

        $command = new TestUpdateCommand(TestUpdateCommand::MODE_S36);
        $command->fakePharPath = $this->fakePharPath();
        $command->pharUrl = 'file://' . $pharSrc;
        $command->sigUrl = 'file://' . $sigSrc;
        $command->failPharDownload = $failPharDownload;
        $command->failSigDownload = $failSigDownload;
        $command->releasePayload = [
            'tag_name'     => 'v9.9.9-s36',
            'prerelease'   => false,
            'draft'        => false,
            'published_at' => '2026-01-01T00:00:00Z',
            'assets'       => [
                ['name' => 'phpdup.phar', 'browser_download_url' => $command->pharUrl],
                ['name' => 'phpdup.release.json', 'browser_download_url' => 'file://' . $manifestSrc],
                ['name' => 'phpdup.release.json.sig', 'browser_download_url' => $command->sigUrl],
            ],
        ];

        $app = new Application('phpdup', '0.0.0');
        $app->add($command);
        return $command;
    }

    /** @return resource */
    private static function openWrapper(string $url)
    {
        if (!in_array('s36-false-read', stream_get_wrappers(), true)) {
            stream_wrapper_register('s36-false-read', S36FalseReadStream::class);
        }
        if (!in_array('s36-stalled-read', stream_get_wrappers(), true)) {
            stream_wrapper_register('s36-stalled-read', S36StalledReadStream::class);
        }
        $h = fopen($url, 'rb');
        if ($h === false) {
            throw new \RuntimeException('cannot open test wrapper ' . $url);
        }
        return $h;
    }

    /**
     * Write-mode counterpart of openWrapper() for sinks that steer the
     * destination handle itself (the failing-flush seam).
     *
     * @return resource
     */
    private static function openWriteWrapper(string $url)
    {
        if (!in_array('s36-failing-flush', stream_get_wrappers(), true)) {
            stream_wrapper_register('s36-failing-flush', S36FailingFlushStream::class);
        }
        $h = fopen($url, 'wb');
        if ($h === false) {
            throw new \RuntimeException('cannot open test write wrapper ' . $url);
        }
        return $h;
    }

    // -- once-S91 harness -------------------------------------------------

    /**
     * A well-formed release payload pointing every asset at a local path —
     * served over the fake server so the REAL transport reads it off a
     * socket instead of a substituted seam.
     */
    private static function releasePayloadJson(string $pharUrl, string $manifestPath, string $sigPath): string
    {
        return (string)json_encode([
            'tag_name'     => self::TAG,
            'prerelease'   => false,
            'draft'        => false,
            'published_at' => '2026-01-01T00:00:00Z',
            'assets'       => [
                ['name' => 'phpdup.phar', 'browser_download_url' => $pharUrl],
                ['name' => 'phpdup.release.json', 'browser_download_url' => 'file://' . $manifestPath],
                ['name' => 'phpdup.release.json.sig', 'browser_download_url' => 'file://' . $sigPath],
            ],
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build a wire reply with an honest Content-Length for the given body
     * (pass the raw string yourself when the case needs a LYING length —
     * that is the truncation pin's whole point).
     *
     * @param array<string, string> $headers
     */
    private static function httpReply(string $statusLine, array $headers, string $body): string
    {
        $lines = ['HTTP/1.1 ' . $statusLine];
        $headers['Content-Length'] = (string)strlen($body);
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        return implode("\r\n", $lines) . "\r\n\r\n" . $body;
    }

    /**
     * SymfonyStyle wraps error blocks at the terminal width; flatten the
     * whitespace so a promise can be pinned as a whole sentence (the words
     * are the contract, the line breaks are decoration).
     */
    private static function flatten(string $messages): string
    {
        return (string)preg_replace('/\s+/', ' ', $messages);
    }

    /** @param string $apiUrl loopback fake-server URL the REAL transport dials */
    private function s91Command(string $apiUrl): S91UpdateCommand
    {
        file_put_contents($this->fakePharPath(), 'OLD-PHAR-CONTENT');

        $command = new S91UpdateCommand();
        $command->fakePharPath = $this->fakePharPath();
        $command->apiUrl       = $apiUrl;

        $app = new Application('phpdup', '0.0.0');
        $app->add($command);
        return $command;
    }

    /**
     * Forked one-shot loopback HTTP server — the house harness, mirrored
     * from tests/Unit/Util/HttpTest.php (ephemeral port, poll-until-deadline
     * reaping, no fixed sleeps, no resolver traffic, no external network).
     *
     * @param callable(string): string $responseFor
     * @return array{0: int, 1: int, 2: resource} [pid, port, listener]
     */
    private function forkHttpServer(callable $responseFor, int $acceptTimeoutSec = 5, int $serveCount = 1): array
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('forked HTTP server requires pcntl_fork/pcntl_waitpid');
        }
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('forked HTTP server reaper requires posix_kill');
        }
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped("loopback bind unavailable: $errstr");
        }
        $name = (string)stream_socket_get_name($server, false);
        $port = (int)substr($name, (int)strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port);

        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($server);
            $this->markTestSkipped('pcntl_fork failed');
        }
        if ($pid === 0) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $served = 0;
            for ($i = 0; $i < $serveCount; $i++) {
                $conn = @stream_socket_accept($server, $acceptTimeoutSec);
                if ($conn === false) {
                    break;
                }
                $served++;
                stream_set_timeout($conn, $acceptTimeoutSec);
                $request = '';
                while (!str_contains($request, "\r\n\r\n")) {
                    $chunk = fread($conn, 8192);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $request .= $chunk;
                }
                if (preg_match('/^Content-Length: (\d+)$/mi', $request, $m) === 1) {
                    $remaining = (int)$m[1];
                    while ($remaining > 0) {
                        $chunk = fread($conn, min(8192, $remaining));
                        if ($chunk === false || $chunk === '') {
                            break;
                        }
                        $remaining -= strlen($chunk);
                    }
                }
                @fwrite($conn, $responseFor($request));
                fclose($conn);
            }
            fclose($server);
            exit($served > 0 ? 0 : 1);
        }
        return [$pid, $port, $server];
    }

    /**
     * Bounded reap (poll-until-deadline, house discipline): the child
     * finishes by serving or by accept-timeout. Anything else is a hung
     * transport — kill and fail loudly.
     */
    private function reapForkedHttpServer(int $pid): void
    {
        $status   = 0;
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline) {
            $reaped = pcntl_waitpid($pid, $status, WNOHANG);
            if ($reaped === $pid) {
                return;
            }
            if ($reaped === -1) {
                $this->fail("pcntl_waitpid failed for forked server $pid");
            }
            usleep(20_000);
        }
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
        $this->fail("forked HTTP server $pid never finished — client stalled or never connected");
    }
}

/**
 * once-S91 harness: drives the REAL metadata transport (fetchJson) and the
 * REAL download leg against the forked loopback server above. Only the
 * environment seams are steered — phar path, API URL, verification key,
 * GITHUB_TOKEN. Nothing here dials anything but 127.0.0.1.
 */
class S91UpdateCommand extends UpdateCommand
{
    public string $fakePharPath = '';

    public string $apiUrl = '';

    public ?string $keyOverride = null;

    public string $tokenValue = '';

    /** Public pass-through so the wire legs can drive the real downloadFile. */
    public function download(string $url, string $dest, SymfonyStyle $io): bool
    {
        return $this->downloadFile($url, $dest, $io);
    }

    /** Public pass-through for the post-install check primitive. */
    public function smokePublic(string $pharPath, ?string $pinnedSha256): ?string
    {
        return $this->smokeInstalledPhar($pharPath, $pinnedSha256);
    }

    #[\Override]
    protected function releasesApiUrl(): string
    {
        return $this->apiUrl;
    }

    #[\Override]
    protected function runningPharPath(): string
    {
        return $this->fakePharPath;
    }

    #[\Override]
    protected function runningVersion(): string
    {
        return '0.0.0';
    }

    #[\Override]
    protected function githubToken(): string
    {
        return $this->tokenValue;
    }

    #[\Override]
    protected function resolveVerificationKey(): string
    {
        return $this->keyOverride ?? parent::resolveVerificationKey();
    }
}

/**
 * once-S05 harness: real UpdateCommand::execute() + real verifySignature
 * over mock file:// transport; only the environment seams (phar path,
 * release metadata, key, manifest-download failure) are steered.
 */
class S05UpdateCommand extends UpdateCommand
{
    public string $fakePharPath = '';

    /** @var array<string, mixed> */
    public array $releasePayload = [];

    public ?string $keyOverride = null;

    public string $manifestUrl = '';

    public bool $failManifestDownload = false;

    public int $realVerifyCalls = 0;

    /**
     * once-S91: steer the post-install check's outcome without faking the
     * swap itself (the reason string flows into the honest refusal text).
     */
    public ?string $smokeFailure = null;

    public function keyIsPlaceholder(): bool
    {
        return $this->verificationKeyIsPlaceholder();
    }

    public function verifyDirect(string $payloadFile, string $expectedSig): bool
    {
        return $this->verifySignature($payloadFile, $expectedSig);
    }

    #[\Override]
    protected function runningPharPath(): string
    {
        return $this->fakePharPath;
    }

    #[\Override]
    protected function runningVersion(): string
    {
        return '0.0.0';
    }

    #[\Override]
    protected function githubToken(): string
    {
        return '';
    }

    #[\Override]
    protected function fetchJson(string $url, array $headers): array
    {
        // once-S91 struct contract: a mocked payload is a trusted HTTP 2xx
        // reply (the honest-status legs drive the REAL transport instead —
        // see S91UpdateCommand).
        return ['status' => 200, 'json' => $this->releasePayload, 'retryAfter' => null];
    }

    #[\Override]
    protected function smokeInstalledPhar(string $pharPath, ?string $pinnedSha256): ?string
    {
        return $this->smokeFailure ?? parent::smokeInstalledPhar($pharPath, $pinnedSha256);
    }

    #[\Override]
    protected function resolveVerificationKey(): string
    {
        return $this->keyOverride ?? parent::resolveVerificationKey();
    }

    #[\Override]
    protected function verifySignature(string $payloadFile, string $expectedSig): bool
    {
        $this->realVerifyCalls++;
        return parent::verifySignature($payloadFile, $expectedSig);
    }

    #[\Override]
    protected function downloadFile(string $url, string $dest, SymfonyStyle $io): bool
    {
        if ($this->failManifestDownload && $url === $this->manifestUrl) {
            return false;
        }
        return parent::downloadFile($url, $dest, $io);
    }
}

/**
 * once-S05 fix r1 (L-2) harness: a ConsoleOutputInterface double so the
 * error-stream half of the dev-unsigned banner becomes assertable. The main
 * stream is this object's own BufferedOutput buffer (SymfonyStyle writes
 * land there); getErrorOutput() returns the separate stderr buffer.
 */
class S05ConsoleOutput extends BufferedOutput implements ConsoleOutputInterface
{
    public function __construct(private OutputInterface $error)
    {
        parent::__construct();
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->error;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->error = $error;
    }

    public function section(): ConsoleSectionOutput
    {
        throw new \LogicException('section() is not modeled by the S05 console-output double');
    }
}

class TestUpdateCommand extends UpdateCommand
{
    public const MODE_S36 = 's36';

    private string $mode;

    // shell-S36 controls (only consulted in MODE_S36)
    public ?string $fakePharPath = null;

    /** @var array<string, mixed>|null */
    public ?array $releasePayload = null;
    public string $pharUrl = '';
    public string $sigUrl = '';
    public bool $failPharDownload = false;
    public bool $failSigDownload = false;
    public int $verifySignatureCalls = 0;
    public bool $verifyResult = true;

    public function __construct(string $mode)
    {
        parent::__construct();
        $this->mode = $mode;
    }

    /**
     * Public pass-through onto the download seam (pipeStream) so unit
     * tests can drive stream resources directly.
     *
     * @param resource $in
     * @param resource $out
     */
    public function pipe($in, $out, ?int $expectedBytes, SymfonyStyle $io): bool
    {
        return $this->pipeStream($in, $out, $expectedBytes, $io);
    }

    protected function verifySignature(string $pharFile, string $expectedSig): bool
    {
        if ($this->mode === self::MODE_S36) {
            $this->verifySignatureCalls++;
            return $this->verifyResult;
        }
        return parent::verifySignature($pharFile, $expectedSig);
    }

    /**
     * once-S05 asset model: execute() refuses on a placeholder key before
     * any download — the S36 legs supply a test key through this seam.
     */
    protected function resolveVerificationKey(): string
    {
        return 's36-raw-test-key';
    }

    protected function githubToken(): string
    {
        return '';
    }

    protected function runningVersion(): string
    {
        return '0.0.0';
    }

    protected function runningPharPath(): string
    {
        return $this->mode === self::MODE_S36 ? (string)$this->fakePharPath : parent::runningPharPath();
    }

    protected function fetchJson(string $url, array $headers): array
    {
        if ($this->mode === self::MODE_S36 && $this->releasePayload !== null) {
            // once-S91 struct contract: the S36 mock is a trusted 2xx reply.
            return ['status' => 200, 'json' => $this->releasePayload, 'retryAfter' => null];
        }
        return parent::fetchJson($url, $headers);
    }

    protected function downloadFile(string $url, string $dest, SymfonyStyle $io): bool
    {
        if ($this->mode === self::MODE_S36) {
            if ($this->failPharDownload && $url === $this->pharUrl) {
                // A torn transfer leaves partial bytes at $dest and the
                // caller owns cleanup (@unlink in execute()'s phar leg).
                // Writing real bytes makes the test's '*.tmp.*' glob
                // pin load-bearing: dropping that unlink surfaces the
                // residue and turns the assertion red (symmetry with the
                // sig-leg test, whose tmp phar carries real bytes).
                file_put_contents($dest, 'TORN-PARTIAL');
                return false;
            }
            if ($this->failSigDownload && $url === $this->sigUrl) {
                return false;
            }
        }
        return parent::downloadFile($url, $dest, $io);
    }
}

/**
 * User-space read stream: yields a payload, then stream_read returns false,
 * which PHP surfaces to the consumer as `fread() === false` with feof()
 * still false — the mid-read abort of php-dup-finder-shell-017.
 */
class S36FalseReadStream
{
    public static string $nextPayload = '';

    /** @var mixed */
    public $context;

    private int $pos = 0;
    private string $payload = '';

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $this->payload = self::$nextPayload;
        $this->pos = 0;
        return true;
    }

    public function stream_read($count)
    {
        if ($this->pos < strlen($this->payload)) {
            $out = substr($this->payload, $this->pos, (int)$count);
            $this->pos += strlen($out);
            return $out;
        }
        return false; // -> fread() === false
    }

    public function stream_eof()
    {
        return false; // never reaches EOF: the false read must be what fails it
    }

    public function stream_stat()
    {
        return false;
    }
}

/**
 * User-space read stream that stalls forever: fread() returns '' while
 * feof() stays false. The old loop spun on this forever; pipeStream must
 * classify it as a read failure.
 */
class S36StalledReadStream
{
    /** @var mixed */
    public $context;

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        return true;
    }

    public function stream_read($count)
    {
        return '';
    }

    public function stream_eof()
    {
        return false;
    }

    public function stream_stat()
    {
        return false;
    }
}

/**
 * User-space write-only sink (shell-S36 fix round, issue 1): every
 * stream_write is fully accepted, but stream_flush reports failure. This
 * makes fflush($handle) return false in userland — the only reachable
 * seam for the flush/close guard in pipeStream, whose fclose arm is not
 * provable (fclose returns true on a wrapper stream even when
 * stream_close fails).
 */
class S36FailingFlushStream
{
    /** @var mixed */
    public $context;

    /** Write-mode wrappers require a public data property; unused sink. @var string */
    public $data = '';

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $this->data = '';
        return true;
    }

    public function stream_write($data)
    {
        $this->data .= $data;
        return strlen($data); // full acceptance: the failure is flush-time
    }

    public function stream_flush()
    {
        return false; // -> fflush() === false
    }

    public function stream_close()
    {
        // Best-effort cleanup; its return value does not reach fclose().
    }
}
