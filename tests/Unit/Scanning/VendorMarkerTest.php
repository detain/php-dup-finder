<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Scanning;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Scanning\VendorMarker;
use Phpdup\Util\LineRange;

/**
 * Item 5.2 (W2b) — the vendor signal. Three legs (nested vendor dir,
 * composer.lock package-name path, known upstream header), per-root
 * scoping, fail-open notes, and the run-context overwrite law that
 * keeps Block::$vendored honest across cache replay (the RepoLabels
 * posture pinned here; the P1 Hole safe-direction precedent beside it).
 */
final class VendorMarkerTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpdup_vendor_' . uniqid('t', true);
        mkdir($this->tmp, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    // ------------------------------------------------------------- leg 1: vendor dir

    public function testNestedVendorDirSegmentMarksAtAnyDepth(): void
    {
        $marker = new VendorMarker([]);

        // The doc's opening case: scripts/bots/<bot>/vendor/...
        $deep = '/srv/app/scripts/bots/MonitorLizzy/vendor/detain/pkg/y.php';
        self::assertSame(VendorMarker::SIGNAL_VENDOR_DIR, $marker->describe($deep)['signal']);

        // One-segment nesting still marks.
        self::assertSame(
            VendorMarker::SIGNAL_VENDOR_DIR,
            $marker->describe('/srv/app/vendor/pkg/z.php')['signal'],
        );

        // Case-insensitive dir segment (composer copies travel both ways).
        self::assertSame(
            VendorMarker::SIGNAL_VENDOR_DIR,
            $marker->describe('/srv/app/Include/Vendor/pkg/z.php')['signal'],
        );
    }

    public function testBasenameVendorCollisionNeverMarks(): void
    {
        $marker = new VendorMarker([]);

        // A first-party CLASS named Vendor.php (or a dir named vendors/)
        // is not a vendored copy: only exact dir segments count.
        self::assertSame('', $marker->describe('/srv/app/src/Vendor.php')['signal']);
        self::assertSame('', $marker->describe('/srv/app/vendors/tool/w.php')['signal']);
    }

    // ------------------------------------------------------------- leg 2: composer.lock

    public function testLockShortNameDirLeg(): void
    {
        $root = $this->tmp . '/appA';
        $this->writeLock($root, ['eusebiob/nusoap']);

        $marker = new VendorMarker([$root]);
        $verdict = $marker->describe($root . '/include/nusoap/nusoaplib.php');

        self::assertSame(VendorMarker::SIGNAL_LOCK_PATH, $verdict['signal']);
        self::assertSame('eusebiob/nusoap', $verdict['detail'], 'detail names the declared package');
    }

    public function testLockFullNameAdjacencyLeg(): void
    {
        $root = $this->tmp . '/appA';
        $this->writeLock($root, ['Eusebiob/NuSOAP']); // case-folded at load

        $marker = new VendorMarker([$root]);

        // Copy keeps the vendor/ns dir shape (custom installers do this).
        self::assertSame(
            ['signal' => VendorMarker::SIGNAL_LOCK_PATH, 'detail' => 'eusebiob/nusoap'],
            $marker->describe($root . '/lib/eusebiob/nusoap/x.php'),
        );
    }

    public function testLockLegIsScopedToItsDeclaringRoot(): void
    {
        $appA = $this->tmp . '/appA';
        $appB = $this->tmp . '/appB';
        $this->writeLock($appA, ['eusebiob/nusoap']);
        mkdir($appB . '/include/nusoap', 0o775, true); // same dir shape, no lock

        $marker = new VendorMarker([$appA, $appB]);

        self::assertSame(VendorMarker::SIGNAL_LOCK_PATH, $marker->describe($appA . '/include/nusoap/a.php')['signal']);
        self::assertSame(
            '',
            $marker->describe($appB . '/include/nusoap/a.php')['signal'],
            'a package declared by one root says nothing about another root\'s tree',
        );
    }

    public function testFileNamedLikeAPackageWithoutADirCollisionDoesNotMark(): void
    {
        $root = $this->tmp . '/appA';
        $this->writeLock($root, ['eusebiob/nusoap']);

        $marker = new VendorMarker([$root]);

        // src/nusoap.php: a hand-written file sharing the short name is
        // NOT the copy archetype (the doc's cases are package trees).
        self::assertSame('', $marker->describe($root . '/src/nusoap.php')['signal']);
    }

    public function testRequireDevLaneCountsToo(): void
    {
        $root = $this->tmp . '/appA';
        $this->writeLock($root, [], ['phpmailer/phpmailer']);

        $marker = new VendorMarker([$root]);
        self::assertSame(
            ['signal' => VendorMarker::SIGNAL_LOCK_PATH, 'detail' => 'phpmailer/phpmailer'],
            $marker->describe($root . '/include/phpmailer/send.php'),
        );
    }

    public function testCorruptLockFailsOpenWithOneNote(): void
    {
        $root = $this->tmp . '/appA';
        mkdir($root, 0o775, true);
        file_put_contents($root . '/composer.lock', '{not json at all');

        $marker = new VendorMarker([$root]);
        self::assertSame('', $marker->describe($root . '/include/nusoap/x.php')['signal'], 'no lock leg without a parsed lock');
        self::assertCount(1, $marker->notes(), 'fail-open, never silent');
        self::assertStringContainsString('corrupt composer.lock', $marker->notes()[0]);

        // Missing locks are not an event.
        $clean = new VendorMarker([$this->tmp . '/no-such-root']);
        self::assertSame([], $clean->notes());
    }

    // ------------------------------------------------------------- leg 3: headers

    public function testKnownUpstreamHeaderMarksOutsideVendorAndLock(): void
    {
        $root = $this->tmp . '/appB';
        mkdir($root . '/lib/wm', 0o775, true);
        $copy = $root . '/lib/wm/f3a.php';
        file_put_contents($copy, "<?php\n/*\n * This file is part of the workerman framework, released under MIT.\n */\nfunction f(): int { return 1; }\n");

        $marker = new VendorMarker([$root]); // no lock here
        $verdict = $marker->describe($copy);

        self::assertSame(VendorMarker::SIGNAL_HEADER, $verdict['signal']);
        self::assertSame('workerman', $verdict['detail']);
    }

    public function testFirstPartyReferenceOfPackageNameDoesNotMark(): void
    {
        $root = $this->tmp . '/appB';
        mkdir($root . '/src', 0o775, true);
        $firstParty = $root . '/src/Mailer.php';
        // Merely USES the package — the header anchors must not fire.
        file_put_contents($firstParty, "<?php\nuse PHPMailer\\PHPMailer\\PHPMailer;\nclass Mailer { public function go(): void {} }\n");

        $marker = new VendorMarker([$root]);
        self::assertSame('', $marker->describe($firstParty)['signal']);
    }

    public function testHeaderBeyondThePeekBoundDoesNotMark(): void
    {
        $root = $this->tmp . '/appB';
        mkdir($root, 0o775, true);
        $file = $root . '/deep.php';
        file_put_contents($file, "<?php\n// " . str_repeat('x', 5000) . "\n// NuSOAP - Web Services Toolkit for PHP\n");

        $marker = new VendorMarker([$root]);
        self::assertSame('', $marker->describe($file)['signal'], 'the header leg is bounded to the file head');
    }

    public function testUnreadableFileFailsOpenToNoSignal(): void
    {
        $marker = new VendorMarker([]);
        self::assertSame('', $marker->describe('/definitely/not/on/disk/x.php')['signal']);
    }

    public function testUnreadableLockFailsOpenWithNote(): void
    {
        // m-1 (r1): the IO-error arm, pinned beside its parse-error twin.
        // The suite runs as a non-root uid (setUp would skip otherwise:
        // root reads 0o000 files), so the chmod gate genuinely bites.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('chmod-000 is not a gate for root');
        }
        $root = $this->tmp . '/appLockIO';
        mkdir($root, 0o775, true);
        $lock = $root . '/composer.lock';
        file_put_contents($lock, (string) json_encode([
            'packages'     => [['name' => 'someone/mail', 'version' => '1.0.0']],
            'packages-dev' => [],
        ]));
        chmod($lock, 0o000);
        try {
            $marker = new VendorMarker([$root]);
            self::assertCount(1, $marker->notes());
            self::assertStringContainsString('unreadable composer.lock', $marker->notes()[0]);
            self::assertSame(
                '',
                $marker->describe($root . '/include/mail/x.php')['signal'],
                'an unreadable lock disables the leg, it never guesses',
            );
        } finally {
            chmod($lock, 0o644); // keep tearDown unlink happy
        }
    }

    // ------------------------------------------------------- M-1 (r1): root-boundary law

    public function testCheckoutDirectoryNameNeverSpellsAPackage(): void
    {
        // The vacuous-gate hazard: a project checked out INTO a dir
        // named like a declared package short name (~/www/mail with a
        // lock declaring someone/mail) must NOT be all-marked. Leg 2
        // sees only segments BELOW the scan-root boundary.
        $root = $this->tmp . '/www/mail';
        $this->writeLock($root, ['someone/mail']);

        $marker = new VendorMarker([$root]);

        // Before the r1 cure these BOTH returned composer_lock_path.
        self::assertSame('', $marker->describe($root . '/src/Mailer.php')['signal']);
        self::assertSame('', $marker->describe($root . '/src/Order.php')['signal']);

        // The doc archetype under the root still marks — the cure
        // costs the leg zero real detections.
        self::assertSame(
            ['signal' => VendorMarker::SIGNAL_LOCK_PATH, 'detail' => 'someone/mail'],
            $marker->describe($root . '/include/mail/nusoapish.php'),
        );

        // And a real nested vendor tree under a colliding root still
        // marks via leg 1 (full-path law unchanged).
        self::assertSame(
            VendorMarker::SIGNAL_VENDOR_DIR,
            $marker->describe($root . '/scripts/bot1/vendor/pkg/tool.php')['signal'],
        );
    }

    public function testAncestorSegmentsOutsideAnyRootNeverMark(): void
    {
        // /home/user/cache with a 'doctrine/cache'-style declaration:
        // the run's roots are disjoint from the collision path.
        $root = $this->tmp . '/proj';
        $this->writeLock($root, ['doctrine/cache']);

        $marker = new VendorMarker([$root]);
        self::assertSame('', $marker->describe('/home/user/cache/lib/Router.php')['signal']);
    }

    // ------------------------------------------------------------- stamping law

    public function testStampOverwritesStaleBlobMarks(): void
    {
        $root = $this->tmp . '/appB';
        mkdir($root . '/src', 0o775, true);
        $file = $root . '/src/Clean.php';
        file_put_contents($file, "<?php\nclass Clean {}\n");

        $block = new Block($file, new LineRange(1, 4), 'class', null, null, null, new Node\Stmt\Nop());

        // A deserialized blob may carry a stale TRUE (whole-object
        // serialization). The restamp from THIS run's roots governs.
        $block->vendored = true;
        (new VendorMarker([$root]))->stamp($block);
        self::assertFalse($block->vendored, 'a stale mark never survives a restamp — run context law');

        // And a stale FALSE cannot hide a real copy either.
        $copy = $root . '/vendor/acme/pkg/old.php';
        $block2 = new Block($copy, new LineRange(1, 4), 'function', null, null, null, new Node\Stmt\Nop());
        $block2->vendored = false;
        (new VendorMarker([$root]))->stamp($block2);
        self::assertTrue($block2->vendored, 'the restamp also repairs marks a saving run missed');
    }

    public function testFieldDefaultIsFalseAndRoundTrips(): void
    {
        // The serialization direction pin (P1 Hole precedent): the field
        // survives a whole-object round trip (so the blob is honest),
        // while the DEFAULT stays false (so legacy blobs restore inert).
        self::assertFalse((new \ReflectionProperty(Block::class, 'vendored'))->getDefaultValue());

        $root = $this->tmp . '/appB';
        $block = new Block($root . '/vendor/x/y.php', new LineRange(2, 9), 'function', null, null, null, new Node\Stmt\Nop());
        (new VendorMarker([$root]))->stamp($block);
        /** @var Block $back */
        $back = unserialize(serialize($block));
        self::assertTrue($back->vendored, 'serialize carries the raw value — the RESTAMP, not the codec, enforces the run-context law');
    }

    public function testDescribeLabelCarriesDetail(): void
    {
        $root = $this->tmp . '/appA';
        $this->writeLock($root, ['eusebiob/nusoap']);
        $marker = new VendorMarker([$root]);

        self::assertSame('composer_lock_path:eusebiob/nusoap', $marker->describeLabel($root . '/include/nusoap/a.php'));
        self::assertSame('vendor_dir', $marker->describeLabel('/anything/vendor/pkg/a.php'));
        self::assertSame('', $marker->describeLabel('/anything/src/A.php'));
    }

    // ------------------------------------------------------------------ helpers

    /** @param list<string> $packages @param list<string> $dev */
    private function writeLock(string $root, array $packages, array $dev = []): void
    {
        mkdir($root, 0o775, true);
        $encode = static fn(array $names): array => array_map(
            static fn(string $n): array => ['name' => $n, 'version' => '1.0.0'],
            $names,
        );
        file_put_contents($root . '/composer.lock', (string) json_encode([
            'packages'     => $encode($packages),
            'packages-dev' => $encode($dev),
        ]));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
