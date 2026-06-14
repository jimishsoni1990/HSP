<?php

namespace HSP\Tests\EndToEnd\Platform;

use HSP\Tests\EndToEnd\BaseEndToEndTestCase;

/**
 * Tests that verify the queue mechanics at the PostgreSQL level.
 *
 * These tests directly manipulate the system.queue_jobs, system.dead_letter_jobs,
 * and system.worker_heartbeats tables to validate the queue infrastructure
 * independent of the WordPress layer.
 */
class QueueMechanicsTest extends BaseEndToEndTestCase
{
    /**
     * Skip all tests if the PostgreSQL container is not running.
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
     * Verify that a job can be inserted into system.queue_jobs and retrieved.
     */
    public function testJobInsertionToQueue(): void
    {
        $pdo = $this->getPostgresPdo();
        $eventId = $this->generateUuidV4();

        $stmt = $pdo->prepare(
            "INSERT INTO system.queue_jobs (queue_name, event_id, payload, status, attempts, available_at)
             VALUES (:queue_name, :event_id, :payload, 'queued', 0, NOW())
             RETURNING job_id"
        );
        $stmt->execute([
            'queue_name' => 'test-queue',
            'event_id'   => $eventId,
            'payload'    => json_encode(['test' => 'job_insertion']),
        ]);
        $jobId = $stmt->fetchColumn();

        $this->assertNotEmpty($jobId, 'Job insertion should return a job_id.');

        // Verify the job exists
        $stmt = $pdo->prepare("SELECT * FROM system.queue_jobs WHERE job_id = :job_id");
        $stmt->execute(['job_id' => $jobId]);
        $job = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($job, 'Inserted job should be retrievable from system.queue_jobs.');
        $this->assertEquals('test-queue', $job['queue_name']);
        $this->assertEquals($eventId, $job['event_id']);
        $this->assertEquals('queued', $job['status']);
        $this->assertEquals(0, (int) $job['attempts']);
    }

    /**
     * Verify that jobs can be claimed using FOR UPDATE SKIP LOCKED semantics.
     *
     * Inserts multiple jobs, claims a batch within a transaction, and verifies
     * that the claimed jobs are marked as 'processing'.
     */
    public function testJobClaimingWithForUpdateSkipLocked(): void
    {
        $pdo = $this->getPostgresPdo();

        // Insert 3 test jobs
        $insertedJobIds = [];
        for ($i = 0; $i < 3; $i++) {
            $eventId = $this->generateUuidV4();
            $stmt = $pdo->prepare(
                "INSERT INTO system.queue_jobs (queue_name, event_id, payload, status, attempts, available_at)
                 VALUES (:queue_name, :event_id, :payload, 'queued', 0, NOW())
                 RETURNING job_id"
            );
            $stmt->execute([
                'queue_name' => 'test-claim-queue',
                'event_id'   => $eventId,
                'payload'    => json_encode(['test' => 'claim_batch', 'index' => $i]),
            ]);
            $insertedJobIds[] = $stmt->fetchColumn();
        }

        $this->assertCount(3, $insertedJobIds);

        // Claim a batch of 2 jobs using FOR UPDATE SKIP LOCKED
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT job_id FROM system.queue_jobs
             WHERE queue_name = :queue_name AND status = 'queued' AND available_at <= NOW()
             ORDER BY job_id ASC
             LIMIT 2
             FOR UPDATE SKIP LOCKED"
        );
        $stmt->execute(['queue_name' => 'test-claim-queue']);
        $claimedRows = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertCount(2, $claimedRows, 'Should have claimed exactly 2 jobs.');

        // Mark claimed jobs as 'processing'
        foreach ($claimedRows as $claimedJobId) {
            $stmt = $pdo->prepare(
                "UPDATE system.queue_jobs
                 SET status = 'processing', reserved_at = NOW(), attempts = attempts + 1
                 WHERE job_id = :job_id"
            );
            $stmt->execute(['job_id' => $claimedJobId]);
        }

        $pdo->commit();

        // Verify the claimed jobs are 'processing'
        foreach ($claimedRows as $claimedJobId) {
            $stmt = $pdo->prepare("SELECT status, attempts FROM system.queue_jobs WHERE job_id = :job_id");
            $stmt->execute(['job_id' => $claimedJobId]);
            $job = $stmt->fetch(\PDO::FETCH_ASSOC);

            $this->assertEquals('processing', $job['status'], "Job {$claimedJobId} should be 'processing'.");
            $this->assertEquals(1, (int) $job['attempts'], "Job {$claimedJobId} should have 1 attempt.");
        }

