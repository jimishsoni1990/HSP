<?php

namespace HSP\Tests\EndToEnd\Content;

use HSP\Tests\EndToEnd\BaseEndToEndTestCase;

/**
 * E2E Integration Test for Cache Revalidation Webhook.
 *
 * Verifies that saving/updating/deleting content in WordPress projects the data to PostgreSQL
 * and triggers a non-blocking cache revalidation webhook, recorded in the system audit logs.
 */
class RevalidationWebhookTest extends BaseEndToEndTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        $this->clearAuditLogs();
    }

    /**
     * Clear the audit log table before running tests.
     */
    private function clearAuditLogs(): void
    {
        $pdo = $this->getPostgresPdo();
        $pdo->exec("TRUNCATE system.audit_log CASCADE;");
    }

    /**
     * Helper to debug audit log contents on test failure.
     */
    private function debugAuditLog(): string
    {
        try {
            $pdo = $this->getPostgresPdo();
            $stmt = $pdo->query("SELECT * FROM system.audit_log ORDER BY id ASC");
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return json_encode($rows, JSON_PRETTY_PRINT);
        } catch (\Exception $e) {
            return "Failed to fetch audit logs: " . $e->getMessage();
        }
    }

    /**
     * Run the background worker queue.
     */
    private function runWorker(): void
    {
        $process = $this->runWpCli(['headless-sync', 'worker', 'run', '--queue=content']);
        $this->assertTrue($process->isSuccessful(), "Worker CLI run failed: " . $process->getErrorOutput());
    }

    /**
     * Test Case 1: Creating a post triggers a cache revalidation webhook audit log.
     */
    public function testPostCreationTriggersWebhook(): void
    {
        // 1. Create a published post in WP
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post('wp/v2/posts', [
            'json' => [
                'title' => 'Webhook Test Post',
                'content' => 'Content for testing webhook',
                'excerpt' => 'Excerpt for testing webhook',
                'slug' => 'webhook-test-post',
                'status' => 'publish'
            ]
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true);
        $wpPostId = $body['id'];
        $actualSlug = $body['slug'];
        $this->trackPost($wpPostId);

        // 2. Execute the async worker to process the projection and webhook
        $this->runWorker();

        // 3. Query system.audit_log to verify the webhook trigger was logged
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM system.audit_log 
            WHERE action = 'cache_revalidation_triggered' 
              AND target_type = 'post' 
              AND target_id = :slug
            ORDER BY id ASC LIMIT 1
        ");
        $stmt->execute(['slug' => $actualSlug]);
        $log = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($log, 'No revalidation webhook trigger was audited in system.audit_log. Audit logs: ' . $this->debugAuditLog());
        
        $details = json_decode($log['details'], true);
        $this->assertIsArray($details, 'Audit log details should be JSON.');
        $this->assertArrayHasKey('payload', $details);
        
        $payload = $details['payload'];
        $this->assertEquals('post', $payload['type']);
        $this->assertEquals('create', $payload['action']);
        $this->assertEquals($actualSlug, $payload['slug']);
        $this->assertContains("/posts/{$actualSlug}", $details['paths']);
        $this->assertContains('/', $details['paths']);
    }

    /**
     * Test Case 2: Updating a post triggers update webhook and tracks old/new slugs.
     */
    public function testPostUpdateTriggersWebhookWithOldSlug(): void
    {
        // 1. Create initial post
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post('wp/v2/posts', [
            'json' => [
                'title' => 'Slug Original Title',
                'slug' => 'slug-original',
                'status' => 'publish'
            ]
        ]);
        $body = json_decode($response->getBody()->getContents(), true);
        $wpPostId = $body['id'];
        $originalSlug = $body['slug'];
        $this->trackPost($wpPostId);

        $this->runWorker();
        $this->clearAuditLogs();

        // 2. Update slug and title
        $response = $wpClient->post("wp/v2/posts/{$wpPostId}", [
            'json' => [
                'title' => 'Slug Updated Title',
                'slug' => 'slug-updated'
            ]
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        $updatedBody = json_decode($response->getBody()->getContents(), true);
        $updatedSlug = $updatedBody['slug'];

        // 3. Run worker to process the update
        $this->runWorker();

        // 4. Assert audit logs
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM system.audit_log 
            WHERE action = 'cache_revalidation_triggered' 
              AND target_type = 'post' 
              AND target_id = :slug
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(['slug' => $updatedSlug]);
        $log = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($log, 'No revalidation webhook trigger was audited for the post update. Audit logs: ' . $this->debugAuditLog());
        
        $details = json_decode($log['details'], true);
        $payload = $details['payload'];
        
        $this->assertEquals('post', $payload['type']);
        $this->assertEquals('update', $payload['action']);
        $this->assertEquals($updatedSlug, $payload['slug']);
        $this->assertEquals($originalSlug, $payload['oldSlug']);
        $this->assertContains("/posts/{$updatedSlug}", $details['paths']);
        $this->assertContains("/posts/{$originalSlug}", $details['paths']);
        $this->assertContains('/', $details['paths']);
    }

    /**
     * Test Case 3: Deleting a post triggers delete webhook and cleans up.
     */
    public function testPostDeletionTriggersWebhook(): void
    {
        // 1. Create post
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post('wp/v2/posts', [
            'json' => [
                'title' => 'Delete Webhook Post',
                'slug' => 'delete-webhook-post',
                'status' => 'publish'
            ]
        ]);
        $body = json_decode($response->getBody()->getContents(), true);
        $wpPostId = $body['id'];
        $actualSlug = $body['slug'];
        
        $this->runWorker();
        $this->clearAuditLogs();

        // 2. Force delete post (hard delete)
        $response = $wpClient->delete("wp/v2/posts/{$wpPostId}", [
            'query' => ['force' => 'true']
        ]);
        $this->assertEquals(200, $response->getStatusCode());

        // 3. Run worker to process deletion
        $this->runWorker();

        // 4. Assert audit logs
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM system.audit_log 
            WHERE action = 'cache_revalidation_triggered' 
              AND target_type = 'post' 
              AND target_id = :slug
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(['slug' => $actualSlug]);
        $log = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($log, 'No revalidation webhook trigger was audited for the post deletion. Audit logs: ' . $this->debugAuditLog());
        
        $details = json_decode($log['details'], true);
        $payload = $details['payload'];
        
        $this->assertEquals('post', $payload['type']);
        $this->assertEquals('delete', $payload['action']);
        $this->assertEquals($actualSlug, $payload['slug']);
        $this->assertContains("/posts/{$actualSlug}", $details['paths']);
        $this->assertContains('/', $details['paths']);
    }

    /**
     * Test Case 4: Directly test the Next.js API route using Guzzle.
     */
    public function testNextJsRevalidationApiRoute(): void
    {
        $revalUrl = getenv('REVALIDATION_URL') ?: 'http://host.docker.internal:3000/api/revalidate';
        $secret = getenv('REVALIDATION_SECRET') ?: 'hsp_revalidation_secret_token_123';

        $client = new \GuzzleHttp\Client([
            'timeout' => 5.0,
            'http_errors' => false,
            'proxy' => null,
            'curl' => [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
            ]
        ]);

        // 1. Test unauthorized - missing secret
        $response = $client->get($revalUrl, [
            'query' => ['path' => '/']
        ]);
        $this->assertEquals(401, $response->getStatusCode(), 'Expected 401 for missing secret');

        // 2. Test unauthorized - invalid secret
        $response = $client->get($revalUrl, [
            'query' => ['secret' => 'invalid_secret', 'path' => '/']
        ]);
        $this->assertEquals(401, $response->getStatusCode(), 'Expected 401 for invalid secret');

        // 3. Test unauthorized - POST with invalid secret in body
        $response = $client->post($revalUrl, [
            'json' => ['secret' => 'invalid_secret', 'path' => '/']
        ]);
        $this->assertEquals(401, $response->getStatusCode(), 'Expected 401 for invalid secret in POST body');

        // 4. Test missing path parameter
        $response = $client->get($revalUrl, [
            'query' => ['secret' => $secret]
        ]);
        $this->assertEquals(400, $response->getStatusCode(), 'Expected 400 for missing path');

        // 5. Test authorized GET
        $response = $client->get($revalUrl, [
            'query' => ['secret' => $secret, 'path' => '/posts/hello']
        ]);
        $this->assertEquals(200, $response->getStatusCode(), 'Expected 200 for authorized GET');
        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertTrue($body['revalidated']);
        $this->assertEquals('/posts/hello', $body['path']);

        // 6. Test authorized POST with query parameters
        $response = $client->post($revalUrl . "?secret=" . urlencode($secret) . "&path=/posts/query");
        $this->assertEquals(200, $response->getStatusCode(), 'Expected 200 for authorized POST with query parameters');
        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertTrue($body['revalidated']);
        $this->assertEquals('/posts/query', $body['path']);

        // 7. Test authorized POST with JSON body
        $response = $client->post($revalUrl, [
            'json' => ['secret' => $secret, 'path' => '/posts/body']
        ]);
        $this->assertEquals(200, $response->getStatusCode(), 'Expected 200 for authorized POST with JSON body');
        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertTrue($body['revalidated']);
        $this->assertEquals('/posts/body', $body['path']);
    }

    /**
     * Test Case: Moving a post from Category A to Category B triggers revalidation for both category A and B pages.
     */
    public function testMovingPostBetweenCategoriesTriggersRevalidationForBothCategories(): void
    {
        $wpClient = $this->getWordPressClient();

        // 1. Create Category A
        $responseA = $wpClient->post('wp/v2/categories', [
            'json' => [
                'name' => 'Cat A Name',
                'slug' => 'category-a',
            ]
        ]);
        $this->assertEquals(201, $responseA->getStatusCode());
        $catA = json_decode($responseA->getBody()->getContents(), true);
        $catAId = $catA['id'];
        $this->trackTerm($catAId);

        // 2. Create Category B
        $responseB = $wpClient->post('wp/v2/categories', [
            'json' => [
                'name' => 'Cat B Name',
                'slug' => 'category-b',
            ]
        ]);
        $this->assertEquals(201, $responseB->getStatusCode());
        $catB = json_decode($responseB->getBody()->getContents(), true);
        $catBId = $catB['id'];
        $this->trackTerm($catBId);

        // Run worker to register these categories in PostgreSQL
        $this->runWorker();

        // 3. Create a post associated with Category A
        $responsePost = $wpClient->post('wp/v2/posts', [
            'json' => [
                'title' => 'Post to move',
                'content' => 'Content of post to move',
                'slug' => 'post-to-move',
                'status' => 'publish',
                'categories' => [$catAId]
            ]
        ]);
        $this->assertEquals(201, $responsePost->getStatusCode());
        $postData = json_decode($responsePost->getBody()->getContents(), true);
        $postId = $postData['id'];
        $postSlug = $postData['slug'];
        $this->trackPost($postId);

        // Run worker to sync the post
        $this->runWorker();
        $this->clearAuditLogs();

        // 4. Update post to move it to Category B (removing Category A)
        $responseUpdate = $wpClient->post("wp/v2/posts/{$postId}", [
            'json' => [
                'categories' => [$catBId]
            ]
        ]);
        $this->assertEquals(200, $responseUpdate->getStatusCode());

        // Run worker to sync the update
        $this->runWorker();

        // 5. Query system.audit_log for post revalidation trigger
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM system.audit_log 
            WHERE action = 'cache_revalidation_triggered' 
              AND target_type = 'post' 
              AND target_id = :slug
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(['slug' => $postSlug]);
        $log = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($log, 'No revalidation webhook trigger was audited for the post move.');
        $details = json_decode($log['details'], true);

        // Assert that both category paths are marked for revalidation
        $this->assertContains('/category/category-a', $details['paths']);
        $this->assertContains('/category/category-b', $details['paths']);
        $this->assertContains("/posts/{$postSlug}", $details['paths']);
    }

    /**
     * Test Case: Changing a category slug triggers revalidation for the posts belonging to that category.
     */
    public function testChangingCategorySlugTriggersRevalidationForCategoryPosts(): void
    {
        $wpClient = $this->getWordPressClient();

        // 1. Create Category with slug 'cat-slug-old'
        $responseCat = $wpClient->post('wp/v2/categories', [
            'json' => [
                'name' => 'Cat Slug Old Name',
                'slug' => 'cat-slug-old',
            ]
        ]);
        $this->assertEquals(201, $responseCat->getStatusCode());
        $cat = json_decode($responseCat->getBody()->getContents(), true);
        $catId = $cat['id'];
        $this->trackTerm($catId);

        // Run worker to register Category
        $this->runWorker();

        // 2. Create a post associated with Category
        $responsePost = $wpClient->post('wp/v2/posts', [
            'json' => [
                'title' => 'Post in category',
                'content' => 'Content of post in category',
                'slug' => 'post-in-category',
                'status' => 'publish',
                'categories' => [$catId]
            ]
        ]);
        $this->assertEquals(201, $responsePost->getStatusCode());
        $postData = json_decode($responsePost->getBody()->getContents(), true);
        $postId = $postData['id'];
        $postSlug = $postData['slug'];
        $this->trackPost($postId);

        // Run worker to sync the post
        $this->runWorker();
        $this->clearAuditLogs();

        // 3. Update Category slug to 'cat-slug-new'
        $responseUpdate = $wpClient->post("wp/v2/categories/{$catId}", [
            'json' => [
                'slug' => 'cat-slug-new'
            ]
        ]);
        $this->assertEquals(200, $responseUpdate->getStatusCode());
        $updatedCat = json_decode($responseUpdate->getBody()->getContents(), true);
        $newSlug = $updatedCat['slug'];
        $this->assertEquals('cat-slug-new', $newSlug);

        // Run worker to sync the Category update
        $this->runWorker();

        // 4. Query system.audit_log for category revalidation trigger
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM system.audit_log 
            WHERE action = 'cache_revalidation_triggered' 
              AND target_type = 'category' 
              AND target_id = :slug
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(['slug' => $newSlug]);
        $log = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($log, 'No revalidation webhook trigger was audited for the category slug change.');
        $details = json_decode($log['details'], true);

        // Assert that old and new category paths are marked for revalidation
        $this->assertContains('/category/cat-slug-new', $details['paths']);
        $this->assertContains('/category/cat-slug-old', $details['paths']);
        // Assert that the post belonging to the category is also marked for revalidation
        $this->assertContains("/posts/{$postSlug}", $details['paths']);
    }
}
