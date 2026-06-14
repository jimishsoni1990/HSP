<?php

namespace HSP\Core\Events;

class EventEnvelope
{
    /**
     * @var string
     */
    protected string $eventId;

    /**
     * @var string
     */
    protected string $eventType;

    /**
     * @var int
     */
    protected int $eventVersion;

    /**
     * @var string
     */
    protected string $aggregateType;

    /**
     * @var string
     */
    protected string $aggregateId;

    /**
     * @var int
     */
    protected int $aggregateVersion;

    /**
     * @var string
     */
    protected string $sourceUpdatedAt;

    /**
     * @var string
     */
    protected string $createdAt;

    /**
     * @var array
     */
    protected array $payload;

    /**
     * EventEnvelope constructor.
     *
     * @param string $eventId
     * @param string $eventType
     * @param int $eventVersion
     * @param string $aggregateType
     * @param string $aggregateId
     * @param int $aggregateVersion
     * @param string $sourceUpdatedAt
     * @param string $createdAt
     * @param array $payload
     */
    public function __construct(
        string $eventId,
        string $eventType,
        int $eventVersion,
        string $aggregateType,
        string $aggregateId,
        int $aggregateVersion,
        string $sourceUpdatedAt,
        string $createdAt,
        array $payload
    ) {
        $this->eventId = $eventId;
        $this->eventType = $eventType;
        $this->eventVersion = $eventVersion;
        $this->aggregateType = $aggregateType;
        $this->aggregateId = $aggregateId;
        $this->aggregateVersion = $aggregateVersion;
        $this->sourceUpdatedAt = $sourceUpdatedAt;
        $this->createdAt = $createdAt;
        $this->payload = $payload;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getEventVersion(): int
    {
        return $this->eventVersion;
    }

    public function getAggregateType(): string
    {
        return $this->aggregateType;
    }

    public function getAggregateId(): string
    {
        return $this->aggregateId;
    }

    public function getAggregateVersion(): int
    {
        return $this->aggregateVersion;
    }

    public function getSourceUpdatedAt(): string
    {
        return $this->sourceUpdatedAt;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Convert envelope to standard array format.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'event_version' => $this->eventVersion,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'aggregate_version' => $this->aggregateVersion,
            'source_updated_at' => $this->sourceUpdatedAt,
            'created_at' => $this->createdAt,
            'payload' => $this->payload,
        ];
    }

    /**
     * Create an envelope instance from array data.
     *
     * @param array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['event_id'],
            $data['event_type'],
            (int) ($data['event_version'] ?? 1),
            $data['aggregate_type'],
            $data['aggregate_id'],
            (int) $data['aggregate_version'],
            $data['source_updated_at'],
            $data['created_at'],
            $data['payload']
        );
    }
}
