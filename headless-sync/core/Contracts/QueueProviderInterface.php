<?php

namespace HSP\Core\Contracts;

use HSP\Core\Queue\Job;
use Throwable;

interface QueueProviderInterface
{
    /**
     * Push a new job onto the queue.
     *
     * @param Job $job
     * @return void
     */
    public function push(Job $job): void;

    /**
     * Claim a batch of jobs from the queue.
     *
     * @param string $queueName
     * @param int $limit
     * @return Job[]
     */
    public function claim(string $queueName, int $limit): array;

    /**
     * Release a reserved job back onto the queue.
     *
     * @param Job $job
     * @param int $delay Delay in seconds
     * @return void
     */
    public function release(Job $job, int $delay): void;

    /**
     * Mark a job as successfully completed.
     *
     * @param Job $job
     * @return void
     */
    public function complete(Job $job): void;

    /**
     * Mark a job as failed, potentially routing to DLQ.
     *
     * @param Job $job
     * @param Throwable $exception
     * @return void
     */
    public function fail(Job $job, Throwable $exception): void;
}
