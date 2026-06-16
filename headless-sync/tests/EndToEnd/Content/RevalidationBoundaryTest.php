<?php

namespace HSP\Tests\EndToEnd\Content;

use HSP\Tests\EndToEnd\BaseEndToEndTestCase;

/**
 * E2E Integration Test for Cache Revalidation Webhook Boundary Cases and Additional Types.
 *
 * Verifies cache revalidation webhook behavior for pages, categories, and boundary/stress conditions.
 */
class RevalidationBoundaryTest extends BaseEndToEndTestCase
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

    private function clearAuditLogs(): void
    {
        $pdo = $this->getPostgresPdo();
        $pdo->exec("TRUNCATE system.audit_log CASCADE;");
    }

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

    private function runWorker(): void
    {
        $process = $this->runWpCli(['headless-sync', 'worker', 'run', '--queue=content']);
        $this->assertTrue($process->isSuccessful(), "Worker CLI run failed: " . $process->getErrorOutput());
    }

    /**
     * Page Test 1: Creating a page triggers a cache revalidation webhook audit log.
     */
    public function testPageCreationTriggersWebhook(): void
    {
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post('wp/v2/pages', [
            'json' => [
                'title' => 'Webhook Test Page',
                'content' => 'Content for testing page webhook',
                'slug' => 'webhook-test-page',
                'status' => 'publish'
            ]
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true);
        $wpPageId = $body['id'];
        $actualSlug = $body['slug'];
        $this->trackPost($wpPageId); // Pages are tracked as posts for cleanup

        $this->runWorker();

        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM system.audit_log 
            WHERE action = 'cache_revalidation_triggered' 
              AND target_type = 'page' 
              AND target_id = :slug
            ORDER BY id ASC LIMIT 1
        ");
        $stmt->execute(['slug' => $actualSlug]);
        $log = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($log, 'No page revalidation webhook trigger was audited. Logs: ' . $this->debugAuditLog());
        
        $details = json_decode($log['details'], true);
        $this->assertIsArray($details);
        $payload = $details['payload'];
        $this->assertEquals('page', $payload['type']);
        $this->assertContains($payload['action'], ['create', 'update']);
        $this->assertEquals($actualSlug, $payload['slug']);
        $this->assertContains("/pages/{$actualSlug}", $details['paths']);
        $this->assertContains('/', $details['paths']);
    }

    /**
     * Page Test 2: Updating a page's slug triggers revalidation with old and new slugs.
     */
    public function testPageUpdateTriggersWebhookWithOldSlug(): void
    {
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post('wp/v2/pages', [
            'json' => [
                'title' => 'Page Original Title',
                'slug' => 'page-slug-original',
                'status' => 'publish'
            ]
        ]);
        $body = json_decode($response->getBody()->getContents(), true);
        $wpPageId = $body['id'];
        $originalSlug = $body['slug'];
        $this->trackPost($wpPageId);

        $this->runWorker();
        $this->clearAuditLogs();

        $response = $wpClient->post("wp/v2/pages/{$wpPageId}", [
            'json' => [
                'title' => 'Page Updated Title',
                'slug' => 'page-slug-updated'
            ]
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        $updatedBody = json_decode($response->getBody()->getContents(), true);
        $updatedSlug = $updatedBody['slug'];

        $this->runWorker();

        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM system.audit_log 
            WHERE action = 'cache_revalidation_triggered' 
              AND target_type = 'page' 
              AND target_id = :slug
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(['slug' => $updatedSlug]);
        $log = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($log, 'No page update revalidation was audited. Logs: ' . $this->debugAuditLog());
        
        $details = json_decode($log['details'], true);
        $payload = $details['payload'];
        $this->assertEquals('page', $payload['type']);
        $this->assertEquals('update', $payload['action']);
        $this->assertEquals($updatedSlug, $payload['slug']);
        $this->assertEquals($originalSlug, $payload['oldSlug']);
        $this->assertContains("/pages/{$updatedSlug}", $details['paths']);
        $this->assertContains("/pages/{$originalSlug}", $details['paths']);
    }

    /**
     * Page Test 3: Deleting a page triggers a delete webhook.
     */
    public function testPageDeletionTriggersWebhook(): void
    {
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post('wp/v2/pages', [
            'json' => [
                'title' => 'Delete Page Title',
                'slug' => 'delete-page-slug',
                'status' => 'publish'
            ]
        ]);
        $body = json_decode($response->getBody()->getContents(), true);
        $wpPageId = $body['id'];
        $actualSlug = $body['slug'];

        $this->runWorker();
        $this->clearAuditLogs();

        $response = $wpClient->delete("wp/v2/pages/{$wpPageId}", [
            'query' => ['force' => 'true']
        ]);
        $this->assertEquals(200, $response->getStatusCode());

        $this->runWorker();

        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM system.audit_log 
            WHERE action = 'cache_revalidation_triggered' 
              AND target_type = 'page' 
              AND target_id = :slug
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(['slug' => $actualSlug]);
        $log = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($log, 'No page delete revalidation was audited. Logs: ' . $this->debugAuditLog());
        
        $details = json_decode($log['details'], true);
        $payload = $details['payload'];
        $this->assertEquals('page', $payload['type']);
        $this->assertEquals('delete', $payload['action']);
        $this->assertEquals($actualSlug, $payload['slug']);
        $this->assertContains("/pages/{$actualSlug}", $details['paths']);
    }

    /**
     * Category Test 1: Creating a category triggers a cache revalidation webhook audit log.
     */
    public function testCategoryCreationTriggersWebhook(): void
    {
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post('wp/v2/categories', [
            'json' => [
                'name' => 'Webhook Test Category',
                'slug' => 'webhook-test-category'
            ]
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true);
        $wpCatId = $body['id'];
        $actualSlug = $body['slug'];
        $this->trackTerm($wpCatId);

        $this->runWorker();

        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM system.audit_log 
            WHERE action = 'cache_revalidation_triggered' 
              AND target_type = 'category' 
              AND target_id = :slug
            ORDER BY id ASC LIMIT 1
        ");
        $stmt->execute(['slug' => $actualSlug]);
        $log = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($log, 'No category revalidation webhook trigger was audited. Logs: ' . $this->debugAuditLog());
        
        $details = json_decode($log['details'], true);
        $payload = $details['payload'];
        $this->assertEquals('category', $payload['type']);
        $this->assertEquals($actualSlug, $payload['slug']);
        $this->assertContains("/category/{$actualSlug}", $details['paths']);
    }

    /**
     * Category Test 2: Updating a category's slug triggers revalidation with old and new slugs.
     */
    public function testCategoryUpdateTriggersWebhookWithOldSlug(): void
    {
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post('wp/v2/categories', [
            'json' => [
                'name' => 'Category Original Name',
                'slug' => 'cat-slug-original'
            ]
        ]);
        $body = json_decode($response->getBody()->getContents(), true);
        $wpCatId = $body['id'];
        $originalSlug = $body['slug'];
        $this->trackTerm($wpCatId);

        $this->runWorker();
        $this->clearAuditLogs();

        $response = $wpClient->post("wp/v2/categories/{$wpCatId}", [
            'json' => [
                'name' => 'Category Updated Name',
                'slug' => 'cat-slug-updated'
            ]
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        $updatedBody = json_decode($response->getBody()->getContents(), true);
        $updatedSlug = $updatedBody['slug'];

        $this->runWorker();

        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM system.audit_log 
            WHERE action = 'cache_revalidation_triggered' 
              AND target_type = 'category' 
              AND target_id = :slug
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(['slug' => $updatedSlug]);
        $log = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($log, 'No category update revalidation was audited. Logs: ' . $this->debugAuditLog());
        
        $details = json_decode($log['details'], true);
        $payload = $details['payload'];
        $this->assertEquals('category', $payload['type']);
        $this->assertEquals('update', $payload['action']);
        $this->assertEquals($updatedSlug, $payload['slug']);
        $this->assertEquals($originalSlug, $payload['oldSlug']);
        $this->assertContains("/category/{$updatedSlug}", $details['paths']);
        $this->assertContains("/category/{$originalSlug}", $details['paths']);
    }

    /**
     * Category Test 3: Deleting a category triggers a delete webhook.
     */
    public function testCategoryDeletionTriggersWebhook(): void
    {
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post('wp/v2/categories', [
            'json' => [
                'name' => 'Delete Category Name',
                'slug' => 'delete-category-slug'
            ]
        ]);
        $body = json_decode($response->getBody()->getContents(), true);
        $wpCatId = $body['id'];
        $actualSlug = $body['slug'];

        $this->runWorker();
        $this->clearAuditLogs();

        $response = $wpClient->delete("wp/v2/categories/{$wpCatId}", [
            'query' => ['force' => 'true']
        ]);
        $this->assertEquals(200, $response->getStatusCode());

        $this->runWorker();

        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM system.audit_log 
            WHERE action = 'cache_revalidation_triggered' 
              AND target_type = 'category' 
              AND target_id = :slug
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(['slug' => $actualSlug]);
        $log = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($log, 'No category delete revalidation was audited. Logs: ' . $this->debugAuditLog());
        
        $details = json_decode($log['details'], true);
        $payload = $details['payload'];
        $this->assertEquals('category', $payload['type']);
        $this->assertEquals('delete', $payload['action']);
        $this->assertEquals($actualSlug, $payload['slug']);
        $this->assertContains("/category/{$actualSlug}", $details['paths']);
    }

    /**
     * Boundary Case 1: Post with a long slug (> 50 chars).
     *
     * This test demonstrates the bug in system.audit_log.target_id (VARCHAR(50)) truncation.
     * When the slug is > 50 characters, the audit log insert fails, but the projection itself succeeds.
     */
    public function testPostWithLongSlugAuditLogBug(): void
    {
        // 56 characters slug
        $longSlug = 'a-very-long-slug-with-more-than-fifty-characters-for-testing';
        
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post('wp/v2/posts', [
            'json' => [
                'title' => 'Long Slug Test Post',
                'content' => 'Content for long slug testing',
                'slug' => $longSlug,
                'status' => 'publish'
            ]
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true);
        $wpPostId = $body['id'];
        $actualSlug = $body['slug'];
        $this->assertEquals($longSlug, $actualSlug);
        $this->trackPost($wpPostId);

        // Run worker to project
        $this->runWorker();

        // 1. Verify projection succeeded in content.posts table
        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("SELECT id FROM content.posts WHERE slug = :slug");
        $stmt->execute(['slug' => $actualSlug]);
        $postId = $stmt->fetchColumn();
        $this->assertNotEmpty($postId, 'Post projection failed to write to content.posts for long slug.');

        // 2. Verify that the audit log was written successfully (with truncated target_id)
        $stmtLog = $pdo->prepare("
            SELECT * FROM system.audit_log 
            WHERE action = 'cache_revalidation_triggered' 
              AND target_type = 'post' 
              AND target_id = :slug
        ");
        $stmtLog->execute(['slug' => substr($actualSlug, 0, 50)]);
        $log = $stmtLog->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($log, 'Audit log should have been successfully written after truncation.');
        $this->assertEquals(substr($actualSlug, 0, 50), $log['target_id']);
    }

    /**
     * Boundary Case 2: Post with empty slug.
     *
     * In WordPress, sending an empty slug results in WordPress auto-generating a slug.
     * We verify how the system handles the generated slug and triggers revalidation.
     */
    public function testPostWithEmptySlug(): void
    {
        $wpClient = $this->getWordPressClient();
        $response = $wpClient->post('wp/v2/posts', [
            'json' => [
                'title' => 'Empty Slug Test',
                'content' => 'Content for empty slug testing',
                'slug' => '',
                'status' => 'publish'
            ]
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true);
        $wpPostId = $body['id'];
        $actualSlug = $body['slug'];
        
        // WordPress should auto-generate a slug from title (e.g. 'empty-slug-test')
        $this->assertNotEmpty($actualSlug);
        $this->trackPost($wpPostId);

        $this->runWorker();

        $pdo = $this->getPostgresPdo();
        $stmt = $pdo->prepare("
            SELECT * FROM system.audit_log 
            WHERE action = 'cache_revalidation_triggered' 
              AND target_type = 'post' 
              AND target_id = :slug
        ");
        $stmt->execute(['slug' => $actualSlug]);
        $log = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($log, 'No revalidation webhook trigger was audited for empty slug (WordPress auto-generated slug).');
    }
}
