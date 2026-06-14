<?php

namespace HSP\Tests\EndToEnd\Platform;

use HSP\Tests\EndToEnd\BaseEndToEndTestCase;

/**
 * Tests verifying WP-Cron custom schedules, scheduled events, and cron queue processing.
 */
class CronAndAdminBarTest extends BaseEndToEndTestCase
{
    /**
     * Skip all tests if PostgreSQL connection is not available.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->getPostgresPdo() === null) {
            $this->markTestSkipped(
                'PostgreSQL connection is not available: ' . ($this->pgConnectionError ?? 'Unknown error')
            );
        }
    }

    /**
     * Test that our custom 'every_minute' cron schedule is registered.
     */
    public function testCronScheduleIsRegistered(): void
    {
        $process = $this->runWpCli(['eval', 'echo json_encode(wp_get_schedules());']);
        
        $this->assertEquals(0, $process->getExitCode(), 'WP-CLI command failed: ' . $process->getErrorOutput());
        
        $schedules = json_decode($process->getOutput(), true);
        
        $this->assertArrayHasKey('every_minute', $schedules, 'every_minute custom schedule is not registered.');
        $this->assertEquals(60, $schedules['every_minute']['interval']);
        $this->assertStringContainsString('Every Minute', $schedules['every_minute']['display']);
    }

    /**
     * Test that our cron hook 'headless_sync_cron_runner' is scheduled.
     */
    public function testCronEventIsScheduled(): void
    {
        $process = $this->runWpCli(['eval', 'echo json_encode(wp_next_scheduled("headless_sync_cron_runner"));']);
        
        $this->assertEquals(0, $process->getExitCode(), 'WP-CLI command failed: ' . $process->getErrorOutput());
        
        $nextScheduled = json_decode($process->getOutput(), true);
        
        $this->assertNotEmpty($nextScheduled, 'headless_sync_cron_runner event is not scheduled.');
        $this->assertGreaterThan(0, $nextScheduled);
    }

    /**
     * Test that running the scheduled cron action processes queued outbox jobs.
     */
    public function testCronActionProcessesQueue(): void
    {
        $pdo = $this->getPostgresPdo();

        // 1. Insert a mock outbox event in PostgreSQL
        $eventId = '99999999-9999-7999-9999-999999999999'; // Valid UUIDv7 format mock
        $pdo->prepare("
            INSERT INTO system.events (id, event_type, event_version, aggregate_type, aggregate_id, aggregate_version, source_updated_at, created_at, payload)
            VALUES (:id, 'content.post.created', 1, 'post', '12345', 1, NOW(), NOW(), :payload)
        ")->execute([
            'id' => $eventId,
            'payload' => json_encode([
                'ID' => '12345',
                'post_title' => 'Test Post via Cron',
                'post_name' => 'test-post-via-cron',
                'post_content' => 'Sample content',
                'post_excerpt' => 'Sample excerpt',
                'post_status' => 'publish',
                'categories' => []
            ])
        ]);

        // 2. Insert the corresponding job in PostgreSQL queue
        $pdo->prepare("
            INSERT INTO system.queue_jobs (queue_name, event_id, payload, status, attempts, available_at, created_at, updated_at)
            VALUES ('content', :event_id, :payload, 'queued', 0, NOW(), NOW(), NOW())
        ")->execute([
            'event_id' => $eventId,
            'payload' => json_encode(['event_id' => $eventId])
        ]);

        // Verify the job starts in the queued state
        $queuedCount = (int) $pdo->query("SELECT COUNT(*) FROM system.queue_jobs WHERE event_id = '$eventId' AND status = 'queued'")->fetchColumn();
        $this->assertEquals(1, $queuedCount);

        // 3. Trigger the WP-Cron hook using WP-CLI eval
        $process = $this->runWpCli(['eval', 'do_action("headless_sync_cron_runner");']);
        
        $this->assertEquals(0, $process->getExitCode(), 'WP-CLI hook action run failed: ' . $process->getErrorOutput());

        // 4. Verify that the job was processed and cleared from the active queue
        $queuedAfterCount = (int) $pdo->query("SELECT COUNT(*) FROM system.queue_jobs WHERE event_id = '$eventId'")->fetchColumn();
        $this->assertEquals(0, $queuedAfterCount, 'The queue job was not removed/processed by the cron execution.');

        // 5. Verify the post projection was written to content.posts
        $postCount = (int) $pdo->query("SELECT COUNT(*) FROM content.posts WHERE slug = 'test-post-via-cron'")->fetchColumn();
        $this->assertEquals(1, $postCount, 'The canonical post was not projected into content.posts.');
    }
}
