<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use Closure;

final class LimitUploadSize
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isMultipart()) {
            return $next($request);
        }

        $contentLength = (int) $request->header('Content-Length', '0');
        $maxBytes = 10485760; // 10 MiB hard cap for uploads.

        if ($contentLength > $maxBytes) {
            return Response::json([
                'error' => 'payload_too_large',
                'message' => 'Request exceeds the 10 MiB upload limit.',
                'limit_bytes' => 10485760,
                'received_bytes' => $contentLength,
            ], 413);
        }

        foreach ($request->files() as $field => $file) {
            if ($file->size > 10485760) {
                return Response::json([
                    'error' => 'file_too_large',
                    'field' => $field,
                    'message' => sprintf('File "%s" exceeds the 10 MiB limit.', $file->originalName),
                ], 413);
            }
            if ($file->size === 0) {
                return Response::json([
                    'error' => 'empty_file',
                    'field' => $field,
                ], 422);
            }
        }

        return $next($request);
    }
}
