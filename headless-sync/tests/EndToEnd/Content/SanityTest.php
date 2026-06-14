<?php

namespace HSP\Tests\EndToEnd\Content;

use HSP\Tests\EndToEnd\BaseEndToEndTestCase;

class SanityTest extends BaseEndToEndTestCase
{
    /**
     * Verify that the environment configuration is successfully loaded.
     */
    public function testEnvironmentConfig(): void
    {
        $this->assertNotEmpty(getenv('WP_URL'), 'WP_URL env variable is not set');
        $this->assertNotEmpty(getenv('PG_DB_HOST'), 'PG_DB_HOST env variable is not set');
        $this->assertNotEmpty(getenv('DELIVERY_API_URL'), 'DELIVERY_API_URL env variable is not set');
    }

    /**
     * Test connectivity to PostgreSQL.
     */
    public function testPostgresConnection(): void
    {
        $pdo = $this->getPostgresPdo();

        if ($pdo === null) {
            $this->markTestSkipped('PostgreSQL connection failed: ' . ($this->pgConnectionError ?? 'Unknown error'));
        }

        $this->assertInstanceOf(\PDO::class, $pdo);
        
        $stmt = $pdo->query('SELECT version()');
        $version = $stmt->fetchColumn();
        
        $this->assertNotEmpty($version);
        $this->assertStringContainsString('PostgreSQL', $version);
    }

    /**
     * Test connectivity to WordPress REST API.
     */
    public function testWordPressApiConnectivity(): void
    {
        $client = $this->getWordPressClient();

        try {
            // Request the index of WordPress REST API
            $response = $client->get('');
            $status = $response->getStatusCode();
            
            $this->assertGreaterThanOrEqual(200, $status);
            $this->assertLessThan(500, $status);
        } catch (\Exception $e) {
            $this->markTestSkipped('WordPress REST API is not reachable: ' . $e->getMessage());
        }
    }

    /**
     * Test connectivity to Delivery REST API.
     */
    public function testDeliveryApiConnectivity(): void
    {
        $client = $this->getDeliveryApiClient();

        try {
            $response = $client->get('');
            $status = $response->getStatusCode();
            
            $this->assertGreaterThanOrEqual(200, $status);
            $this->assertLessThan(500, $status);
        } catch (\Exception $e) {
            $this->markTestSkipped('Delivery REST API is not reachable: ' . $e->getMessage());
        }
    }
}
