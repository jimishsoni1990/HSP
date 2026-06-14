<?php

namespace HSP\Core\Events;

use PDO;
use Throwable;

class OutboxService
{
    /**
     * @var PDO
     */
    protected PDO $pdo;

    /**
     * @var EventBuilder
     */
    protected EventBuilder $builder;

    /**
     * OutboxService constructor.
     *
     * @param PDO $pdo
     * @param EventBuilder $builder
     */
    public function __construct(PDO $pdo, EventBuilder $builder)
    {
        $this->pdo = $pdo;
        $this->builder = $builder;
    }

    /**
     * Publish a post/page change event.
     *
     * @param array $postData
     * @param string $eventType
     * @return EventEnvelope
     * @throws Throwable
     */
    public function publishPost(array $postData, string $eventType): EventEnvelope
    {
        try {
            $this->pdo->beginTransaction();

            $aggregateId = (string) ($postData['ID'] ?? '');
            $aggregateType = $postData['post_type'] ?? 'post';
            if ($aggregateType !== 'page') {
                $aggregateType = 'post';
            }

            // 1. Get/Increment aggregate version atomically
            $sql = "INSERT INTO system.aggregate_versions (aggregate_type, aggregate_id, version, updated_at)
                    VALUES (:type, :id, 1, NOW())
                    ON CONFLICT (aggregate_type, aggregate_id)
                    DO UPDATE SET version = system.aggregate_versions.version + 1, updated_at = NOW()
                    RETURNING version";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':type', $aggregateType, PDO::PARAM_STR);
            $stmt->bindValue(':id', $aggregateId, PDO::PARAM_STR);
            $stmt->execute();
            $version = (int) $stmt->fetchColumn();

            // 2. Build envelope
            $envelope = $this->builder->buildFromPost($postData, $eventType, $version);

            // 3. Save to system.events
            $this->saveEvent($envelope);

            // 4. Queue job
            $this->queueJob($envelope);

            $this->pdo->commit();

            return $envelope;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Publish a term/category change event.
     *
     * @param array $termData
     * @param string $eventType
     * @param string $taxonomy
     * @return EventEnvelope
     * @throws Throwable
     */
    public function publishTerm(array $termData, string $eventType, string $taxonomy = 'category'): EventEnvelope
    {
        try {
            $this->pdo->beginTransaction();

            $aggregateId = (string) ($termData['term_id'] ?? '');

            // 1. Get/Increment aggregate version atomically
            $sql = "INSERT INTO system.aggregate_versions (aggregate_type, aggregate_id, version, updated_at)
                    VALUES (:type, :id, 1, NOW())
                    ON CONFLICT (aggregate_type, aggregate_id)
                    DO UPDATE SET version = system.aggregate_versions.version + 1, updated_at = NOW()
                    RETURNING version";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':type', $taxonomy, PDO::PARAM_STR);
            $stmt->bindValue(':id', $aggregateId, PDO::PARAM_STR);
            $stmt->execute();
            $version = (int) $stmt->fetchColumn();

            // 2. Build envelope
            $envelope = $this->builder->buildFromTerm($termData, $eventType, $version, $taxonomy);

            // 3. Save to system.events
            $this->saveEvent($envelope);

            // 4. Queue job
            $this->queueJob($envelope);

            $this->pdo->commit();

            return $envelope;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Save an event envelope to the outbox database table.
     *
     * @param EventEnvelope $envelope
     * @return void
     */
    protected function saveEvent(EventEnvelope $envelope): void
    {
        $sql = "INSERT INTO system.events (id, event_type, event_version, aggregate_type, aggregate_id, aggregate_version, source_updated_at, created_at, payload)
                VALUES (:event_id, :event_type, :event_version, :aggregate_type, :aggregate_id, :aggregate_version, :source_updated_at, :created_at, :payload)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':event_id', $envelope->getEventId(), PDO::PARAM_STR);
        $stmt->bindValue(':event_type', $envelope->getEventType(), PDO::PARAM_STR);
        $stmt->bindValue(':event_version', $envelope->getEventVersion(), PDO::PARAM_INT);
        $stmt->bindValue(':aggregate_type', $envelope->getAggregateType(), PDO::PARAM_STR);
        $stmt->bindValue(':aggregate_id', $envelope->getAggregateId(), PDO::PARAM_STR);
        $stmt->bindValue(':aggregate_version', $envelope->getAggregateVersion(), PDO::PARAM_INT);
        $stmt->bindValue(':source_updated_at', $envelope->getSourceUpdatedAt(), PDO::PARAM_STR);
        $stmt->bindValue(':created_at', $envelope->getCreatedAt(), PDO::PARAM_STR);
        $stmt->bindValue(':payload', json_encode($envelope->getPayload()), PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Queue a job corresponding to the event.
     *
     * @param EventEnvelope $envelope
     * @return void
     */
    protected function queueJob(EventEnvelope $envelope): void
    {
        $sql = "INSERT INTO system.queue_jobs (queue_name, event_id, payload, status, attempts, available_at, created_at, updated_at)
                VALUES ('content', :event_id, :payload, 'queued', 0, NOW(), NOW(), NOW())";

        $jobPayload = [
            'event_id' => $envelope->getEventId(),
            'event_type' => $envelope->getEventType(),
            'aggregate_type' => $envelope->getAggregateType(),
            'aggregate_id' => $envelope->getAggregateId(),
            'aggregate_version' => $envelope->getAggregateVersion()
        ];

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':event_id', $envelope->getEventId(), PDO::PARAM_STR);
        $stmt->bindValue(':payload', json_encode($jobPayload), PDO::PARAM_STR);
        $stmt->execute();
    }
}
