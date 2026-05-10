<?php
declare(strict_types=1);

namespace Phpdup\Cli;

use Phpdup\Server\Application;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `phpdup serve` — minimal HTTP server fronting the analysis pipeline.
 *
 * Dependency-light: uses `stream_socket_server` and a hand-rolled
 * HTTP/1.1 request parser. No ReactPHP / Amp; this is meant for
 * dev / small-team use, not production. For realistic deployment
 * the Application class can be lifted into a more capable runtime
 * (Roadrunner, FrankenPHP, FPM behind nginx, etc.).
 *
 * Routes mirror docs/SERVER.md:
 *
 *   GET  /healthz       → liveness check
 *   POST /analyze       → synchronous analysis, JSON report response
 *   POST /jobs          → enqueue an analysis (synchronous demo impl)
 *   GET  /jobs/{id}     → poll job status / result
 */
final class ServeCommand extends SymfonyCommand
{
    protected function configure(): void
    {
        $this->setName('serve')
            ->setDescription('Run phpdup as a minimal HTTP analysis server.')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host to bind', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'TCP port to bind', '8080');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = (string)$input->getOption('host');
        $port = (int)$input->getOption('port');
        $address = "tcp://{$host}:{$port}";

        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server($address, $errno, $errstr);
        if ($server === false) {
            $output->writeln("<error>phpdup: bind failed: {$errstr}</error>");
            return 1;
        }
        $output->writeln("<info>phpdup serve</info> listening on {$address}");

        $app = new Application();
        // Accept until the underlying socket goes away (SIGINT, fd
        // close, …). Using `is_resource($server)` as the loop
        // condition keeps PhpStan happy — the condition really can
        // become false during the loop.
        while (is_resource($server)) {
            $client = @stream_socket_accept($server, 30);
            if ($client === false) continue;
            $this->handleClient($client, $app);
            @fclose($client);
            if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
        }
        return 0;
    }

    /** @param resource $client */
    private function handleClient($client, Application $app): void
    {
        $raw = '';
        // Read until end of headers.
        while (!feof($client)) {
            $chunk = @fread($client, 4096);
            if ($chunk === false || $chunk === '') break;
            $raw .= $chunk;
            if (str_contains($raw, "\r\n\r\n")) break;
        }
        if (!preg_match('#^([A-Z]+) ([^ ]+) HTTP/1\.[01]\r\n#', $raw, $m)) {
            fwrite($client, "HTTP/1.1 400 Bad Request\r\n\r\n");
            return;
        }
        $method = $m[1];
        $path   = $m[2];
        $bodyStart = strpos($raw, "\r\n\r\n");
        $body = $bodyStart === false ? '' : substr($raw, $bodyStart + 4);
        // Top-level Content-Length parsing for simple JSON bodies.
        if (preg_match('/Content-Length:\s*(\d+)/i', $raw, $cm)) {
            $expected = (int)$cm[1];
            while (strlen($body) < $expected && !feof($client)) {
                $chunk = @fread($client, 4096);
                if ($chunk === false || $chunk === '') break;
                $body .= $chunk;
            }
        }

        $response = $app->handle($method, $path, $body);
        $payload  = "HTTP/1.1 {$response['status']} OK\r\n";
        foreach ($response['headers'] as $h => $v) {
            $payload .= "{$h}: {$v}\r\n";
        }
        $payload .= "Content-Length: " . strlen($response['body']) . "\r\n";
        $payload .= "Connection: close\r\n\r\n";
        $payload .= $response['body'];
        @fwrite($client, $payload);
    }
}
