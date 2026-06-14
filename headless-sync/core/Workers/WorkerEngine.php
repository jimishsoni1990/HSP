<?php

namespace HSP\Core\Workers;

use HSP\Core\Contracts\WorkerInterface;
use HSP\Core\Contracts\QueueProviderInterface;
use HSP\Core\Events\EventEnvelope;
use HSP\Core\Queue\Job;
use PDO;
use Exception;
use Throwable;

class WorkerEngine implements WorkerInterface
{
    /**
     * @var QueueProviderInterface
     */
    protected QueueProviderInterface $queueProvider;

    /**
     * @var PDO
     */
    protected PDO $pdo;

    /**
     * @var string
     */
    protected string $workerId;

    /**
     * @var int
     */
    protected int $maxJobs = 1000;

    /**
     * @var int
     */
    protected int $maxRuntime = 3600;

    /**
     * @var int
     */
    protected int $memoryLimit = 134217728; // 128MB

    /**
     * @var bool
     */
    protected bool $stopWhenEmpty = false;

    /**
     * @var int
     */
    protected int $processedCount = 0;

    /**
     * @var int
     */
    protected int $failedCount = 0;

    /**
     * @var int
     */
    protected int $startTime;

    /**
     * @var bool
     */
    protected bool $shouldQuit = false;

    /**
     * @var array
     */
    protected static array $subscribers = [];

    /**
     * WorkerEngine constructor.
     *
     * @param QueueProviderInterface $queueProvider
     * @param PDO $pdo
     */
    public function __construct(QueueProviderInterface $queueProvider, PDO $pdo)
    {
        $this->queueProvider = $queueProvider;
        $this->pdo = $pdo;
        $this->workerId = 'worker_' . getmypid() . '_' . uniqid();
        $this->startTime = time();
    }

    /**
     * Register static event callbacks.
     *
     * @param string $eventType
     * @param callable $callback
     * @return void
     */
    public static function subscribe(string $eventType, callable $callback): void
    {
        self::$subscribers[$eventType][] = $callback;
    }

    /**
     * Run the worker loop.
     *
     * @param string $queue
     * @param array $options
     * @return void
     */
    public function work(string $queue, array $options = []): void
    {
        $this->maxJobs = $options['max_jobs'] ?? 1000;
        $this->maxRuntime = $options['max_runtime'] ?? 3600;
        $this->memoryLimit = $options['memory_limit'] ?? 134217728;
        $this->stopWhenEmpty = $options['stop_when_empty'] ?? false;

        $this->registerSignals();
        $this->publishHeartbeat('starting');

        while (!$this->shouldQuit) {
            $this->publishHeartbeat('idle');

            $jobs = $this->queueProvider->claim($queue, 1);

            if (empty($jobs)) {
                if ($this->stopWhenEmpty) {
                    $this->shouldQuit = true;
                    break;
                }
                $this->dispatchSignals();
                $this->checkRecyclingLimits();
                usleep(250000); // 250ms
                continue;
            }

            foreach ($jobs as $job) {
                $this->publishHeartbeat('processing');
                
                try {
                    $this->process($job);
                    $this->processedCount++;
                    $this->queueProvider->complete($job);
                } catch (Throwable $e) {
                    $this->failedCount++;
                    
                    if ($job->getAttempts() >= 10) {
                        $this->queueProvider->fail($job, $e);
                    } else {
                        // Exponential backoff
                        $delay = (int) (pow(2, $job->getAttempts()) + rand(1, 5));
                        $this->queueProvider->release($job, $delay);
                    }
                }

                $this->dispatchSignals();
                if ($this->checkRecyclingLimits()) {
                    break;
                }
            }
        }

        $this->publishHeartbeat('stopped');
    }

    /**
     * Process a claimed job.
     *
     * @param Job $job
     * @return void
     * @throws Exception
     */
    protected function process(Job $job): void
    {
        $payload = $job->getPayload();
        $eventId = $payload['event_id'] ?? null;

        if (!$eventId) {
            throw new Exception("Job payload is missing event_id");
        }

        // 1. Fetch raw event
        $stmt = $this->pdo->prepare("SELECT * FROM system.events WHERE id = :id");
        $stmt->execute(['id' => $eventId]);
        $eventRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$eventRow) {
            throw new Exception("Outbox event {$eventId} not found in system.events");
        }

