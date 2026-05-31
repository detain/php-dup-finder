<?php

declare(strict_types=1);

namespace Acme\Archive\Pipeline;

use Psr\Log\LoggerInterface;

final class HashingArchiver
{
    private const CHUNK_BYTES = 16384;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @return array{bytes: int, sha256: string}
     */
    public function archive(string $sourcePath, string $destPath): array
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

        $ctx = hash_init('sha256');
        $bytesIn = 0;
        try {
            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK_BYTES);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $bytesIn += strlen($chunk);
                hash_update($ctx, $chunk);
                $written = fwrite($out, $chunk);
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('archive write failed');
                }
            }
        } finally {
            fclose($out);
            fclose($in);
        }

        $digest = hash_final($ctx);
        $this->logger->info('archive complete', ['source' => $sourcePath, 'bytes_in' => $bytesIn, 'sha256' => $digest]);
        return ['bytes' => $bytesIn, 'sha256' => $digest];
    }
}
