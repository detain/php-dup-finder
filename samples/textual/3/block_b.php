<?php
declare(strict_types=1);

namespace Bookings\Http\Controllers;

use Bookings\Http\JsonResponse;
use Bookings\Http\Request;
use Bookings\Service\GuestService;

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
final class GuestController
{
    public function __construct(private GuestService $service) {}

    public function show(Request $req, string $id): JsonResponse
    {
        $guest = $this->service->find($id);
        return new JsonResponse($guest->toArray(), 200);
    }

    public function update(Request $req, string $id): JsonResponse
    {
        $updated = $this->service->update($id, $req->json());
        return new JsonResponse($updated->toArray(), 200);
    }

    public function delete(string $id): JsonResponse
    {
        $this->service->delete($id);
        return new JsonResponse(null, 204);
    }
}
