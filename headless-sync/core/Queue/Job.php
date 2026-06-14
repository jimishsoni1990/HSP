<?php

namespace HSP\Core\Queue;

class Job
{
    /**
     * @var int|null
     */
    protected ?int $id;

    /**
     * @var string
     */
    protected string $queue;

    /**
     * @var array
     */
    protected array $payload;

    /**
     * @var int
     */
    protected int $attempts;

    /**
     * Job constructor.
     *
     * @param int|null $id
     * @param string $queue
     * @param array $payload
     * @param int $attempts
     */
    public function __construct(?int $id, string $queue, array $payload, int $attempts = 0)
    {
        $this->id = $id;
        $this->queue = $queue;
        $this->payload = $payload;
        $this->attempts = $attempts;
    }

    /**
     * Get the job ID.
     *
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Get the queue name.
     *
     * @return string
     */
    public function getQueue(): string
    {
        return $this->queue;
    }

    /**
     * Get the job payload.
     *
     * @return array
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Get the number of attempts.
     *
     * @return int
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }
}
