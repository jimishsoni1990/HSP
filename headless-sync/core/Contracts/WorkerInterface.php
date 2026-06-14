<?php

namespace HSP\Core\Contracts;

interface WorkerInterface
{
    /**
     * Run the worker process for the specified queue.
     *
     * @param string $queue
     * @param array $options
     * @return void
     */
    public function work(string $queue, array $options = []): void;
}
