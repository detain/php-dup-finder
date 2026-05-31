<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Shipping;

use PHPUnit\Framework\TestCase;
use App\Http\Controllers\Api\Shipping\ShippingRateController;
use App\Services\ShippingRateService;
use App\Http\Request\ShippingRateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mockery;

final class ShippingRateControllerTest extends TestCase
{
    private ShippingRateController $controller;
    private $mockShippingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockShippingService = Mockery::mock(ShippingRateService::class);
        $this->controller = new ShippingRateController($this->mockShippingService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testGetRatesReturns200WithValidOriginDestination(): void
    {
        $request = Request::create('/api/shipping/rates', 'POST', [
            'origin' => [
                'postal_code' => '10001',
                'country' => 'US',
            ],
            'destination' => [
                'postal_code' => '90210',
                'country' => 'US',
            ],
            'packages' => [
                ['weight' => 5, 'length' => 10, 'width' => 8, 'height' => 6],
            ],
        ]);

        $mockRates = [
            ['carrier' => 'UPS', 'service' => 'Ground', 'rate' => 15.99],
            ['carrier' => 'FedEx', 'service' => 'Express', 'rate' => 25.99],
        ];

        $this->mockShippingService
            ->shouldReceive('calculateRates')
            ->once()
            ->andReturn($mockRates);

        $response = $this->controller->getRates($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('rates', $data);
        $this->assertCount(2, $data['rates']);
    }

    public function testGetRatesReturns400WithMissingOrigin(): void
    {
        $request = Request::create('/api/shipping/rates', 'POST', [
            'destination' => [
                'postal_code' => '90210',
                'country' => 'US',
            ],
        ]);

        $response = $this->controller->getRates($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertStringContainsString('origin', $data['message']);
    }

    public function testGetRatesReturns400WithMissingDestination(): void
    {
        $request = Request::create('/api/shipping/rates', 'POST', [
            'origin' => [
                'postal_code' => '10001',
                'country' => 'US',
            ],
        ]);

        $response = $this->controller->getRates($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertStringContainsString('destination', $data['message']);
    }

    public function testGetRatesReturns400WithEmptyPackages(): void
    {
        $request = Request::create('/api/shipping/rates', 'POST', [
            'origin' => [
                'postal_code' => '10001',
                'country' => 'US',
            ],
            'destination' => [
                'postal_code' => '90210',
                'country' => 'US',
            ],
            'packages' => [],
        ]);

        $response = $this->controller->getRates($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('packages', strtolower($data['message']));
    }

    public function testGetRatesReturns422WithInvalidCountryCode(): void
    {
        $request = Request::create('/api/shipping/rates', 'POST', [
            'origin' => [
                'postal_code' => '10001',
                'country' => 'XX',
            ],
            'destination' => [
                'postal_code' => '90210',
                'country' => 'US',
            ],
            'packages' => [
                ['weight' => 5],
            ],
        ]);

        $response = $this->controller->getRates($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('origin.country', $data['errors']);
    }

    public function testGetRatesReturns422WithWeightExceedingLimit(): void
    {
        $request = Request::create('/api/shipping/rates', 'POST', [
            'origin' => [
                'postal_code' => '10001',
                'country' => 'US',
            ],
            'destination' => [
                'postal_code' => '90210',
                'country' => 'US',
            ],
            'packages' => [
                ['weight' => 200, 'length' => 10, 'width' => 8, 'height' => 6],
            ],
        ]);

        $response = $this->controller->getRates($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('packages.0.weight', $data['errors']);
    }

    public function testGetRatesReturns500WhenServiceUnavailable(): void
    {
        $request = Request::create('/api/shipping/rates', 'POST', [
            'origin' => [
                'postal_code' => '10001',
                'country' => 'US',
            ],
            'destination' => [
                'postal_code' => '90210',
                'country' => 'US',
            ],
            'packages' => [
                ['weight' => 5],
            ],
        ]);

        $this->mockShippingService
            ->shouldReceive('calculateRates')
            ->once()
            ->andThrow(new \RuntimeException('Shipping provider API unavailable'));

        $response = $this->controller->getRates($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(500, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }

    public function testGetRatesReturns429WhenRateLimited(): void
    {
        $request = Request::create('/api/shipping/rates', 'POST', [
            'origin' => [
                'postal_code' => '10001',
                'country' => 'US',
            ],
            'destination' => [
                'postal_code' => '90210',
                'country' => 'US',
            ],
            'packages' => [
                ['weight' => 5],
            ],
        ]);

        $this->mockShippingService
            ->shouldReceive('calculateRates')
            ->once()
            ->andThrow(new \RuntimeException('Rate limit exceeded'));

        $response = $this->controller->getRates($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(429, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }

    public function testGetRatesWithInternationalDestination(): void
    {
        $request = Request::create('/api/shipping/rates', 'POST', [
            'origin' => [
                'postal_code' => '10001',
                'country' => 'US',
            ],
            'destination' => [
                'postal_code' => 'SW1A 1AA',
                'country' => 'GB',
            ],
            'packages' => [
                ['weight' => 2],
            ],
        ]);

        $mockRates = [
            ['carrier' => 'DHL', 'service' => 'International Express', 'rate' => 45.99],
        ];

        $this->mockShippingService
            ->shouldReceive('calculateRates')
            ->once()
            ->andReturn($mockRates);

        $response = $this->controller->getRates($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('rates', $data);
        $this->assertCount(1, $data['rates']);
    }

    public function testGetRatesWithMultiplePackages(): void
    {
        $request = Request::create('/api/shipping/rates', 'POST', [
            'origin' => [
                'postal_code' => '10001',
                'country' => 'US',
            ],
            'destination' => [
                'postal_code' => '90210',
                'country' => 'US',
            ],
            'packages' => [
                ['weight' => 5, 'length' => 10, 'width' => 8, 'height' => 6],
                ['weight' => 3, 'length' => 8, 'width' => 6, 'height' => 4],
                ['weight' => 7, 'length' => 12, 'width' => 10, 'height' => 8],
            ],
        ]);

        $mockRates = [
            ['carrier' => 'UPS', 'service' => 'Ground', 'rate' => 28.99],
            ['carrier' => 'FedEx', 'service' => '2Day', 'rate' => 42.99],
        ];

        $this->mockShippingService
            ->shouldReceive('calculateRates')
            ->once()
            ->andReturn($mockRates);

        $response = $this->controller->getRates($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('rates', $data);
    }
}