        $envelope = EventEnvelope::fromArray([
            'event_id' => $eventRow['id'],
            'event_type' => $eventRow['event_type'],
            'event_version' => (int) $eventRow['event_version'],
            'aggregate_type' => $eventRow['aggregate_type'],
            'aggregate_id' => $eventRow['aggregate_id'],
            'aggregate_version' => (int) $eventRow['aggregate_version'],
            'source_updated_at' => $eventRow['source_updated_at'],
            'created_at' => $eventRow['created_at'],
            'payload' => json_decode($eventRow['payload'], true)
        ]);

        $aggregateType = $envelope->getAggregateType();
        $aggregateId = $envelope->getAggregateId();
        $eventVersion = $envelope->getAggregateVersion();

        // 2. Stale Event Skipping
        $processedType = $aggregateType . ':processed';
        $stmt = $this->pdo->prepare("
            SELECT version FROM system.aggregate_versions 
            WHERE aggregate_type = :type AND aggregate_id = :id
        ");
        $stmt->execute(['type' => $processedType, 'id' => $aggregateId]);
        $latestProcessedVersion = $stmt->fetchColumn();

        if ($latestProcessedVersion !== false && $eventVersion <= (int) $latestProcessedVersion) {
            // Skip processing since we've already handled a newer version
            return;
        }

        // 3. Execute subscribers
        $eventType = $envelope->getEventType();
        if (isset(self::$subscribers[$eventType])) {
            foreach (self::$subscribers[$eventType] as $callback) {
                call_user_func($callback, $envelope);
            }
        }

        // 4. Update latest processed version
        $stmt = $this->pdo->prepare("
            INSERT INTO system.aggregate_versions (aggregate_type, aggregate_id, version, updated_at)
            VALUES (:type, :id, :version, NOW())
            ON CONFLICT (aggregate_type, aggregate_id)
            DO UPDATE SET version = :version, updated_at = NOW()
        ");
        $stmt->execute([
            'type' => $processedType,
            'id' => $aggregateId,
            'version' => $eventVersion
        ]);
    }

    /**
     * Register PCNTL signal handlers.
     *
     * @return void
     */
    protected function registerSignals(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        }
    }

    /**
     * Dispatch PCNTL signals.
     *
     * @return void
     */
    protected function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    /**
     * Handle signal callback.
     *
     * @param int $signal
     * @return void
     */
    public function handleSignal(int $signal): void
    {
        $this->shouldQuit = true;
    }

    /**
     * Check if the worker process has hit its recycling limits.
     *
     * @return bool
     */
    protected function checkRecyclingLimits(): bool
    {
        if ($this->processedCount >= $this->maxJobs) {
            $this->shouldQuit = true;
            $this->publishHeartbeat('recycling');
            return true;
        }

        if ((time() - $this->startTime) >= $this->maxRuntime) {
            $this->shouldQuit = true;
            $this->publishHeartbeat('recycling');
            return true;
        }

        if (memory_get_usage(true) >= $this->memoryLimit) {
            $this->shouldQuit = true;
            $this->publishHeartbeat('recycling');
            return true;
        }

        return false;
    }

    /**
     * Write worker heartbeat status to PostgreSQL.
     *
     * @param string $status
     * @return void
     */
    protected function publishHeartbeat(string $status): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO system.worker_heartbeats (worker_id, status, last_heartbeat_at, started_at, processed_count, failed_count, memory_bytes)
                VALUES (:worker_id, :status, NOW(), :started_at, :processed_count, :failed_count, :memory_bytes)
                ON CONFLICT (worker_id)
                DO UPDATE SET status = EXCLUDED.status, last_heartbeat_at = NOW(), processed_count = EXCLUDED.processed_count, failed_count = EXCLUDED.failed_count, memory_bytes = EXCLUDED.memory_bytes
            ");
            $stmt->execute([
                'worker_id' => $this->workerId,
                'status' => $status,
                'started_at' => date('Y-m-d H:i:s', $this->startTime),
                'processed_count' => $this->processedCount,
                'failed_count' => $this->failedCount,
                'memory_bytes' => memory_get_usage(true)
            ]);
        } catch (Throwable $e) {
            // Non-blocking fallback for heartbeat write failures
        }
    }
}
