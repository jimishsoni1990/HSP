<?php

namespace HSP\Core\Queue;

use HSP\Core\Contracts\QueueProviderInterface;
use HSP\Core\Events\EventBuilder;
use Throwable;
use PDO;

class DatabaseQueueProvider implements QueueProviderInterface
{
    /**
     * @var PDO
     */
    protected PDO $pdo;

    /**
     * DatabaseQueueProvider constructor.
     *
     * @param PDO $pdo
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Push a new job onto the queue.
     *
     * @param Job $job
     * @return void
     */
    public function push(Job $job): void
    {
        $payload = $job->getPayload();
        $eventId = $payload['event_id'] ?? EventBuilder::generateUuidV7();

        $sql = "INSERT INTO system.queue_jobs (queue_name, event_id, payload, status, attempts, available_at, created_at, updated_at)
                VALUES (:queue, :event_id, :payload, 'queued', :attempts, NOW(), NOW(), NOW())";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':queue', $job->getQueue(), PDO::PARAM_STR);
        $stmt->bindValue(':event_id', $eventId, PDO::PARAM_STR);
        $stmt->bindValue(':payload', json_encode($payload), PDO::PARAM_STR);
        $stmt->bindValue(':attempts', $job->getAttempts(), PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Claim a batch of jobs from the queue.
     *
     * @param string $queueName
     * @param int $limit
     * @return Job[]
     */
    public function claim(string $queueName, int $limit): array
    {
        $sql = "WITH next_jobs AS (
                    SELECT job_id
                    FROM system.queue_jobs
                    WHERE queue_name = :queue
                      AND status = 'queued'
                      AND available_at <= NOW()
                    ORDER BY available_at ASC, job_id ASC
                    LIMIT :limit
                    FOR UPDATE SKIP LOCKED
                )
                UPDATE system.queue_jobs
                SET status = 'reserved',
                    reserved_at = NOW(),
                    attempts = attempts + 1,
                    updated_at = NOW()
                FROM next_jobs
                WHERE system.queue_jobs.job_id = next_jobs.job_id
                RETURNING system.queue_jobs.job_id, system.queue_jobs.queue_name, system.queue_jobs.payload, system.queue_jobs.attempts";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':queue', $queueName, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $jobs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $jobs[] = new Job(
                (int) $row['job_id'],
                $row['queue_name'],
                json_decode($row['payload'], true),
                (int) $row['attempts']
            );
        }

        return $jobs;
    }

    /**
     * Release a reserved job back onto the queue.
     *
     * @param Job $job
     * @param int $delay Delay in seconds
     * @return void
     */
    public function release(Job $job, int $delay): void
    {
        $sql = "UPDATE system.queue_jobs
                SET status = 'queued',
                    available_at = NOW() + (:delay::text || ' seconds')::interval,
                    reserved_at = NULL,
                    updated_at = NOW()
                WHERE job_id = :job_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':delay', (string) $delay, PDO::PARAM_STR);
        $stmt->bindValue(':job_id', $job->getId(), PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Mark a job as successfully completed.
     *
     * @param Job $job
     * @return void
     */
    public function complete(Job $job): void
    {
        $sql = "DELETE FROM system.queue_jobs WHERE job_id = :job_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':job_id', $job->getId(), PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Mark a job as failed, routing to DLQ and deleting from active queue.
     *
     * @param Job $job
     * @param Throwable $exception
     * @return void
     */
    public function fail(Job $job, Throwable $exception): void
    {
        try {
            $this->pdo->beginTransaction();

            $payload = $job->getPayload();
            $eventId = $payload['event_id'] ?? EventBuilder::generateUuidV7();

            // Insert into Dead Letter Queue
            $sql = "INSERT INTO system.dead_letter_jobs (job_id, queue_name, event_id, payload, failed_at, exception_message)
                    VALUES (:job_id, :queue, :event_id, :payload, NOW(), :exception_message)
                    ON CONFLICT (job_id) DO UPDATE 
                    SET exception_message = EXCLUDED.exception_message, failed_at = NOW()";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':job_id', $job->getId(), PDO::PARAM_INT);
            $stmt->bindValue(':queue', $job->getQueue(), PDO::PARAM_STR);
            $stmt->bindValue(':event_id', $eventId, PDO::PARAM_STR);
            $stmt->bindValue(':payload', json_encode($payload), PDO::PARAM_STR);
            $stmt->bindValue(':exception_message', $exception->getMessage() . "\n" . $exception->getTraceAsString(), PDO::PARAM_STR);
            $stmt->execute();

            // Delete from active queue
            $deleteSql = "DELETE FROM system.queue_jobs WHERE job_id = :job_id";
            $deleteStmt = $this->pdo->prepare($deleteSql);
            $deleteStmt->bindValue(':job_id', $job->getId(), PDO::PARAM_INT);
            $deleteStmt->execute();

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
