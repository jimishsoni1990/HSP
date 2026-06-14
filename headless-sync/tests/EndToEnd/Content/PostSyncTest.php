<?php

namespace HSP\Tests\EndToEnd\Content;

use HSP\Tests\EndToEnd\BaseEndToEndTestCase;

class PostSyncTest extends BaseEndToEndTestCase
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
     * Helper to create a WordPress Post.
     */
    private function createPost(array $data): array
    {
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post('wp/v2/posts', [
            'json' => $data
        ]);
        
        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true);
        $this->trackPost($body['id']);
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
     * Post Case 1: Create Published Post
     */
    public function testCreatePublishedPost(): void
    {
        // 1. Trigger
        $postData = [
            'title' => 'E2E Post Create Title',
            'content' => 'E2E Post Create Content',
            'excerpt' => 'E2E Post Create Excerpt',
            'slug' => 'e2e-post-create-slug',
            'status' => 'publish'
        ];
        $wpPost = $this->createPost($postData);
        $wpPostId = $wpPost['id'];

        // 2. Intermediate DB Checks
        $this->assertEventAndJob('post', $wpPostId, 'content.post.created', 1);

        // 3. Worker Execution
        $this->runWorker();

        // 4. Projection DB Assertions
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("SELECT * FROM content.posts WHERE source_post_id = :id");
        $stmt->execute(['id' => (string)$wpPostId]);
        $post = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertNotEmpty($post, 'Post projection not found in content.posts');
        $this->assertEquals('post', $post['source_entity_type']);
        $this->assertEquals('e2e-post-create-slug', $post['slug']);
        $this->assertEquals('E2E Post Create Title', $post['title']);
        $this->assertEquals('E2E Post Create Excerpt', $post['excerpt']);
        $this->assertEquals('publish', $post['status']);
        $this->assertNull($post['deleted_at']);

        // 5. REST Delivery API Verification
        $deliveryClient = $this->getDeliveryApiClient();
        $response = $deliveryClient->get('api/v1/posts', [
            'query' => ['slug' => 'e2e-post-create-slug']
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        
        $apiData = json_decode($response->getBody()->getContents(), true);
        $this->assertEquals($post['id'], $apiData['id']);
        $this->assertEquals((string)$wpPostId, (string)$apiData['source_post_id']);
        $this->assertEquals('e2e-post-create-slug', $apiData['slug']);
        $this->assertEquals('E2E Post Create Title', $apiData['title']);
        $this->assertEquals('E2E Post Create Excerpt', $apiData['excerpt']);
        $this->assertEquals('publish', $apiData['status']);
    }

    /**
     * Post Case 2: Update Published Post
     */
    public function testUpdatePublishedPost(): void
    {
        // 1. Initial Create
        $wpPost = $this->createPost([
            'title' => 'E2E Post Create Title',
            'content' => 'E2E Post Create Content',
            'excerpt' => 'E2E Post Create Excerpt',
            'slug' => 'e2e-post-create-slug',
            'status' => 'publish'
        ]);
        $wpPostId = $wpPost['id'];
        $this->runWorker();

        // 2. Trigger
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post("wp/v2/posts/{$wpPostId}", [
            'json' => [
                'title' => 'E2E Post Updated Title',
                'content' => 'E2E Post Updated Content'
            ]
        ]);
        $this->assertEquals(200, $response->getStatusCode());

        // 3. Intermediate DB Checks
        $this->assertEventAndJob('post', $wpPostId, 'content.post.updated', 2);

        // 4. Worker Execution
        $this->runWorker();

        // 5. Projection DB Assertions
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("SELECT * FROM content.posts WHERE source_post_id = :id");
        $stmt->execute(['id' => (string)$wpPostId]);
        $post = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($post, 'Post projection not found in content.posts');
        $this->assertEquals('E2E Post Updated Title', $post['title']);
        $this->assertEquals('publish', $post['status']);
        $this->assertNull($post['deleted_at']);

        // 6. REST Delivery API Verification
        $deliveryClient = $this->getDeliveryApiClient();
        $response = $deliveryClient->get('api/v1/posts', [
            'query' => ['slug' => 'e2e-post-create-slug']
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        
        $apiData = json_decode($response->getBody()->getContents(), true);
        $this->assertEquals('E2E Post Updated Title', $apiData['title']);
        $this->assertEquals('publish', $apiData['status']);
    }

    /**
     * Post Case 3: Move Post to Trash (Soft Delete)
     */
    public function testMovePostToTrash(): void
    {
        // 1. Initial Create
        $wpPost = $this->createPost([
            'title' => 'E2E Post Create Title',
            'content' => 'E2E Post Create Content',
            'excerpt' => 'E2E Post Create Excerpt',
            'slug' => 'e2e-post-create-slug',
            'status' => 'publish'
        ]);
        $wpPostId = $wpPost['id'];
        $this->runWorker();

        // 2. Trigger (Trash)
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->delete("wp/v2/posts/{$wpPostId}");
        $this->assertEquals(200, $response->getStatusCode());

        // 3. Intermediate DB Checks
        $this->assertEventAndJob('post', $wpPostId, 'content.post.updated', 2);

        // 4. Worker Execution
        $this->runWorker();

        // 5. Projection DB Assertions
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("SELECT * FROM content.posts WHERE source_post_id = :id");
        $stmt->execute(['id' => (string)$wpPostId]);
        $post = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($post, 'Post projection not found in content.posts');
        $this->assertEquals('trash', $post['status']);
        $this->assertNotNull($post['deleted_at']);

        // 6. REST Delivery API Verification
        $deliveryClient = $this->getDeliveryApiClient();
        $response = $deliveryClient->get('api/v1/posts', [
            'query' => ['slug' => 'e2e-post-create-slug']
        ]);
        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * Post Case 4: Restore Post from Trash
     */
    public function testRestorePostFromTrash(): void
    {
        // 1. Initial Create
        $wpPost = $this->createPost([
            'title' => 'E2E Post Create Title',
            'content' => 'E2E Post Create Content',
            'excerpt' => 'E2E Post Create Excerpt',
            'slug' => 'e2e-post-create-slug',
            'status' => 'publish'
        ]);
        $wpPostId = $wpPost['id'];
        $this->runWorker();

        // 2. Trash the post
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->delete("wp/v2/posts/{$wpPostId}");
        $this->assertEquals(200, $response->getStatusCode());
        $this->runWorker();

        // 3. Trigger (Restore)
        $response = $wpClient->post("wp/v2/posts/{$wpPostId}", [
            'json' => [
                'status' => 'publish'
            ]
        ]);
        $this->assertEquals(200, $response->getStatusCode());

        // 4. Intermediate DB Checks
        $this->assertEventAndJob('post', $wpPostId, 'content.post.updated', 3);

        // 5. Worker Execution
        $this->runWorker();

        // 6. Projection DB Assertions
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("SELECT * FROM content.posts WHERE source_post_id = :id");
        $stmt->execute(['id' => (string)$wpPostId]);
        $post = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($post, 'Post projection not found in content.posts');
        $this->assertEquals('publish', $post['status']);
        $this->assertNull($post['deleted_at']);

        // 7. REST Delivery API Verification
        $deliveryClient = $this->getDeliveryApiClient();
        $response = $deliveryClient->get('api/v1/posts', [
            'query' => ['slug' => 'e2e-post-create-slug']
        ]);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Post Case 5: Force Delete Post (Hard Delete)
     */
    public function testForceDeletePost(): void
    {
        // 1. Initial Create
        $wpPost = $this->createPost([
            'title' => 'E2E Post Create Title',
            'content' => 'E2E Post Create Content',
            'excerpt' => 'E2E Post Create Excerpt',
            'slug' => 'e2e-post-create-slug',
            'status' => 'publish'
        ]);
        $wpPostId = $wpPost['id'];
        $this->runWorker();

        // 2. Trigger (Force Delete)
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->delete("wp/v2/posts/{$wpPostId}", [
            'query' => ['force' => 'true']
        ]);
        $this->assertEquals(200, $response->getStatusCode());

        // 3. Intermediate DB Checks
        $this->assertEventAndJob('post', $wpPostId, 'content.post.deleted', 2);

        // 4. Worker Execution
        $this->runWorker();

        // 5. Projection DB Assertions
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("SELECT * FROM content.posts WHERE source_post_id = :id");
        $stmt->execute(['id' => (string)$wpPostId]);
        $post = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertFalse($post, 'Post projection should be completely purged from content.posts');

        // 6. REST Delivery API Verification
        $deliveryClient = $this->getDeliveryApiClient();
        $response = $deliveryClient->get('api/v1/posts', [
            'query' => ['slug' => 'e2e-post-create-slug']
        ]);
        $this->assertEquals(404, $response->getStatusCode());
    }
}
