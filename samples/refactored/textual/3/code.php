<?php
declare(strict_types=1);

namespace Bookings\Http\Controllers;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class ApiContract
{
    public function __construct(
        public string $team = 'Platform / Bookings Squad <platform-bookings@example.com>',
        public string $openApiTag = 'Reservations',
        public string $authScope = 'bookings:write',
        public string $rateLimit = '60 req/min per token, 1000 req/hour per IP',
        public string $cachePolicy = 'no-store',
        /** @var list<string> */
        public array $changelog = [
            '2024-02-14 v3.2 added partial-refund payload',
            '2024-04-02 v3.3 deprecated legacy_id field, removal in v4.0',
            '2024-05-21 v3.4 added metadata passthrough object',
        ],
    ) {}
}

#[ApiContract]
final class ReservationController
{
    public function __construct(private \Bookings\Service\ReservationService $service) {}

    public function create(\Bookings\Http\Request $req): \Bookings\Http\JsonResponse
    {
        $reservation = $this->service->create($req->json());
        return new \Bookings\Http\JsonResponse(['id' => $reservation->id], 201);
    }
}

#[ApiContract]
final class GuestController
{
    public function __construct(private \Bookings\Service\GuestService $service) {}

    public function show(string $id): \Bookings\Http\JsonResponse
    {
        return new \Bookings\Http\JsonResponse($this->service->find($id)->toArray());
    }
}
