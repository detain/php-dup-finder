<?php
declare(strict_types=1);

namespace App\Http;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class FileDownloadResponseHandler
{
    private ConfigManager $config;
    private LoggerInterface $logger;
    private int $compressionLevel = 6;
    private int $minSizeForCompression = 1024;

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function createResponse(string $filePath, Request $request): Response
    {
        $content = file_get_contents($filePath);
        
        if ($content === false) {
            throw new \RuntimeException('Failed to read file');
        }
        
        $response = new Response();
        $response->setContent($content);
        
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $contentType = $this->getContentType($extension);
        $response->headers->set('Content-Type', $contentType);
        
        $fileName = basename($filePath);
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        
        $encoding = $this->negotiateEncoding($request);
        
        if ($encoding !== null && strlen($response->getContent()) >= $this->minSizeForCompression) {
            $compressed = $this->compressContent($response->getContent(), $encoding);
            $response->setContent($compressed);
            $response->headers->set('Content-Encoding', $encoding);
            $response->headers->set('Vary', 'Accept-Encoding');
        }
        
        $response->headers->set('Cache-Control', 'private, max-age=3600');
        $response->headers->set('Pragma', 'private');
        
        return $response;
    }

    private function getContentType(string $extension): string
    {
        $types = [
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'csv' => 'text/csv',
            'txt' => 'text/plain',
            'json' => 'application/json',
        ];
        
        return $types[$extension] ?? 'application/octet-stream';
    }

    private function negotiateEncoding(Request $request): ?string
    {
        $acceptEncoding = $request->headers->get('Accept-Encoding', '');
        
        if (str_contains($acceptEncoding, 'gzip')) {
            return 'gzip';
        }
        
        if (str_contains($acceptEncoding, 'deflate')) {
            return 'deflate';
        }
        
        return null;
    }

    private function compressContent(string $content, string $encoding): string
    {
        if ($encoding === 'gzip') {
            return gzencode($content, $this->compressionLevel);
        }
        
        if ($encoding === 'deflate') {
            return gzdeflate($content, $this->compressionLevel);
        }
        
        return $content;
    }

    public function setCacheHeaders(Response $response, int $maxAge): void
    {
        $response->headers->set('Cache-Control', 'public, max-age=' . $maxAge);
        $response->headers->set('Pragma', '');
        $response->headers->set('Expires', gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT');
    }
}
