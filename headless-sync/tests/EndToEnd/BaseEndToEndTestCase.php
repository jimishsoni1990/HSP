<?php

namespace HSP\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use Symfony\Component\Process\Process;

abstract class BaseEndToEndTestCase extends TestCase
{
    /**
     * @var \PDO|null
     */
    protected $pgPdo = null;

    /**
     * @var string|null
     */
    protected $pgConnectionError = null;

    /**
     * @var Client|null
     */
    protected $wpClient = null;

    /**
     * @var Client|null
     */
    protected $deliveryClient = null;

    /**
     * @var array
     */
    protected $createdPostIds = [];

    /**
     * @var array
     */
    protected $createdTermIds = [];

    /**
     * Get the PostgreSQL PDO connection.
     *
     * @return \PDO|null
     */
    protected function getPostgresPdo(): ?\PDO
    {
        if ($this->pgPdo === null) {
            $host = getenv('PG_DB_HOST') ?: '127.0.0.1';
            $port = getenv('PG_DB_PORT') ?: '5432';
            $db = getenv('PG_DB_NAME') ?: 'hsp_delivery';
            $user = getenv('PG_DB_USER') ?: 'hsp_admin';
            $pass = getenv('PG_DB_PASSWORD') ?: 'hsp_pass';

            $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
            try {
                $this->pgPdo = new \PDO($dsn, $user, $pass, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 2, // 2 seconds timeout
                ]);
            } catch (\PDOException $e) {
                $this->pgPdo = null;
                $this->pgConnectionError = $e->getMessage();
            }
        }
        return $this->pgPdo;
    }

    /**
     * Get the Guzzle client for the WordPress REST API.
     *
     * @return Client
     */
    protected function getWordPressClient(): Client
    {
        if ($this->wpClient === null) {
            $baseUri = getenv('WP_URL') ?: 'http://localhost:8080';
            $user = getenv('WP_ADMIN_USER') ?: 'admin';
            $password = getenv('WP_ADMIN_APP_PASSWORD') ?: 'abcd-efgh-ijkl-mnop';

            $config = [
                'base_uri' => rtrim($baseUri, '/') . '/wp-json/',
                'timeout'  => 3.0,
                'http_errors' => false,
            ];

            if (!empty($user) && !empty($password)) {
                $config['auth'] = [$user, $password];
            }

            $this->wpClient = new Client($config);
        }
        return $this->wpClient;
    }

    /**
     * Get the Guzzle client for the REST Delivery API.
     *
     * @return Client
     */
    protected function getDeliveryApiClient(): Client
    {
        if ($this->deliveryClient === null) {
            $baseUri = getenv('DELIVERY_API_URL') ?: 'http://localhost:9000';

            $this->deliveryClient = new Client([
                'base_uri' => rtrim($baseUri, '/') . '/',
                'timeout'  => 3.0,
                'http_errors' => false,
            ]);
        }
        return $this->deliveryClient;
    }

    /**
     * Execute a WP-CLI command programmatically via Symfony Process.
     *
     * Runs WP-CLI inside the Docker WordPress container since it is
     * not available on the host machine.
     *
     * @param array $args
     * @param string|null $cwd
     * @return Process
     */
    protected function runWpCli(array $args, string $cwd = null): Process
    {
        $container = getenv('WP_CONTAINER_NAME') ?: 'hsp-wordpress';
        
        // Automatically append --stop-when-empty for headless-sync worker runs in tests
        if (count($args) >= 2 && $args[0] === 'headless-sync' && in_array($args[1], ['worker', 'work'])) {
            if (!in_array('--stop-when-empty', $args)) {
                $args[] = '--stop-when-empty';
            }
        }

        $cmd = array_merge(['docker', 'exec', $container, 'wp', '--allow-root'], $args);

        $process = new Process($cmd, $cwd);
        $process->setTimeout(30);
        $process->run();
        return $process;
    }

    /**
     * Track a post ID for deletion during tearDown.
     *
     * @param int|string $id
     */
    protected function trackPost($id): void
    {
        $this->createdPostIds[] = $id;
    }

    /**
     * Track a term/category ID for deletion during tearDown.
     *
     * @param int|string $id
     */
    protected function trackTerm($id): void
    {
        $this->createdTermIds[] = $id;
    }

    /**
     * Cleanup databases and tracked WordPress entities.
     */
    protected function tearDown(): void
    {
        $this->cleanupWordPressState();
        $this->cleanupPostgresState();
        parent::tearDown();
    }

    /**
     * Cleanup WordPress state by deleting tracked entities.
     */
    protected function cleanupWordPressState(): void
    {
        foreach ($this->createdPostIds as $id) {
            try {
                $this->runWpCli(['post', 'delete', $id, '--force']);
            } catch (\Exception $e) {
                // Ignore failure during cleanup
            }
        }
        $this->createdPostIds = [];

        foreach ($this->createdTermIds as $id) {
            try {
                $this->runWpCli(['term', 'delete', 'category', $id]);
            } catch (\Exception $e) {
                // Ignore failure during cleanup
            }
        }
        $this->createdTermIds = [];
    }

    /**
     * Truncate PostgreSQL tables.
     */
    protected function cleanupPostgresState(): void
    {
        $pdo = $this->getPostgresPdo();
        if ($pdo === null) {
            return;
        }

        $tables = [
            'system.events',
            'system.queue_jobs',
            'system.dead_letter_jobs',
            'system.audit_log',
            'system.aggregate_versions',
            'content.posts',
            'content.pages',
            'content.taxonomies',
            'content.entity_taxonomies',
            'content.media'
        ];

        foreach ($tables as $table) {
            try {
                $pdo->exec("TRUNCATE {$table} CASCADE;");
            } catch (\PDOException $e) {
                // Table might not exist yet, ignore
            }
        }
    }
}
