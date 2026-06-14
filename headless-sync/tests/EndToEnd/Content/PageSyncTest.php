<?php

namespace HSP\Tests\EndToEnd\Content;

use HSP\Tests\EndToEnd\BaseEndToEndTestCase;

class PageSyncTest extends BaseEndToEndTestCase
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
     * Helper to create a WordPress Page.
     */
    private function createPage(array $data): array
    {
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post('wp/v2/pages', [
            'json' => $data
        ]);
        
        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true);
        $this->trackPost($body['id']); // Pages are tracked as posts for cleanup
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
     * Page Case 1: Create Published Page
     */
    public function testCreatePublishedPage(): void
    {
        // 1. Trigger
        $pageData = [
            'title' => 'E2E Page Create Title',
            'content' => 'E2E Page Create Content',
            'slug' => 'e2e-page-create-slug',
            'status' => 'publish'
        ];
        $wpPage = $this->createPage($pageData);
        $wpPageId = $wpPage['id'];

        // 2. Intermediate DB Checks
        $this->assertEventAndJob('page', $wpPageId, 'content.page.created', 1);

        // 3. Worker Execution
        $this->runWorker();

        // 4. Projection DB Assertions
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("SELECT * FROM content.pages WHERE source_post_id = :id");
        $stmt->execute(['id' => (string)$wpPageId]);
        $page = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($page, 'Page projection not found in content.pages');
        $this->assertEquals('page', $page['source_entity_type']);
        $this->assertEquals('e2e-page-create-slug', $page['slug']);
        $this->assertEquals('E2E Page Create Title', $page['title']);
        $this->assertEquals('publish', $page['status']);
        $this->assertNull($page['deleted_at']);

        // 5. REST Delivery API Verification
        $deliveryClient = $this->getDeliveryApiClient();
        $response = $deliveryClient->get('api/v1/pages', [
            'query' => ['slug' => 'e2e-page-create-slug']
        ]);
        $this->assertEquals(200, $response->getStatusCode());

        $apiData = json_decode($response->getBody()->getContents(), true);
        $this->assertEquals($page['id'], $apiData['id']);
        $this->assertEquals((string)$wpPageId, (string)$apiData['source_post_id']);
        $this->assertEquals('e2e-page-create-slug', $apiData['slug']);
        $this->assertEquals('E2E Page Create Title', $apiData['title']);
        $this->assertEquals('publish', $apiData['status']);
    }

    /**
     * Page Case 2: Update Published Page
     */
    public function testUpdatePublishedPage(): void
    {
        // 1. Initial Create
        $wpPage = $this->createPage([
            'title' => 'E2E Page Create Title',
            'content' => 'E2E Page Create Content',
            'slug' => 'e2e-page-create-slug',
            'status' => 'publish'
        ]);
        $wpPageId = $wpPage['id'];
        $this->runWorker();

        // 2. Trigger
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post("wp/v2/pages/{$wpPageId}", [
            'json' => [
                'title' => 'E2E Page Updated Title',
                'content' => 'E2E Page Updated Content'
            ]
        ]);
        $this->assertEquals(200, $response->getStatusCode());

        // 3. Intermediate DB Checks
        $this->assertEventAndJob('page', $wpPageId, 'content.page.updated', 2);

        // 4. Worker Execution
        $this->runWorker();

        // 5. Projection DB Assertions
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("SELECT * FROM content.pages WHERE source_post_id = :id");
        $stmt->execute(['id' => (string)$wpPageId]);
        $page = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($page, 'Page projection not found in content.pages');
        $this->assertEquals('E2E Page Updated Title', $page['title']);
        $this->assertEquals('publish', $page['status']);
        $this->assertNull($page['deleted_at']);

        // 6. REST Delivery API Verification
        $deliveryClient = $this->getDeliveryApiClient();
        $response = $deliveryClient->get('api/v1/pages', [
            'query' => ['slug' => 'e2e-page-create-slug']
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        
        $apiData = json_decode($response->getBody()->getContents(), true);
        $this->assertEquals('E2E Page Updated Title', $apiData['title']);
    }

    /**
     * Page Case 3: Move Page to Trash (Soft Delete)
     */
    public function testMovePageToTrash(): void
    {
        // 1. Initial Create
        $wpPage = $this->createPage([
            'title' => 'E2E Page Create Title',
            'content' => 'E2E Page Create Content',
            'slug' => 'e2e-page-create-slug',
            'status' => 'publish'
        ]);
        $wpPageId = $wpPage['id'];
        $this->runWorker();

        // 2. Trigger (Trash)
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->delete("wp/v2/pages/{$wpPageId}");
        $this->assertEquals(200, $response->getStatusCode());

        // 3. Intermediate DB Checks
        $this->assertEventAndJob('page', $wpPageId, 'content.page.updated', 2);

        // 4. Worker Execution
        $this->runWorker();

        // 5. Projection DB Assertions
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("SELECT * FROM content.pages WHERE source_post_id = :id");
        $stmt->execute(['id' => (string)$wpPageId]);
        $page = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($page, 'Page projection not found in content.pages');
        $this->assertEquals('trash', $page['status']);
        $this->assertNotNull($page['deleted_at']);

        // 6. REST Delivery API Verification
        $deliveryClient = $this->getDeliveryApiClient();
        $response = $deliveryClient->get('api/v1/pages', [
            'query' => ['slug' => 'e2e-page-create-slug']
        ]);
        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * Page Case 4: Restore Page from Trash
     */
    public function testRestorePageFromTrash(): void
    {
        // 1. Initial Create
        $wpPage = $this->createPage([
            'title' => 'E2E Page Create Title',
            'content' => 'E2E Page Create Content',
            'slug' => 'e2e-page-create-slug',
            'status' => 'publish'
        ]);
        $wpPageId = $wpPage['id'];
        $this->runWorker();

        // 2. Trash the page
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->delete("wp/v2/pages/{$wpPageId}");
        $this->assertEquals(200, $response->getStatusCode());
        $this->runWorker();

        // 3. Trigger (Restore)
        $response = $wpClient->post("wp/v2/pages/{$wpPageId}", [
            'json' => [
                'status' => 'publish'
            ]
        ]);
        $this->assertEquals(200, $response->getStatusCode());

        // 4. Intermediate DB Checks
        $this->assertEventAndJob('page', $wpPageId, 'content.page.updated', 3);

        // 5. Worker Execution
        $this->runWorker();

        // 6. Projection DB Assertions
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("SELECT * FROM content.pages WHERE source_post_id = :id");
        $stmt->execute(['id' => (string)$wpPageId]);
        $page = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($page, 'Page projection not found in content.pages');
        $this->assertEquals('publish', $page['status']);
        $this->assertNull($page['deleted_at']);

        // 7. REST Delivery API Verification
        $deliveryClient = $this->getDeliveryApiClient();
        $response = $deliveryClient->get('api/v1/pages', [
            'query' => ['slug' => 'e2e-page-create-slug']
        ]);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Page Case 5: Force Delete Page (Hard Delete)
     */
    public function testForceDeletePage(): void
    {
        // 1. Initial Create
        $wpPage = $this->createPage([
            'title' => 'E2E Page Create Title',
            'content' => 'E2E Page Create Content',
            'slug' => 'e2e-page-create-slug',
            'status' => 'publish'
        ]);
        $wpPageId = $wpPage['id'];
        $this->runWorker();

        // 2. Trigger (Force Delete)
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->delete("wp/v2/pages/{$wpPageId}", [
            'query' => ['force' => 'true']
        ]);
        $this->assertEquals(200, $response->getStatusCode());

        // 3. Intermediate DB Checks
        $this->assertEventAndJob('page', $wpPageId, 'content.page.deleted', 2);

        // 4. Worker Execution
        $this->runWorker();

        // 5. Projection DB Assertions
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("SELECT * FROM content.pages WHERE source_post_id = :id");
        $stmt->execute(['id' => (string)$wpPageId]);
        $page = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertFalse($page, 'Page projection should be completely purged from content.pages');

        // 6. REST Delivery API Verification
        $deliveryClient = $this->getDeliveryApiClient();
        $response = $deliveryClient->get('api/v1/pages', [
            'query' => ['slug' => 'e2e-page-create-slug']
        ]);
        $this->assertEquals(404, $response->getStatusCode());
    }
}
