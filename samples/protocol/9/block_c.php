<?php
declare(strict_types=1);

namespace Acme\Storage\BunnyCdn;

use Psr\Log\LoggerInterface;

final class BunnyCdnMultipartUpload
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $zone,
        private readonly string $accessKey
    ) {
    }

    public function upload(string $remotePath, string $contentType, string $filePath): string
    {
        if (!is_readable($filePath)) {
            throw new \RuntimeException('Bunny file unreadable: ' . $filePath);
        }
        $boundary = '----acme' . bin2hex(random_bytes(8));
        $bodyParts = [];
        $bodyParts[] = "--$boundary\r\n";
        $bodyParts[] = "Content-Disposition: form-data; name=\"path\"\r\n\r\n";
        $bodyParts[] = $remotePath . "\r\n";
        $bodyParts[] = "--$boundary\r\n";
        $bodyParts[] = "Content-Disposition: form-data; name=\"zone\"\r\n\r\n";
        $bodyParts[] = $this->zone . "\r\n";
        $bodyParts[] = "--$boundary\r\n";
        $bodyParts[] = "Content-Disposition: form-data; name=\"file\"; filename=\"" . basename($filePath) . "\"\r\n";
        $bodyParts[] = "Content-Type: $contentType\r\n\r\n";
        $bodyParts[] = (string) file_get_contents($filePath);
        $bodyParts[] = "\r\n--$boundary--\r\n";
        $body = implode('', $bodyParts);

        $url = 'https://storage.bunnycdn.com/' . $this->zone . '/' . ltrim($remotePath, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'AccessKey: ' . $this->accessKey,
                'Content-Type: multipart/form-data; boundary=' . $boundary,
                'Content-Length: ' . strlen($body),
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            $this->logger->error('Bunny upload failed', ['status' => $status, 'body' => $response]);
            throw new \RuntimeException('Bunny HTTP ' . $status);
        }
        return 'https://' . $this->zone . '.b-cdn.net/' . ltrim($remotePath, '/');
    }
}
