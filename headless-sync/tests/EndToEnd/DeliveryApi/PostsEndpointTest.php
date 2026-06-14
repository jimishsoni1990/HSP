<?php

namespace HSP\Tests\EndToEnd\DeliveryApi;

use HSP\Tests\EndToEnd\BaseEndToEndTestCase;

/**
 * Tests that verify the Delivery API /api/v1/posts endpoint.
 *
 * These tests insert data directly into PostgreSQL content tables and query the
 * Delivery API to verify correct filtering, slug lookups, category associations,
 * and exclusion of deleted/draft posts.
 */
class PostsEndpointTest extends BaseEndToEndTestCase
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
     * Verify that a post can be retrieved by slug via the Delivery API.
     */
    public function testGetPostBySlug(): void
    {
        $pdo = $this->getPostgresPdo();
        $postId = $this->generateUuidV4();

        // Insert a published post directly into PostgreSQL
        $stmt = $pdo->prepare(
            "INSERT INTO content.posts (id, source_post_id, source_entity_type, slug, title, excerpt, content, status, created_at, updated_at)
             VALUES (:id, :source_post_id, 'post', :slug, :title, :excerpt, :content, 'publish', NOW(), NOW())"
        );
        $stmt->execute([
            'id'             => $postId,
            'source_post_id' => '99901',
            'slug'           => 'e2e-delivery-post-slug',
            'title'          => 'E2E Delivery Post Title',
            'excerpt'        => 'E2E Delivery Post Excerpt',
            'content'        => 'E2E Delivery Post Content',
        ]);

        // Query the Delivery API
        $client = $this->getDeliveryApiClient();
        $response = $client->get('api/v1/posts', [
            'query' => ['slug' => 'e2e-delivery-post-slug'],
        ]);

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertIsArray($body);
        $this->assertEquals($postId, $body['id']);
        $this->assertEquals('99901', $body['source_post_id']);
        $this->assertEquals('e2e-delivery-post-slug', $body['slug']);
        $this->assertEquals('E2E Delivery Post Title', $body['title']);
        $this->assertEquals('E2E Delivery Post Excerpt', $body['excerpt']);
        $this->assertEquals('publish', $body['status']);
    }

    /**
     * Verify that querying a non-existent slug returns 404.
     */
    public function testGetPostBySlugReturns404ForMissing(): void
    {
        $client = $this->getDeliveryApiClient();
        $response = $client->get('api/v1/posts', [
            'query' => ['slug' => 'nonexistent-post-slug-' . uniqid()],
        ]);

        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * Verify that posts can be retrieved by category slug.
     *
     * Inserts a post, a category, and the entity_taxonomy relationship, then
     * queries /api/v1/posts?category=<slug> to verify the post is returned.
     */
    public function testGetPostsByCategorySlug(): void
    {
        $pdo = $this->getPostgresPdo();
        $postId = $this->generateUuidV4();
        $categoryId = $this->generateUuidV4();

        // Insert the post
        $stmt = $pdo->prepare(
            "INSERT INTO content.posts (id, source_post_id, source_entity_type, slug, title, excerpt, content, status, created_at, updated_at)
             VALUES (:id, :source_post_id, 'post', :slug, :title, :excerpt, :content, 'publish', NOW(), NOW())"
        );
        $stmt->execute([
            'id'             => $postId,
            'source_post_id' => '99902',
            'slug'           => 'e2e-cat-filter-post',
            'title'          => 'E2E Category Filter Post',
            'excerpt'        => 'Excerpt',
            'content'        => 'Content',
        ]);

        // Insert the category
        $stmt = $pdo->prepare(
            "INSERT INTO content.taxonomies (id, source_term_id, taxonomy_type, slug, name, description, created_at, updated_at)
             VALUES (:id, :source_term_id, 'category', :slug, :name, :description, NOW(), NOW())"
        );
        $stmt->execute([
            'id'             => $categoryId,
            'source_term_id' => '88801',
            'slug'           => 'e2e-test-cat',
            'name'           => 'E2E Test Category',
            'description'    => 'E2E Test Category Description',
        ]);

        // Insert the entity_taxonomy relationship
        $stmt = $pdo->prepare(
            "INSERT INTO content.entity_taxonomies (entity_id, taxonomy_id)
             VALUES (:entity_id, :taxonomy_id)"
        );
        $stmt->execute([
            'entity_id'   => $postId,
            'taxonomy_id' => $categoryId,
        ]);

        // Query the Delivery API by category slug
        $client = $this->getDeliveryApiClient();
        $response = $client->get('api/v1/posts', [
            'query' => ['category' => 'e2e-test-cat'],
        ]);

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertIsArray($body);
        $this->assertNotEmpty($body, 'Category filter should return at least one post.');

        // Find our post in the results
        $found = false;
        foreach ($body as $post) {
            if ($post['id'] === $postId) {
                $found = true;
                $this->assertEquals('e2e-cat-filter-post', $post['slug']);
                break;
            }
        }
        $this->assertTrue($found, "Post '{$postId}' should appear in category filter results.");
    }

    /**
     * Verify that posts with a non-null deleted_at are NOT returned by the API.
     */
    public function testDeletedPostsAreFiltered(): void
    {
        $pdo = $this->getPostgresPdo();
        $postId = $this->generateUuidV4();

        // Insert a soft-deleted post
        $stmt = $pdo->prepare(
            "INSERT INTO content.posts (id, source_post_id, source_entity_type, slug, title, excerpt, content, status, created_at, updated_at, deleted_at)
             VALUES (:id, :source_post_id, 'post', :slug, :title, :excerpt, :content, 'trash', NOW(), NOW(), NOW())"
        );
        $stmt->execute([
            'id'             => $postId,
            'source_post_id' => '99903',
            'slug'           => 'e2e-deleted-post-slug',
            'title'          => 'E2E Deleted Post',
            'excerpt'        => 'Excerpt',
            'content'        => 'Content',
        ]);

        // Query the Delivery API — should return 404
        $client = $this->getDeliveryApiClient();
        $response = $client->get('api/v1/posts', [
            'query' => ['slug' => 'e2e-deleted-post-slug'],
        ]);

        $this->assertEquals(
            404,
            $response->getStatusCode(),
            'Soft-deleted posts should not be returned by the API (expected 404).'
        );
    }

    /**
     * Verify that posts with status='draft' are NOT returned by the API.
     */
    public function testDraftPostsAreFiltered(): void
    {
        $pdo = $this->getPostgresPdo();
        $postId = $this->generateUuidV4();

        // Insert a draft post
        $stmt = $pdo->prepare(
            "INSERT INTO content.posts (id, source_post_id, source_entity_type, slug, title, excerpt, content, status, created_at, updated_at)
             VALUES (:id, :source_post_id, 'post', :slug, :title, :excerpt, :content, 'draft', NOW(), NOW())"
        );
        $stmt->execute([
            'id'             => $postId,
            'source_post_id' => '99904',
            'slug'           => 'e2e-draft-post-slug',
            'title'          => 'E2E Draft Post',
            'excerpt'        => 'Excerpt',
            'content'        => 'Content',
        ]);

        // Query the Delivery API — should return 404
        $client = $this->getDeliveryApiClient();
        $response = $client->get('api/v1/posts', [
            'query' => ['slug' => 'e2e-draft-post-slug'],
        ]);

        $this->assertEquals(
            404,
            $response->getStatusCode(),
            'Draft posts should not be returned by the API (expected 404).'
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
