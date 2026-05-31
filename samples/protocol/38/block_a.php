<?php
declare(strict_types=1);

namespace App\Http;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RestApiResponseHandler
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

    public function createResponse(array $data, Request $request): Response
    {
        $response = new Response();
        $response->setContent(json_encode($data));
        
        $contentType = $this->negotiateContentType($request);
        $response->headers->set('Content-Type', $contentType);
        
        $encoding = $this->negotiateEncoding($request);
        
        if ($encoding !== null && strlen($response->getContent()) >= $this->minSizeForCompression) {
            $compressed = $this->compressContent($response->getContent(), $encoding);
            $response->setContent($compressed);
            $response->headers->set('Content-Encoding', $encoding);
            $response->headers->set('Vary', 'Accept-Encoding');
        }
        
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        
        return $response;
    }

    private function negotiateContentType(Request $request): string
    {
        $accept = $request->headers->get('Accept', 'application/json');
        
        if (str_contains($accept, 'application/xml')) {
            return 'application/xml';
        }
        
        if (str_contains($accept, 'text/html')) {
            return 'text/html';
        }
        
        return 'application/json';
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
