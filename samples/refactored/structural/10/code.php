<?php

declare(strict_types=1);

namespace Acme\Common\Pipeline;

use Psr\Log\LoggerInterface;

interface StreamTransform
{
    /** Called once before any chunks; may return a header to write. */
    public function open(): string;

    /** Transform a single chunk. */
    public function transform(string $chunk): string;

    /** Called once after the last chunk; may return a trailer. */
    public function close(): string;
}

final class ChunkedStreamPipeline
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function run(string $sourcePath, string $destPath, StreamTransform $transform, int $chunkBytes, string $label): int
    {
        $in = fopen($sourcePath, 'rb');
        if ($in === false) {
            throw new \RuntimeException("cannot open source {$sourcePath}");
        }
        $out = fopen($destPath, 'wb');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException("cannot open dest {$destPath}");
        }

        $bytesIn = 0;
        try {
            fwrite($out, $transform->open());
            while (!feof($in)) {
                $chunk = fread($in, $chunkBytes);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $bytesIn += strlen($chunk);
                $written = fwrite($out, $transform->transform($chunk));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException("{$label} write failed");
                }
            }
            fwrite($out, $transform->close());
        } finally {
            fclose($out);
            fclose($in);
        }

        $this->logger->info("{$label} complete", ['source' => $sourcePath, 'bytes_in' => $bytesIn]);
        return $bytesIn;
    }
}
