<?php

declare(strict_types=1);

namespace Acme\Storage\Pipeline;

use Psr\Log\LoggerInterface;

final class GzipCompressor
{
    private const CHUNK_BYTES = 65536;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function compress(string $sourcePath, string $destPath): int
    {
        $in = fopen($sourcePath, 'rb');
        if ($in === false) {
            throw new \RuntimeException("cannot open source {$sourcePath}");
        }

        $out = gzopen($destPath, 'wb6');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException("cannot open dest {$destPath}");
        }

        $bytesIn = 0;
        try {
            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK_BYTES);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $bytesIn += strlen($chunk);
                $written = gzwrite($out, $chunk);
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('gzip write failed');
                }
            }
        } finally {
            gzclose($out);
            fclose($in);
        }

        $this->logger->info('compress complete', ['source' => $sourcePath, 'bytes_in' => $bytesIn]);
        return $bytesIn;
    }
}
