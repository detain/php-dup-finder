<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Server;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\ServeCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class ServeCommandTest extends TestCase
{
    public function testBindPublicWithoutTokenIsRejected(): void
    {
        $command = new ServeCommand();
        $command->setApplication(new Application('phpdup', '1.0.0'));

        $input = new ArrayInput([
            '--host' => '0.0.0.0',
            '--port' => '8080',
            '--bind-public' => true,
            // no --token
        ]);

        $output = new NullOutput();
        $exitCode = $command->run($input, $output);

        $this->assertSame(1, $exitCode);
    }

    public function testNonLoopbackWithoutBindPublicIsRejected(): void
    {
        $command = new ServeCommand();
        $command->setApplication(new Application('phpdup', '1.0.0'));

        $input = new ArrayInput([
            '--host' => '0.0.0.0',
            '--port' => '18082',
            // no --bind-public
        ]);

        $output = new NullOutput();
        $exitCode = $command->run($input, $output);

        $this->assertSame(1, $exitCode);
    }

    /**
     * Verify that --serve-root and --token options are properly configured.
     */
    public function testServeRootOptionIsConfigured(): void
    {
        $command = new ServeCommand();
        $definition = $command->getDefinition();

        $serveRootOption = $definition->getOption('serve-root');
        $this->assertNotNull($serveRootOption);
        $this->assertTrue($serveRootOption->isValueRequired());
        $this->assertSame(getcwd() ?: '.', $serveRootOption->getDefault());
    }

    /**
     * Verify that --token option is properly configured.
     */
    public function testTokenOptionIsConfigured(): void
    {
        $command = new ServeCommand();
        $definition = $command->getDefinition();

        $tokenOption = $definition->getOption('token');
        $this->assertNotNull($tokenOption);
        $this->assertTrue($tokenOption->isValueRequired());
        $this->assertNull($tokenOption->getDefault());
    }

    /**
     * Verify that --bind-public option description mentions token requirement.
     */
    public function testBindPublicOptionDescriptionMentionsToken(): void
    {
        $command = new ServeCommand();
        $definition = $command->getDefinition();

        $bindPublicOption = $definition->getOption('bind-public');
        $this->assertNotNull($bindPublicOption);
        $this->assertStringContainsString('token', $bindPublicOption->getDescription());
    }
}
