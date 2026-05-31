<?php
declare(strict_types=1);

namespace Acme\Storage;

use Psr\Log\LoggerInterface;

final class MultipartUploader
{
    /** @param array<string,string> $headers */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $headers,
        private readonly string $tag
    ) {
    }

    /**
     * @param array<string,string> $fields
     * @return array{status:int, body:string}
     */
    public function upload(string $url, array $fields, string $filePath, string $contentType): array
    {
        if (!is_readable($filePath)) {
            throw new \RuntimeException($this->tag . ' file unreadable: ' . $filePath);
        }
        $boundary = '----acme' . bin2hex(random_bytes(8));
        $bodyParts = [];
        foreach ($fields as $name => $value) {
            $bodyParts[] = "--$boundary\r\n";
            $bodyParts[] = "Content-Disposition: form-data; name=\"$name\"\r\n\r\n";
            $bodyParts[] = $value . "\r\n";
        }
        $bodyParts[] = "--$boundary\r\n";
        $bodyParts[] = "Content-Disposition: form-data; name=\"file\"; filename=\"" . basename($filePath) . "\"\r\n";
        $bodyParts[] = "Content-Type: $contentType\r\n\r\n";
        $bodyParts[] = (string) file_get_contents($filePath);
        $bodyParts[] = "\r\n--$boundary--\r\n";
        $body = implode('', $bodyParts);

        $headerLines = [];
        foreach ($this->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $headerLines[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
        $headerLines[] = 'Content-Length: ' . strlen($body);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => 60,
        ]);
        $response = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            $this->logger->error($this->tag . ' upload failed', ['status' => $status, 'body' => $response]);
            throw new \RuntimeException($this->tag . ' HTTP ' . $status);
        }
        return ['status' => $status, 'body' => $response];
    }
}
