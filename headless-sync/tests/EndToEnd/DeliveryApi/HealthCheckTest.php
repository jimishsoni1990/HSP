<?php

namespace HSP\Tests\EndToEnd\DeliveryApi;

use HSP\Tests\EndToEnd\BaseEndToEndTestCase;

/**
 * Tests that verify the Delivery API health check endpoint.
 *
 * These tests confirm that the Delivery API is running and responsive
 * by checking the root endpoint for the expected health response.
 */
class HealthCheckTest extends BaseEndToEndTestCase
{
    /**
     * Skip all tests if the Delivery API container is not reachable.
     */
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->getDeliveryApiClient()->get('');
        } catch (\Exception $e) {
            $this->markTestSkipped('Delivery API is not reachable: ' . $e->getMessage());
        }
    }

    /**
     * Verify that GET / returns {"status":"ok"}.
     */
    public function testHealthEndpointReturnsOk(): void
    {
        $client = $this->getDeliveryApiClient();
        $response = $client->get('');

        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertIsArray($body, 'Health endpoint should return a JSON object.');
        $this->assertArrayHasKey('status', $body, 'Health response should contain a "status" key.');
        $this->assertEquals('ok', $body['status'], 'Health status should be "ok".');
    }

    /**
     * Verify that GET / returns HTTP 200 status code.
     */
    public function testHealthEndpointReturns200(): void
    {
        $client = $this->getDeliveryApiClient();
        $response = $client->get('');

        $this->assertEquals(200, $response->getStatusCode(), 'Health endpoint should return HTTP 200.');
    }
}
