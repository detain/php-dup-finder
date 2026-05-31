<?php

declare(strict_types=1);

namespace App\Http\Security;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ContentSecurityPolicy
{
    private const CSP_DEFAULT_SRC = "'self'";
    private const CSP_SCRIPT_SRC = "'self' 'unsafe-inline' 'unsafe-eval'";
    private const CSP_STYLE_SRC = "'self' 'unsafe-inline'";
    private const CSP_IMG_SRC = "'self' data: https:";
    private const CSP_FONT_SRC = "'self' data:";
    private const CSP_CONNECT_SRC = "'self' https://api.example.com";
    private const CSP_MEDIA_SRC = "'self'";
    private const CSP_OBJECT_SRC = "'none'";
    private const CSP_FRAME_SRC = "'none'";
    private const CSP_REPORT_URI = '/csp-report';
    private const CSP_REPORT_ONLY = false;
    private const CSP_UPGRADE_INSECURE_REQUESTS = true;
    private const CSP_BLOCK_ALL_MIXED_CONTENT = true;

    private array $directives = [];

    public function __construct()
    {
        $this->directives = [
            'default-src' => self::CSP_DEFAULT_SRC,
            'script-src' => self::CSP_SCRIPT_SRC,
            'style-src' => self::CSP_STYLE_SRC,
            'img-src' => self::CSP_IMG_SRC,
            'font-src' => self::CSP_FONT_SRC,
            'connect-src' => self::CSP_CONNECT_SRC,
            'media-src' => self::CSP_MEDIA_SRC,
            'object-src' => self::CSP_OBJECT_SRC,
            'frame-src' => self::CSP_FRAME_SRC,
        ];
    }

    public function addDirective(string $name, string $value): self
    {
        $this->directives[$name] = $value;
        return $this;
    }

    public function build(): string
    {
        $csp = [];

        foreach ($this->directives as $directive => $value) {
            $csp[] = "{$directive} {$value}";
        }

        if (self::CSP_UPGRADE_INSECURE_REQUESTS) {
            $csp[] = 'upgrade-insecure-requests';
        }

        if (self::CSP_BLOCK_ALL_MIXED_CONTENT) {
            $csp[] = 'block-all-mixed-content';
        }

        return implode('; ', $csp);
    }

    public function applyToResponse(Response $response): Response
    {
        $headerName = self::CSP_REPORT_ONLY ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        $headerValue = $this->build();

        $response->headers->set($headerName, $headerValue);

        if (self::CSP_REPORT_URI !== '') {
            $response->headers->set('Report-To', json_encode([
                'group' => 'csp-endpoint',
                'max_age' => 86400,
                'endpoints' => [
                    ['url' => self::CSP_REPORT_URI],
                ],
            ]));
        }

        $this->addSecurityHeaders($response);

        return $response;
    }

    private function addSecurityHeaders(Response $response): void
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public static function forApi(): self
    {
        $csp = new self();
        $csp->directives = [
            'default-src' => "'none'",
            'script-src' => "'none'",
            'style-src' => "'none'",
            'img-src' => "'none'",
            'font-src' => "'none'",
            'connect-src' => "'self'",
            'media-src' => "'none'",
            'object-src' => "'none'",
            'frame-src' => "'none'",
        ];
        return $csp;
    }

    public static function forAdmin(): self
    {
        $csp = new self();
        $csp->directives = [
            'default-src' => "'self'",
            'script-src' => "'self' 'unsafe-inline'",
            'style-src' => "'self' 'unsafe-inline'",
            'img-src' => "'self' data: https:",
            'font-src' => "'self' data:",
            'connect-src' => "'self' https://admin.example.com",
            'media-src' => "'self'",
            'object-src' => "'none'",
            'frame-src' => "'none'",
        ];
        return $csp;
    }
}
