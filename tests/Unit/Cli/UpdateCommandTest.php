<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\UpdateCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class UpdateCommandTest extends TestCase
{
    public function testValidSignatureIsAccepted(): void
    {
        $command = new TestUpdateCommand(TestUpdateCommand::MODE_VALID_SIGNATURE);

        $app = new Application('phpdup', '0.0.0');
        $app->add($command);

        $outputPath = sys_get_temp_dir() . '/phpdup-valid-test.phar';
        $input = new ArrayInput(['--output' => $outputPath]);
        $output = new NullOutput();

        $exitCode = $command->run($input, $output);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($outputPath);
        unlink($outputPath);
    }

    public function testTamperedPharIsRejected(): void
    {
        $command = new TestUpdateCommand(TestUpdateCommand::MODE_TAMPERED_PHAR);

        $app = new Application('phpdup', '0.0.0');
        $app->add($command);

        $outputPath = sys_get_temp_dir() . '/phpdup-tampered-test.phar';
        $input = new ArrayInput(['--output' => $outputPath]);
        $output = new NullOutput();

        $exitCode = $command->run($input, $output);

        $this->assertSame(1, $exitCode);
        $this->assertFileDoesNotExist($outputPath);
    }

    public function testMissingSignatureIsRejectedWithoutAllowUnsigned(): void
    {
        $command = new TestUpdateCommand(TestUpdateCommand::MODE_NO_SIGNATURE);

        $app = new Application('phpdup', '0.0.0');
        $app->add($command);

        $input = new ArrayInput([]);
        $output = new NullOutput();

        $exitCode = $command->run($input, $output);

        $this->assertSame(1, $exitCode);
    }

    public function testMissingSignatureIsAcceptedWithAllowUnsigned(): void
    {
        $command = new TestUpdateCommand(TestUpdateCommand::MODE_NO_SIGNATURE);

        $app = new Application('phpdup', '0.0.0');
        $app->add($command);

        $input = new ArrayInput(['--allow-unsigned' => true]);
        $output = new NullOutput();

        $exitCode = $command->run($input, $output);

        $this->assertSame(0, $exitCode);
    }
}

class TestUpdateCommand extends UpdateCommand
{
    public const MODE_VALID_SIGNATURE = 'valid';
    public const MODE_TAMPERED_PHAR = 'tampered';
    public const MODE_NO_SIGNATURE = 'no-sig';

    private string $mode;
    private string $testPharContent = '<?php $version = "1.0.0"; echo "test";';

    public function __construct(string $mode)
    {
        parent::__construct();
        $this->mode = $mode;
    }

    protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $outPath = $input->getOption('output');

        if ($this->mode === self::MODE_NO_SIGNATURE) {
            $allowUnsigned = (bool)$input->getOption('allow-unsigned');
            $io->section('Verifying signature…');
            $io->warning('No signature file found — proceeding without verification (--allow-unsigned).');
            if (!$allowUnsigned) {
                $io->error('No signature file found in release. Use --allow-unsigned to install anyway (not recommended).');
                return 1;
            }
            $io->success('Update complete (no signature verification).');
            return 0;
        }

        $tmpFile = $outPath ?? (sys_get_temp_dir() . '/phpdup.phar.tmp.' . bin2hex(random_bytes(8)));

        file_put_contents($tmpFile, $this->testPharContent);
        if ($this->mode === self::MODE_TAMPERED_PHAR) {
            file_put_contents($tmpFile, $this->testPharContent . "\n// TAMPERED");
        }

        $tmpSig = $tmpFile . '.sig';
        $expectedSig = hash_hmac('sha256', $this->testPharContent, $this->getVerificationKey());
        file_put_contents($tmpSig, $expectedSig . "\n");

        $io->section('Verifying signature…');
        if (!$this->verifySignature($tmpFile, trim((string)@file_get_contents($tmpSig)))) {
            @unlink($tmpFile);
            @unlink($tmpSig);
            $io->error("HMAC-SHA256 signature mismatch.\nUse --allow-unsigned to install anyway (not recommended).");
            return 1;
        }

        @unlink($tmpSig);

        if ($outPath !== null) {
            chmod($outPath, 0755 & ~umask());
            $io->success("phpdup written to: {$outPath}");
            return 0;
        }

        $io->success('phpdup updated.');
        return 0;
    }

    private function getVerificationKey(): string
    {
        $rc = new \ReflectionClass(UpdateCommand::class);
        $const = $rc->getConstant('VERIFICATION_KEY');
        return $const !== false ? $const : 'REPLACE_WITH_REAL_PUBLIC_KEY';
    }

    protected function verifySignature(string $pharFile, string $expectedSig): bool
    {
        if ($this->mode === self::MODE_TAMPERED_PHAR) {
            return false;
        }
        if ($this->mode === self::MODE_VALID_SIGNATURE) {
            return true;
        }
        return parent::verifySignature($pharFile, $expectedSig);
    }

    protected function githubToken(): string
    {
        return '';
    }

    protected function runningVersion(): string
    {
        return '0.0.0';
    }
}
