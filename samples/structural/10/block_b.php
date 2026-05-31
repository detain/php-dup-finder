<?php

declare(strict_types=1);

namespace Acme\Security\Pipeline;

use Psr\Log\LoggerInterface;

final class StreamEncryptor
{
    private const CHUNK_BYTES = 32768;
    private const CIPHER = 'aes-256-ctr';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $key,
    ) {
    }

    public function encrypt(string $sourcePath, string $destPath): int
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

        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        fwrite($out, $iv);

        $bytesIn = 0;
        try {
            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK_BYTES);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $bytesIn += strlen($chunk);
                $cipherText = openssl_encrypt($chunk, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);
                if ($cipherText === false) {
                    throw new \RuntimeException('encryption failed');
                }
                $written = fwrite($out, $cipherText);
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('encrypted write failed');
                }
            }
        } finally {
            fclose($out);
            fclose($in);
        }

        $this->logger->info('encrypt complete', ['source' => $sourcePath, 'bytes_in' => $bytesIn]);
        return $bytesIn;
    }
}
