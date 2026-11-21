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
    protected function fetchJson(string $url, array $headers): ?array
    {
        return $this->releasePayload;
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

    protected function fetchJson(string $url, array $headers): ?array
    {
        if ($this->mode === self::MODE_S36 && $this->releasePayload !== null) {
            return $this->releasePayload;
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
