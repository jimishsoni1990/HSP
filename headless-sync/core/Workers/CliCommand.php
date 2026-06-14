<?php

namespace HSP\Core\Workers;

use HSP\Bootstrap\Bootstrapper;
use WP_CLI;

class CliCommand
{
    /**
     * Run the worker loop (subcommand: wp headless-sync worker run).
     *
     * @subcommand run
     * @synopsis [--queue=<queue>] [--max-jobs=<max-jobs>] [--max-runtime=<max-runtime>] [--memory-limit=<memory-limit>] [--stop-when-empty]
     */
    public function run($args, $assocArgs): void
    {
        $this->executeWorker($args, $assocArgs);
    }

    /**
     * Run the worker loop (default command: wp headless-sync work).
     *
     * @synopsis [<action>] [--queue=<queue>] [--max-jobs=<max-jobs>] [--max-runtime=<max-runtime>] [--memory-limit=<memory-limit>] [--stop-when-empty]
     */
    public function __invoke($args, $assocArgs): void
    {
        $this->executeWorker($args, $assocArgs);
    }

    /**
     * Resolve worker engine and boot the work loop.
     *
     * @param array $args
     * @param array $assocArgs
     * @return void
     */
    protected function executeWorker($args, $assocArgs): void
    {
        $app = Bootstrapper::getApplication();

        if (!$app) {
            if (class_exists('WP_CLI')) {
                WP_CLI::error("Application kernel not initialized.");
            }
            return;
        }

        $queue = $assocArgs['queue'] ?? 'content';
        
        $options = [
            'max_jobs' => isset($assocArgs['max-jobs']) ? (int) $assocArgs['max-jobs'] : 1000,
            'max_runtime' => isset($assocArgs['max-runtime']) ? (int) $assocArgs['max-runtime'] : 3600,
            'memory_limit' => isset($assocArgs['memory-limit']) ? (int) $assocArgs['memory-limit'] : 134217728,
            'stop_when_empty' => isset($assocArgs['stop-when-empty']),
        ];

        if (class_exists('WP_CLI')) {
            WP_CLI::log(sprintf("Starting background worker for queue: %s", $queue));
        }

        try {
            $worker = $app->make(WorkerEngine::class);
            $worker->work($queue, $options);
            if (class_exists('WP_CLI')) {
                WP_CLI::success("Worker execution completed successfully.");
            }
        } catch (\Throwable $e) {
            if (class_exists('WP_CLI')) {
                WP_CLI::error(sprintf("Worker failed with error: %s", $e->getMessage()));
            } else {
                throw $e;
            }
        }
    }
}
