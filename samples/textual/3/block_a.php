<?php
declare(strict_types=1);

namespace Bookings\Http\Controllers;

use Bookings\Http\JsonResponse;
use Bookings\Http\Request;
use Bookings\Service\ReservationService;

/**
 * -----------------------------------------------------------------------------
 *  Maintained by: Platform / Bookings Squad <platform-bookings@example.com>
 *  OpenAPI tag:   Reservations
 *  Auth:          Bearer token with scope "bookings:write"
 *  Rate limit:    60 req/min per token, 1000 req/hour per IP
 *  Caching:       responses are NEVER cached (Cache-Control: no-store)
 *  Changelog:
 *    2024-02-14  v3.2 added partial-refund payload
 *    2024-04-02  v3.3 deprecated `legacy_id` field, removal in v4.0
 *    2024-05-21  v3.4 added `metadata` passthrough object
 * -----------------------------------------------------------------------------
 */
final class ReservationController
{
    public function __construct(private ReservationService $service) {}

    public function create(Request $req): JsonResponse
    {
        $reservation = $this->service->create($req->json());
        return new JsonResponse(['id' => $reservation->id], 201);
    }

    public function cancel(Request $req, string $id): JsonResponse
    {
        $this->service->cancel($id, $req->json()['reason'] ?? null);
        return new JsonResponse(['cancelled' => true], 200);
    }
}
