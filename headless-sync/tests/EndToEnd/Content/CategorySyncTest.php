<?php

namespace HSP\Tests\EndToEnd\Content;

use HSP\Tests\EndToEnd\BaseEndToEndTestCase;

class CategorySyncTest extends BaseEndToEndTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Skip tests if connectivity or databases are not available
        if ($this->getPostgresPdo() === null) {
            $this->markTestSkipped('PostgreSQL connection is not available.');
        }

        try {
            $response = $this->getWordPressClient()->get('');
            if ($response->getStatusCode() >= 500) {
                $this->markTestSkipped('WordPress API returned a server error.');
            }
        } catch (\Exception $e) {
            $this->markTestSkipped('WordPress API is not reachable.');
        }
    }

    /**
     * Helper to create a WordPress Category.
     */
    private function createCategory(array $data): array
    {
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post('wp/v2/categories', [
            'json' => $data
        ]);
        
        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true);
        $this->trackTerm($body['id']);
        return $body;
    }

    /**
     * Helper to assert system.events and system.queue_jobs.
     */
    private function assertEventAndJob(string $aggregateType, string $aggregateId, string $eventType, int $expectedVersion): array
    {
        $pdo = $this->getPostgresPdo();
        
        // Query system.events
        $stmt = $pdo->prepare("
            SELECT * FROM system.events 
            WHERE aggregate_type = :aggregate_type 
              AND aggregate_id = :aggregate_id 
              AND event_type = :event_type 
              AND aggregate_version = :version
        ");
        $stmt->execute([
            'aggregate_type' => $aggregateType,
            'aggregate_id' => (string)$aggregateId,
            'event_type' => $eventType,
            'version' => $expectedVersion
        ]);
        $event = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($event, "Failed to find event of type {$eventType} for version {$expectedVersion}");

        // Query system.queue_jobs
        $stmt = $pdo->prepare("
            SELECT * FROM system.queue_jobs 
            WHERE event_id = :event_id
        ");
        $stmt->execute(['event_id' => $event['id']]);
        $job = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($job, "Failed to find queue job for event ID {$event['id']}");
        $this->assertEquals('content', $job['queue_name']);
        $this->assertEquals('queued', $job['status']);

        return [$event, $job];
    }

    /**
     * Helper to run CLI worker.
     */
    private function runWorker(): void
    {
        $process = $this->runWpCli(['headless-sync', 'worker', 'run', '--queue=content']);
        $this->assertTrue($process->isSuccessful(), "Worker CLI run failed: " . $process->getErrorOutput());
    }

    /**
     * Category Case 1: Create Category
     */
    public function testCreateCategory(): void
    {
        // 1. Trigger
        $categoryData = [
            'name' => 'E2E Category',
            'slug' => 'e2e-category-slug',
            'description' => 'E2E Category Description'
        ];
        $wpCategory = $this->createCategory($categoryData);
        $wpCategoryId = $wpCategory['id'];

        // 2. Intermediate DB Checks
        $this->assertEventAndJob('category', $wpCategoryId, 'content.category.created', 1);

        // 3. Worker Execution
        $this->runWorker();

        // 4. Projection DB Assertions
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("SELECT * FROM content.taxonomies WHERE source_term_id = :id");
        $stmt->execute(['id' => (string)$wpCategoryId]);
        $category = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($category, 'Category projection not found in content.taxonomies');
        $this->assertEquals('category', $category['taxonomy_type']);
        $this->assertEquals('e2e-category-slug', $category['slug']);
        $this->assertEquals('E2E Category', $category['name']);
        $this->assertEquals('E2E Category Description', $category['description']);
        $this->assertNull($category['deleted_at']);

        // 5. REST Delivery API Verification
        $deliveryClient = $this->getDeliveryApiClient();
        $response = $deliveryClient->get('api/v1/categories', [
            'query' => ['slug' => 'e2e-category-slug']
        ]);
        $this->assertEquals(200, $response->getStatusCode());

        $apiData = json_decode($response->getBody()->getContents(), true);
        $this->assertEquals($category['id'], $apiData['id']);
        $this->assertEquals((string)$wpCategoryId, (string)$apiData['source_term_id']);
        $this->assertEquals('category', $apiData['taxonomy_type']);
        $this->assertEquals('e2e-category-slug', $apiData['slug']);
        $this->assertEquals('E2E Category', $apiData['name']);
        $this->assertEquals('E2E Category Description', $apiData['description']);
    }

    /**
     * Category Case 2: Update Category
     */
    public function testUpdateCategory(): void
    {
        // 1. Initial Create
        $wpCategory = $this->createCategory([
            'name' => 'E2E Category',
            'slug' => 'e2e-category-slug',
            'description' => 'E2E Category Description'
        ]);
        $wpCategoryId = $wpCategory['id'];
        $this->runWorker();

        // 2. Trigger
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post("wp/v2/categories/{$wpCategoryId}", [
            'json' => [
                'name' => 'E2E Category Updated',
                'description' => 'E2E Category Updated Description'
            ]
        ]);
        $this->assertEquals(200, $response->getStatusCode());

        // 3. Intermediate DB Checks
        $this->assertEventAndJob('category', $wpCategoryId, 'content.category.updated', 2);

        // 4. Worker Execution
        $this->runWorker();

        // 5. Projection DB Assertions
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("SELECT * FROM content.taxonomies WHERE source_term_id = :id");
        $stmt->execute(['id' => (string)$wpCategoryId]);
        $category = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($category, 'Category projection not found in content.taxonomies');
        $this->assertEquals('E2E Category Updated', $category['name']);
        $this->assertEquals('E2E Category Updated Description', $category['description']);

        // 6. REST Delivery API Verification
        $deliveryClient = $this->getDeliveryApiClient();
        $response = $deliveryClient->get('api/v1/categories', [
            'query' => ['slug' => 'e2e-category-slug']
        ]);
        $this->assertEquals(200, $response->getStatusCode());

        $apiData = json_decode($response->getBody()->getContents(), true);
        $this->assertEquals('E2E Category Updated', $apiData['name']);
        $this->assertEquals('E2E Category Updated Description', $apiData['description']);
    }

    /**
     * Category Case 3: Associate Post with Category
     */
    public function testAssociatePostWithCategory(): void
    {
        // 1. Create Category
        $wpCategory = $this->createCategory([
            'name' => 'E2E Category',
            'slug' => 'e2e-category-slug',
            'description' => 'E2E Category Description'
        ]);
        $wpCategoryId = $wpCategory['id'];
        $this->runWorker();

        // 2. Create Post
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post('wp/v2/posts', [
            'json' => [
                'title' => 'E2E Post Title',
                'content' => 'E2E Post Content',
                'slug' => 'e2e-post-slug',
                'status' => 'publish'
            ]
        ]);
        $this->assertEquals(201, $response->getStatusCode());
        $wpPost = json_decode($response->getBody()->getContents(), true);
        $wpPostId = $wpPost['id'];
        $this->trackPost($wpPostId);
        $this->runWorker();

        // 3. Trigger: Associate Post with Category
        $response = $wpClient->post("wp/v2/posts/{$wpPostId}", [
            'json' => [
                'categories' => [$wpCategoryId]
            ]
        ]);
        $this->assertEquals(200, $response->getStatusCode());

        // 4. Intermediate DB Checks
        $this->assertEventAndJob('post', $wpPostId, 'content.post.updated', 2);

        // 5. Worker Execution
        $this->runWorker();

        // 6. Projection DB Assertions
        $pdo = $this->getPostgresPdo();
        
        // Get post UUID
        $stmt = $pdo->prepare("SELECT id FROM content.posts WHERE source_post_id = :id");
        $stmt->execute(['id' => (string)$wpPostId]);
        $postRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($postRow);
        $postUuid = $postRow['id'];

        // Get taxonomy UUID
        $stmt = $pdo->prepare("SELECT id FROM content.taxonomies WHERE source_term_id = :id");
        $stmt->execute(['id' => (string)$wpCategoryId]);
        $taxRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($taxRow);
        $taxUuid = $taxRow['id'];

        // Query content.entity_taxonomies
        $stmt = $pdo->prepare("SELECT * FROM content.entity_taxonomies WHERE entity_id = :entity_id AND taxonomy_id = :taxonomy_id");
        $stmt->execute([
            'entity_id' => $postUuid,
            'taxonomy_id' => $taxUuid
        ]);
        $relation = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($relation, 'Relationship not found in content.entity_taxonomies');

        // 7. REST Delivery API Verification
        $deliveryClient = $this->getDeliveryApiClient();
        
        // 7a. GET /api/v1/posts?slug=<post_slug>
        $response = $deliveryClient->get('api/v1/posts', [
            'query' => ['slug' => 'e2e-post-slug']
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        $apiData = json_decode($response->getBody()->getContents(), true);
        
        $this->assertNotEmpty($apiData['categories']);
        $this->assertEquals($taxUuid, $apiData['categories'][0]['id']);
        $this->assertEquals('e2e-category-slug', $apiData['categories'][0]['slug']);
        $this->assertEquals('E2E Category', $apiData['categories'][0]['name']);

        // 7b. GET /api/v1/posts?category=e2e-category-slug
        $response = $deliveryClient->get('api/v1/posts', [
            'query' => ['category' => 'e2e-category-slug']
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        $listData = json_decode($response->getBody()->getContents(), true);
        
        $this->assertIsArray($listData);
        $this->assertGreaterThanOrEqual(1, count($listData));
        $this->assertEquals($postUuid, $listData[0]['id']);
    }

    /**
     * Category Case 4: Change Category Association
     */
    public function testChangeCategoryAssociation(): void
    {
        // 1. Create Category 1
        $wpCategory1 = $this->createCategory([
            'name' => 'E2E Category 1',
            'slug' => 'e2e-category-slug-1',
            'description' => 'E2E Category 1 Description'
        ]);
        $wpCategoryId1 = $wpCategory1['id'];
        $this->runWorker();

        // 2. Create Category 2
        $wpCategory2 = $this->createCategory([
            'name' => 'E2E Category 2',
            'slug' => 'e2e-category-slug-2',
            'description' => 'E2E Category 2 Description'
        ]);
        $wpCategoryId2 = $wpCategory2['id'];
        $this->runWorker();

        // 3. Create Post associated with Category 1
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post('wp/v2/posts', [
            'json' => [
                'title' => 'E2E Post Title',
                'content' => 'E2E Post Content',
                'slug' => 'e2e-post-slug',
                'status' => 'publish',
                'categories' => [$wpCategoryId1]
            ]
        ]);
        $this->assertEquals(201, $response->getStatusCode());
        $wpPost = json_decode($response->getBody()->getContents(), true);
        $wpPostId = $wpPost['id'];
        $this->trackPost($wpPostId);
        $this->runWorker();

        // 4. Trigger: Update post to change association to Category 2
        $response = $wpClient->post("wp/v2/posts/{$wpPostId}", [
            'json' => [
                'categories' => [$wpCategoryId2]
            ]
        ]);
        $this->assertEquals(200, $response->getStatusCode());

        // 5. Intermediate DB Checks
        $this->assertEventAndJob('post', $wpPostId, 'content.post.updated', 2);

        // 6. Worker Execution
        $this->runWorker();

        // 7. Projection DB Assertions
        $pdo = $this->getPostgresPdo();

        // Get UUIDs
        $stmt = $pdo->prepare("SELECT id FROM content.posts WHERE source_post_id = :id");
        $stmt->execute(['id' => (string)$wpPostId]);
        $postUuid = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT id FROM content.taxonomies WHERE source_term_id = :id");
        $stmt->execute(['id' => (string)$wpCategoryId1]);
        $taxUuid1 = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT id FROM content.taxonomies WHERE source_term_id = :id");
        $stmt->execute(['id' => (string)$wpCategoryId2]);
        $taxUuid2 = $stmt->fetchColumn();

        // Query content.entity_taxonomies
        // Category 1 relationship should NOT exist
        $stmt = $pdo->prepare("SELECT * FROM content.entity_taxonomies WHERE entity_id = :entity_id AND taxonomy_id = :taxonomy_id");
        $stmt->execute(['entity_id' => $postUuid, 'taxonomy_id' => $taxUuid1]);
        $relation1 = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertFalse($relation1, 'Relationship with Category 1 should be deleted');

        // Category 2 relationship should exist
        $stmt = $pdo->prepare("SELECT * FROM content.entity_taxonomies WHERE entity_id = :entity_id AND taxonomy_id = :taxonomy_id");
        $stmt->execute(['entity_id' => $postUuid, 'taxonomy_id' => $taxUuid2]);
        $relation2 = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($relation2, 'Relationship with Category 2 should exist');

        // 8. REST Delivery API Verification
        $deliveryClient = $this->getDeliveryApiClient();

        // GET /api/v1/posts?category=e2e-category-slug-1 (should NOT return the post)
        $response = $deliveryClient->get('api/v1/posts', [
            'query' => ['category' => 'e2e-category-slug-1']
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        $listData1 = json_decode($response->getBody()->getContents(), true);
        $this->assertIsArray($listData1);
        foreach ($listData1 as $item) {
            $this->assertNotEquals($postUuid, $item['id']);
        }

        // GET /api/v1/posts?category=e2e-category-slug-2 (should return the post)
        $response = $deliveryClient->get('api/v1/posts', [
            'query' => ['category' => 'e2e-category-slug-2']
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        $listData2 = json_decode($response->getBody()->getContents(), true);
        $this->assertIsArray($listData2);
        $this->assertGreaterThanOrEqual(1, count($listData2));
        $this->assertEquals($postUuid, $listData2[0]['id']);
    }

    /**
     * Category Case 5: Delete Category
     */
    public function testDeleteCategory(): void
    {
        // 1. Create Category
        $wpCategory = $this->createCategory([
            'name' => 'E2E Category',
            'slug' => 'e2e-category-slug',
            'description' => 'E2E Category Description'
        ]);
        $wpCategoryId = $wpCategory['id'];
        $this->runWorker();

        // 2. Create Post associated with Category
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post('wp/v2/posts', [
            'json' => [
                'title' => 'E2E Post Title',
                'content' => 'E2E Post Content',
                'slug' => 'e2e-post-slug',
                'status' => 'publish',
                'categories' => [$wpCategoryId]
            ]
        ]);
        $this->assertEquals(201, $response->getStatusCode());
        $wpPost = json_decode($response->getBody()->getContents(), true);
        $wpPostId = $wpPost['id'];
        $this->trackPost($wpPostId);
        $this->runWorker();

        // 3. Trigger: Delete category permanently via WordPress REST API
        $response = $wpClient->delete("wp/v2/categories/{$wpCategoryId}", [
            'query' => ['force' => 'true']
        ]);
        $this->assertEquals(200, $response->getStatusCode());

        // 4. Intermediate DB Checks
        $this->assertEventAndJob('category', $wpCategoryId, 'content.category.deleted', 2);

        // 5. Worker Execution
        $this->runWorker();

        // 6. Projection DB Assertions
        $pdo = $this->getPostgresPdo();

        // Query content.taxonomies: deleted_at should not be null
        $stmt = $pdo->prepare("SELECT * FROM content.taxonomies WHERE source_term_id = :id");
        $stmt->execute(['id' => (string)$wpCategoryId]);
        $category = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($category);
        $this->assertNotNull($category['deleted_at']);
        $taxUuid = $category['id'];

        // Query content.entity_taxonomies: relation row should be cleaned up (deleted)
        $stmt = $pdo->prepare("SELECT id FROM content.posts WHERE source_post_id = :id");
        $stmt->execute(['id' => (string)$wpPostId]);
        $postUuid = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM content.entity_taxonomies WHERE entity_id = :entity_id AND taxonomy_id = :taxonomy_id");
        $stmt->execute([
            'entity_id' => $postUuid,
            'taxonomy_id' => $taxUuid
        ]);
        $relation = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertFalse($relation, 'Relationship should be cleaned up from content.entity_taxonomies');

        // 7. REST Delivery API Verification
        $deliveryClient = $this->getDeliveryApiClient();

        // GET /api/v1/categories?slug=e2e-category-slug (should return 404)
        $response = $deliveryClient->get('api/v1/categories', [
            'query' => ['slug' => 'e2e-category-slug']
        ]);
        $this->assertEquals(404, $response->getStatusCode());
    }
}
