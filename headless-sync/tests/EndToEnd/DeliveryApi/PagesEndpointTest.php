<?php

namespace HSP\Tests\EndToEnd\DeliveryApi;

use HSP\Tests\EndToEnd\BaseEndToEndTestCase;

/**
 * Tests that verify the Delivery API /api/v1/pages endpoint.
 *
 * These tests insert data directly into the PostgreSQL content.pages table
 * and query the Delivery API to verify slug lookups, 404 handling, and
 * filtering of soft-deleted pages.
 */
class PagesEndpointTest extends BaseEndToEndTestCase
{
    /**
     * Skip all tests if either PostgreSQL or the Delivery API is not available.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->getPostgresPdo() === null) {
            $this->markTestSkipped(
                'PostgreSQL connection is not available: ' . ($this->pgConnectionError ?? 'Unknown error')
            );
        }

        try {
            $response = $this->getDeliveryApiClient()->get('');
            if ($response->getStatusCode() >= 500) {
                $this->markTestSkipped('Delivery API returned a server error.');
            }
        } catch (\Exception $e) {
            $this->markTestSkipped('Delivery API is not reachable: ' . $e->getMessage());
        }
    }

    /**
     * Verify that a page can be retrieved by slug via the Delivery API.
     */
    public function testGetPageBySlug(): void
    {
        $pdo = $this->getPostgresPdo();
        $pageId = $this->generateUuidV4();

        // Insert a published page directly into PostgreSQL
        $stmt = $pdo->prepare(
            "INSERT INTO content.pages (id, source_post_id, source_entity_type, slug, title, status, created_at, updated_at)
             VALUES (:id, :source_post_id, 'page', :slug, :title, 'publish', NOW(), NOW())"
        );
        $stmt->execute([
            'id'             => $pageId,
            'source_post_id' => '77701',
            'slug'           => 'e2e-delivery-page-slug',
            'title'          => 'E2E Delivery Page Title',
        ]);

        // Query the Delivery API
        $client = $this->getDeliveryApiClient();
        $response = $client->get('api/v1/pages', [
            'query' => ['slug' => 'e2e-delivery-page-slug'],
        ]);

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertIsArray($body);
        $this->assertEquals($pageId, $body['id']);
        $this->assertEquals('77701', $body['source_post_id']);
        $this->assertEquals('e2e-delivery-page-slug', $body['slug']);
        $this->assertEquals('E2E Delivery Page Title', $body['title']);
        $this->assertEquals('publish', $body['status']);
    }

    /**
     * Verify that querying a non-existent page slug returns 404.
     */
    public function testGetPageBySlugReturns404ForMissing(): void
    {
        $client = $this->getDeliveryApiClient();
        $response = $client->get('api/v1/pages', [
            'query' => ['slug' => 'nonexistent-page-slug-' . uniqid()],
        ]);

        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * Verify that pages with a non-null deleted_at are NOT returned by the API.
     */
    public function testDeletedPagesAreFiltered(): void
    {
        $pdo = $this->getPostgresPdo();
        $pageId = $this->generateUuidV4();

        // Insert a soft-deleted page
        $stmt = $pdo->prepare(
            "INSERT INTO content.pages (id, source_post_id, source_entity_type, slug, title, status, created_at, updated_at, deleted_at)
             VALUES (:id, :source_post_id, 'page', :slug, :title, 'trash', NOW(), NOW(), NOW())"
        );
        $stmt->execute([
            'id'             => $pageId,
            'source_post_id' => '77702',
            'slug'           => 'e2e-deleted-page-slug',
            'title'          => 'E2E Deleted Page',
        ]);

        // Query the Delivery API — should return 404
        $client = $this->getDeliveryApiClient();
        $response = $client->get('api/v1/pages', [
            'query' => ['slug' => 'e2e-deleted-page-slug'],
        ]);

        $this->assertEquals(
            404,
            $response->getStatusCode(),
            'Soft-deleted pages should not be returned by the API (expected 404).'
        );
    }

    /**
     * Generate a UUIDv4 string.
     *
     * @return string
     */
    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // Version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // Variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
