<?php
declare(strict_types=1);

namespace Acme\Storage\Cloudinary;

use Psr\Log\LoggerInterface;

final class CloudinaryMultipartUpload
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $cloudName,
        private readonly string $uploadPreset
    ) {
    }

    public function upload(string $publicId, string $contentType, string $filePath): string
    {
        if (!is_readable($filePath)) {
            throw new \RuntimeException('Cloudinary file unreadable: ' . $filePath);
        }
        $boundary = '----acme' . bin2hex(random_bytes(8));
        $bodyParts = [];
        $bodyParts[] = "--$boundary\r\n";
        $bodyParts[] = "Content-Disposition: form-data; name=\"public_id\"\r\n\r\n";
        $bodyParts[] = $publicId . "\r\n";
        $bodyParts[] = "--$boundary\r\n";
        $bodyParts[] = "Content-Disposition: form-data; name=\"upload_preset\"\r\n\r\n";
        $bodyParts[] = $this->uploadPreset . "\r\n";
        $bodyParts[] = "--$boundary\r\n";
        $bodyParts[] = "Content-Disposition: form-data; name=\"file\"; filename=\"" . basename($filePath) . "\"\r\n";
        $bodyParts[] = "Content-Type: $contentType\r\n\r\n";
        $bodyParts[] = (string) file_get_contents($filePath);
        $bodyParts[] = "\r\n--$boundary--\r\n";
        $body = implode('', $bodyParts);

        $url = 'https://api.cloudinary.com/v1_1/' . $this->cloudName . '/auto/upload';
        $ch = curl_init($url);
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
            $this->logger->error('Cloudinary upload failed', ['status' => $status, 'body' => $response]);
            throw new \RuntimeException('Cloudinary HTTP ' . $status);
        }
        $decoded = json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);
        return (string) ($decoded['secure_url'] ?? '');
    }
}
