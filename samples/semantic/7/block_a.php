<?php

declare(strict_types=1);

namespace Acme\Http\Middleware;

use Acme\Http\Exception\ForbiddenException;
use Acme\Http\Request;
use Acme\Http\Response;
use Acme\Http\Session;

final class BetaFeatureMiddleware
{
    public function __construct(private Session $session)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $user = $this->session->user();
        $role = $user['role'] ?? '';
        $plan = $user['plan_code'] ?? '';
        $flags = $user['features'] ?? [];

        $allowed = in_array($role, ['admin', 'beta_tester'], true)
            && in_array($plan, ['pro', 'enterprise'], true)
            && !empty($flags['ai_summaries']);

        if (!$allowed) {
            throw new ForbiddenException('Beta feature not available for this account.');
        }

        return $next($request);
    }
}
