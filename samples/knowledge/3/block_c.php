<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Subscription;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\SubscriptionRepository;
use Closure;
use DateTimeImmutable;

final class RequireActiveSubscription
{
    public function __construct(private SubscriptionRepository $subscriptions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $sub = $this->subscriptions->findForCustomer($user->id);
        if ($sub === null) {
            return Response::redirect('/subscribe');
        }

        $now = new DateTimeImmutable();

        if ($sub->status === Subscription::STATUS_ACTIVE) {
            return $next($request);
        }

        if ($sub->status === Subscription::STATUS_PAST_DUE) {
            $daysOverdue = (int) $now->diff($sub->currentPeriodEnd)->days;

            // Inside the 7-day grace window: still allow access, but flash a banner.
            if ($daysOverdue <= 7) {
                $request->session()->flash(
                    'billing_warning',
                    sprintf('Payment failed. Access will be locked in %d days.', 7 - $daysOverdue)
                );
                return $next($request);
            }

            return Response::redirect('/billing/update?reason=locked');
        }

        return Response::redirect('/subscribe');
    }
}