        // Verify the third job is still 'queued'
        $remainingJobId = array_diff($insertedJobIds, $claimedRows);
        $remainingJobId = array_values($remainingJobId)[0];

        $stmt = $pdo->prepare("SELECT status FROM system.queue_jobs WHERE job_id = :job_id");
        $stmt->execute(['job_id' => $remainingJobId]);
        $remaining = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals('queued', $remaining['status'], 'Unclaimed job should still be queued.');
    }

    /**
     * Verify that a job with max attempts exceeded can be routed to the dead letter queue.
     *
     * Simulates the dead-letter routing by inserting a failed job directly into
     * system.dead_letter_jobs and removing it from system.queue_jobs.
     */
    public function testDeadLetterQueueRouting(): void
    {
        $pdo = $this->getPostgresPdo();
        $eventId = $this->generateUuidV4();

        // Insert a job that has exceeded max attempts
        $stmt = $pdo->prepare(
            "INSERT INTO system.queue_jobs (queue_name, event_id, payload, status, attempts, available_at)
             VALUES (:queue_name, :event_id, :payload, 'failed', 5, NOW())
             RETURNING job_id"
        );
        $stmt->execute([
            'queue_name' => 'test-dlq-queue',
            'event_id'   => $eventId,
            'payload'    => json_encode(['test' => 'dead_letter_routing']),
        ]);
        $jobId = $stmt->fetchColumn();

        // Route to dead letter queue
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "INSERT INTO system.dead_letter_jobs (job_id, queue_name, event_id, payload, failed_at, exception_message)
             VALUES (:job_id, :queue_name, :event_id, :payload, NOW(), :exception_message)"
        );
        $stmt->execute([
            'job_id'            => $jobId,
            'queue_name'        => 'test-dlq-queue',
            'event_id'          => $eventId,
            'payload'           => json_encode(['test' => 'dead_letter_routing']),
            'exception_message' => 'Max attempts exceeded (5/5)',
        ]);

        $stmt = $pdo->prepare("DELETE FROM system.queue_jobs WHERE job_id = :job_id");
        $stmt->execute(['job_id' => $jobId]);

        $pdo->commit();

        // Verify the job exists in dead_letter_jobs
        $stmt = $pdo->prepare("SELECT * FROM system.dead_letter_jobs WHERE job_id = :job_id");
        $stmt->execute(['job_id' => $jobId]);
        $dlqJob = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($dlqJob, 'Job should exist in system.dead_letter_jobs.');
        $this->assertEquals('test-dlq-queue', $dlqJob['queue_name']);
        $this->assertEquals($eventId, $dlqJob['event_id']);
        $this->assertStringContainsString('Max attempts exceeded', $dlqJob['exception_message']);

        // Verify the job no longer exists in queue_jobs
        $stmt = $pdo->prepare("SELECT * FROM system.queue_jobs WHERE job_id = :job_id");
        $stmt->execute(['job_id' => $jobId]);
        $originalJob = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertFalse($originalJob, 'Job should have been removed from system.queue_jobs after DLQ routing.');
    }

    /**
     * Verify that a worker heartbeat record can be inserted and persists.
     */
    public function testWorkerHeartbeatTable(): void
    {
        $pdo = $this->getPostgresPdo();
        $workerId = 'test-worker-' . substr($this->generateUuidV4(), 0, 8);

        $stmt = $pdo->prepare(
            "INSERT INTO system.worker_heartbeats (worker_id, status, last_heartbeat_at, started_at, processed_count, failed_count, memory_bytes)
             VALUES (:worker_id, :status, NOW(), NOW(), :processed_count, :failed_count, :memory_bytes)"
        );
        $stmt->execute([
            'worker_id'       => $workerId,
            'status'          => 'running',
            'processed_count' => 42,
            'failed_count'    => 3,
            'memory_bytes'    => 1048576,
        ]);

        // Verify the heartbeat persists
        $stmt = $pdo->prepare("SELECT * FROM system.worker_heartbeats WHERE worker_id = :worker_id");
        $stmt->execute(['worker_id' => $workerId]);
        $heartbeat = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($heartbeat, 'Worker heartbeat should be retrievable.');
        $this->assertEquals('running', $heartbeat['status']);
        $this->assertEquals(42, (int) $heartbeat['processed_count']);
        $this->assertEquals(3, (int) $heartbeat['failed_count']);
        $this->assertEquals(1048576, (int) $heartbeat['memory_bytes']);

        // Cleanup
        $pdo->prepare("DELETE FROM system.worker_heartbeats WHERE worker_id = :worker_id")
            ->execute(['worker_id' => $workerId]);
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
