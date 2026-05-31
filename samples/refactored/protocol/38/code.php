<?php
declare(strict_types=1);

namespace App\Http;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CompressionResponseHandler
{
    private LoggerInterface $logger;
    private int $compressionLevel;
    private int $minSizeForCompression;

    public function __construct(
        LoggerInterface $logger,
        int $compressionLevel = 6,
        int $minSizeForCompression = 1024
    ) {
        $this->logger = $logger;
        $this->compressionLevel = $compressionLevel;
        $this->minSizeForCompression = $minSizeForCompression;
    }

    public function createJsonResponse(array $data, Request $request): Response
    {
        $response = new Response();
        $response->setContent(json_encode($data));
        $response->headers->set('Content-Type', 'application/json');
        
        $this->applyCompression($response, $request);
        $this->setNoCacheHeaders($response);
        
        return $response;
    }

    public function createFileResponse(string $content, string $contentType, string $fileName, Request $request): Response
    {
        $response = new Response();
        $response->setContent($content);
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        
        $this->applyCompression($response, $request);
        
        return $response;
    }

    protected function applyCompression(Response $response, Request $request): void
    {
        $encoding = $this->negotiateEncoding($request);
        
        if ($encoding === null || strlen($response->getContent()) < $this->minSizeForCompression) {
            return;
        }
        
        $compressed = $this->compressContent($response->getContent(), $encoding);
        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', $encoding);
        $response->headers->set('Vary', 'Accept-Encoding');
    }

    protected function negotiateEncoding(Request $request): ?string
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

    protected function compressContent(string $content, string $encoding): string
    {
        if ($encoding === 'gzip') {
            return gzencode($content, $this->compressionLevel);
        }
        
        if ($encoding === 'deflate') {
            return gzdeflate($content, $this->compressionLevel);
        }
        
        return $content;
    }

    protected function setNoCacheHeaders(Response $response): void
    {
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
    }

    protected function setCacheHeaders(Response $response, int $maxAge): void
    {
        $response->headers->set('Cache-Control', 'public, max-age=' . $maxAge);
        $response->headers->set('Pragma', '');
        $response->headers->set('Expires', gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT');
    }
}
