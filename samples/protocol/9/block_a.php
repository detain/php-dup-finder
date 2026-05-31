<?php
declare(strict_types=1);

namespace Acme\Storage\S3;

use Psr\Log\LoggerInterface;

final class S3MultipartUpload
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $bucket,
        private readonly string $signedUrl
    ) {
    }

    public function upload(string $key, string $contentType, string $filePath): string
    {
        if (!is_readable($filePath)) {
            throw new \RuntimeException('S3 file unreadable: ' . $filePath);
        }
        $boundary = '----acme' . bin2hex(random_bytes(8));
        $bodyParts = [];
        $bodyParts[] = "--$boundary\r\n";
        $bodyParts[] = "Content-Disposition: form-data; name=\"key\"\r\n\r\n";
        $bodyParts[] = $key . "\r\n";
        $bodyParts[] = "--$boundary\r\n";
        $bodyParts[] = "Content-Disposition: form-data; name=\"Content-Type\"\r\n\r\n";
        $bodyParts[] = $contentType . "\r\n";
        $bodyParts[] = "--$boundary\r\n";
        $bodyParts[] = "Content-Disposition: form-data; name=\"file\"; filename=\"" . basename($filePath) . "\"\r\n";
        $bodyParts[] = "Content-Type: $contentType\r\n\r\n";
        $bodyParts[] = (string) file_get_contents($filePath);
        $bodyParts[] = "\r\n--$boundary--\r\n";
        $body = implode('', $bodyParts);

        $ch = curl_init($this->signedUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: multipart/form-data; boundary=' . $boundary,
                'Content-Length: ' . strlen($body),
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            $this->logger->error('S3 upload failed', ['status' => $status, 'body' => $response]);
            throw new \RuntimeException('S3 HTTP ' . $status);
        }
        return 'https://' . $this->bucket . '.s3.amazonaws.com/' . $key;
    }
}
