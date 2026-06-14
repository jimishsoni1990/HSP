<?php

namespace HSP\Core\Admin;

use HSP\Bootstrap\Application;
use HSP\Core\Config\Config;
use HSP\Core\Workers\WorkerEngine;
use PDO;
use Throwable;

class AdminDashboard
{
    /**
     * @var Application
     */
    protected Application $app;

    /**
     * @var string|null
     */
    protected ?string $connectionError = null;

    /**
     * AdminDashboard constructor.
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Register WordPress hooks.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_init', [$this, 'handleManualSync']);
    }

    /**
     * Register the admin menu page.
     *
     * @return void
     */
    public function addAdminMenu(): void
    {
        add_menu_page(
            'Headless Sync Platform',
            'Headless Sync',
            'manage_options',
            'headless-sync',
            [$this, 'renderDashboard'],
            'dashicons-update',
            80
        );
    }

    /**
     * Enqueue styles for the dashboard page.
     *
     * @param string $hook
     * @return void
     */
    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_headless-sync') {
            return;
        }

        wp_enqueue_style(
            'hsp-admin-style',
            plugins_url('assets/css/admin.css', dirname(dirname(__DIR__)) . '/headless-sync.php'),
            [],
            '1.0.0'
        );
    }

    /**
     * Handle manual sync button click.
     *
     * @return void
     */
    public function handleManualSync(): void
    {
        if (!isset($_GET['page']) || $_GET['page'] !== 'headless-sync') {
            return;
        }

        if (isset($_GET['action']) && $_GET['action'] === 'hsp_sync_now') {
            check_admin_referer('hsp_manual_sync');

            if (!current_user_can('manage_options')) {
                wp_die('Unauthorized');
            }

            try {
                $worker = $this->app->make(WorkerEngine::class);
                
                // Set up buffer capture or status detection
                // Run the worker to process exactly 1 job
                $worker->work('content', [
                    'max_jobs' => 1,
                    'max_runtime' => 10,
                    'stop_when_empty' => true
                ]);

                // Redirect back with success flag
                wp_safe_redirect(add_query_arg(['sync_status' => 'success'], menu_page_url('headless-sync', false)));
                exit;
            } catch (Throwable $e) {
                // Redirect back with error message
                wp_safe_redirect(add_query_arg([
                    'sync_status' => 'error',
                    'sync_error' => urlencode($e->getMessage())
                ], menu_page_url('headless-sync', false)));
                exit;
            }
        }
    }

    /**
     * Render the admin dashboard panel.
     *
     * @return void
     */
    public function renderDashboard(): void
    {
        $pdo = $this->getConnection();
        
        $config = $this->app->make(Config::class);
        $dbConfig = $config->get('database.connections.pgsql', []);
        $hostInfo = sprintf('%s:%s', $dbConfig['host'] ?? '127.0.0.1', $dbConfig['port'] ?? '5432');
        $dbName = $dbConfig['database'] ?? 'hsp_delivery';

        $queueCounts = $this->getQueueCounts($pdo);
        $dlqCount = $this->getDlqCount($pdo);
        $totalEvents = $this->getEventsCount($pdo);
        $workers = $this->getWorkersList($pdo);
        $recentEvents = $this->getRecentEvents($pdo);

        ?>
        <div class="wrap hsp-dashboard">
            <div class="hsp-header">
                <h1>Headless Sync Platform</h1>
                <?php if ($pdo): ?>
                    <span class="hsp-status-badge connected">
                        <span class="hsp-status-dot"></span> Postgres Connected
                    </span>
                <?php else: ?>
                    <span class="hsp-status-badge disconnected">
                        <span class="hsp-status-dot"></span> Postgres Disconnected
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($this->connectionError): ?>
                <div class="hsp-alert hsp-alert-error">
                    <strong>Database Connection Error:</strong> Unable to connect to PostgreSQL. Verify your credentials in <code>config/database.php</code>.<br>
                    <small>Error: <code><?php echo esc_html($this->connectionError); ?></code></small>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['sync_status'])): ?>
                <?php if ($_GET['sync_status'] === 'success'): ?>
                    <div class="hsp-alert hsp-alert-success">
                        <strong>Sync Complete:</strong> Successfully executed worker and processed the next job from queue.
                    </div>
                <?php elseif ($_GET['sync_status'] === 'error'): ?>
                    <div class="hsp-alert hsp-alert-error">
                        <strong>Sync Failed:</strong> An error occurred while running the worker: <code><?php echo esc_html(urldecode($_GET['sync_error'] ?? 'Unknown error')); ?></code>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Metrics Grid -->
            <div class="hsp-grid">
                <div class="hsp-card">
                    <h3>Connection Details</h3>
                    <div class="hsp-card-value" style="font-size: 18px; padding-top: 8px;">
                        <?php echo esc_html($dbName); ?>
                    </div>
                    <div class="hsp-card-subtext">Host: <?php echo esc_html($hostInfo); ?></div>
                </div>
                <div class="hsp-card">
                    <h3>Active Queue</h3>
                    <div class="hsp-card-value <?php echo $queueCounts['queued'] > 0 ? 'highlight-green' : ''; ?>">
                        <?php echo esc_html($queueCounts['queued']); ?>
                    </div>
                    <div class="hsp-card-subtext">Reserved: <?php echo esc_html($queueCounts['reserved']); ?> jobs</div>
                </div>
                <div class="hsp-card">
                    <h3>Dead Letter Queue</h3>
                    <div class="hsp-card-value <?php echo $dlqCount > 0 ? 'highlight-red' : ''; ?>">
                        <?php echo esc_html($dlqCount); ?>
                    </div>
                    <div class="hsp-card-subtext">Permanently failed jobs</div>
                </div>
                <div class="hsp-card">
                    <h3>Total Synced Events</h3>
                    <div class="hsp-card-value">
                        <?php echo esc_html($totalEvents); ?>
                    </div>
                    <div class="hsp-card-subtext">Captured outbox events</div>
                </div>
            </div>

            <!-- Actions Section -->
            <div class="hsp-section" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2 style="margin: 0 0 4px 0;">Sync Actions</h2>
                    <span style="color: #64748b; font-size: 14px;">Trigger queue processing manually from the browser.</span>
                </div>
                <div class="hsp-actions">
                    <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'hsp_sync_now']), 'hsp_manual_sync')); ?>" class="hsp-btn">
                        Force Sync Next Job
                    </a>
                    <a href="<?php echo esc_url(menu_page_url('headless-sync', false)); ?>" class="hsp-btn hsp-btn-secondary">
                        Refresh
                    </a>
                </div>
            </div>

            <!-- Workers Status -->
            <div class="hsp-section">
                <h2>Background Workers</h2>
                <div class="hsp-table-wrapper">
                    <table class="hsp-table">
                        <thead>
                            <tr>
                                <th>Worker ID</th>
                                <th>Status</th>
                                <th>Processed</th>
                                <th>Failed</th>
                                <th>Memory Usage</th>
                                <th>Last Heartbeat</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($workers)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: #94a3b8; padding: 24px;">No active background workers registered.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($workers as $worker): ?>
                                    <tr>
                                        <td><span class="hsp-code"><?php echo esc_html($worker['worker_id']); ?></span></td>
                                        <td>
                                            <span class="hsp-badge <?php echo esc_attr($worker['status']); ?>">
                                                <?php echo esc_html($worker['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html($worker['processed_count']); ?></td>
                                        <td><?php echo esc_html($worker['failed_count']); ?></td>
                                        <td><?php echo esc_html(round($worker['memory_bytes'] / 1024 / 1024, 2)); ?> MB</td>
                                        <td><?php echo esc_html($worker['last_heartbeat_at']); ?> UTC</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Outbox Activity -->
            <div class="hsp-section">
                <h2>Recent Outbox Events</h2>
                <div class="hsp-table-wrapper">
                    <table class="hsp-table">
                        <thead>
                            <tr>
                                <th>Event Type</th>
                                <th>Aggregate Target</th>
                                <th>Version</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentEvents)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: #94a3b8; padding: 24px;">No outbox events captured yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentEvents as $event): ?>
                                    <tr>
                                        <td><strong style="color: #0f172a;"><?php echo esc_html($event['event_type']); ?></strong></td>
                                        <td>
                                            <span class="hsp-badge stopped">
                                                <?php echo esc_html($event['aggregate_type']); ?>:<?php echo esc_html($event['aggregate_id']); ?>
                                            </span>
                                        </td>
                                        <td><span class="hsp-code">v<?php echo esc_html($event['aggregate_version']); ?></span></td>
                                        <td><?php echo esc_html($event['created_at']); ?> UTC</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get safe PDO connection or log errors.
     *
     * @return PDO|null
     */
    protected function getConnection(): ?PDO
    {
        try {
            return $this->app->make(PDO::class);
        } catch (Throwable $e) {
            $this->connectionError = $e->getMessage();
            return null;
        }
    }

    /**
     * Fetch counts of queue jobs from PostgreSQL.
     *
     * @param PDO|null $pdo
     * @return array
     */
    protected function getQueueCounts(?PDO $pdo): array
    {
        $counts = ['queued' => 0, 'reserved' => 0];
        if (!$pdo) {
            return $counts;
        }

        try {
            $stmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM system.queue_jobs GROUP BY status");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['status'] === 'queued') {
                    $counts['queued'] = (int) $row['cnt'];
                } elseif ($row['status'] === 'reserved') {
                    $counts['reserved'] = (int) $row['cnt'];
                }
            }
        } catch (Throwable $e) {
            // Ignore database fetch issues
        }

        return $counts;
    }

    /**
     * Fetch DLQ count from PostgreSQL.
     *
     * @param PDO|null $pdo
     * @return int
     */
    protected function getDlqCount(?PDO $pdo): int
    {
        if (!$pdo) {
            return 0;
        }

        try {
            return (int) $pdo->query("SELECT COUNT(*) FROM system.dead_letter_jobs")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Fetch total events count.
     *
     * @param PDO|null $pdo
     * @return int
     */
    protected function getEventsCount(?PDO $pdo): int
    {
        if (!$pdo) {
            return 0;
        }

        try {
            return (int) $pdo->query("SELECT COUNT(*) FROM system.events")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Fetch list of background workers.
     *
     * @param PDO|null $pdo
     * @return array
     */
    protected function getWorkersList(?PDO $pdo): array
    {
        if (!$pdo) {
            return [];
        }

        try {
            $stmt = $pdo->query("SELECT * FROM system.worker_heartbeats ORDER BY last_heartbeat_at DESC LIMIT 5");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Fetch recent events list.
     *
     * @param PDO|null $pdo
     * @return array
     */
    protected function getRecentEvents(?PDO $pdo): array
    {
        if (!$pdo) {
            return [];
        }

        try {
            $stmt = $pdo->query("SELECT event_type, aggregate_type, aggregate_id, aggregate_version, created_at FROM system.events ORDER BY created_at DESC LIMIT 10");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}
