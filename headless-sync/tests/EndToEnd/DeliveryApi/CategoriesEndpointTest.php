<?php

namespace HSP\Tests\EndToEnd\DeliveryApi;

use HSP\Tests\EndToEnd\BaseEndToEndTestCase;

/**
 * Tests that verify the Delivery API /api/v1/categories endpoint.
 *
 * These tests insert data directly into the PostgreSQL content.taxonomies table
 * and query the Delivery API to verify slug lookups, 404 handling, and
 * filtering of soft-deleted categories.
 */
class CategoriesEndpointTest extends BaseEndToEndTestCase
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
     * Verify that a category can be retrieved by slug via the Delivery API.
     */
    public function testGetCategoryBySlug(): void
    {
        $pdo = $this->getPostgresPdo();
        $categoryId = $this->generateUuidV4();

        // Insert a category directly into PostgreSQL
        $stmt = $pdo->prepare(
            "INSERT INTO content.taxonomies (id, source_term_id, taxonomy_type, slug, name, description, created_at, updated_at)
             VALUES (:id, :source_term_id, 'category', :slug, :name, :description, NOW(), NOW())"
        );
        $stmt->execute([
            'id'             => $categoryId,
            'source_term_id' => '66601',
            'slug'           => 'e2e-delivery-cat-slug',
            'name'           => 'E2E Delivery Category',
            'description'    => 'E2E Delivery Category Description',
        ]);

        // Query the Delivery API
        $client = $this->getDeliveryApiClient();
        $response = $client->get('api/v1/categories', [
            'query' => ['slug' => 'e2e-delivery-cat-slug'],
        ]);

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertIsArray($body);
        $this->assertEquals($categoryId, $body['id']);
        $this->assertEquals('66601', $body['source_term_id']);
        $this->assertEquals('category', $body['taxonomy_type']);
        $this->assertEquals('e2e-delivery-cat-slug', $body['slug']);
        $this->assertEquals('E2E Delivery Category', $body['name']);
        $this->assertEquals('E2E Delivery Category Description', $body['description']);
    }

    /**
     * Verify that querying a non-existent category slug returns 404.
     */
    public function testGetCategoryBySlugReturns404ForMissing(): void
    {
        $client = $this->getDeliveryApiClient();
        $response = $client->get('api/v1/categories', [
            'query' => ['slug' => 'nonexistent-cat-slug-' . uniqid()],
        ]);

        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * Verify that categories with a non-null deleted_at are NOT returned by the API.
     */
    public function testDeletedCategoriesAreFiltered(): void
    {
        $pdo = $this->getPostgresPdo();
        $categoryId = $this->generateUuidV4();

        // Insert a soft-deleted category
        $stmt = $pdo->prepare(
            "INSERT INTO content.taxonomies (id, source_term_id, taxonomy_type, slug, name, description, created_at, updated_at, deleted_at)
             VALUES (:id, :source_term_id, 'category', :slug, :name, :description, NOW(), NOW(), NOW())"
        );
        $stmt->execute([
            'id'             => $categoryId,
            'source_term_id' => '66602',
            'slug'           => 'e2e-deleted-cat-slug',
            'name'           => 'E2E Deleted Category',
            'description'    => 'Should not appear in API',
        ]);

        // Query the Delivery API — should return 404
        $client = $this->getDeliveryApiClient();
        $response = $client->get('api/v1/categories', [
            'query' => ['slug' => 'e2e-deleted-cat-slug'],
        ]);

        $this->assertEquals(
            404,
            $response->getStatusCode(),
            'Soft-deleted categories should not be returned by the API (expected 404).'
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
