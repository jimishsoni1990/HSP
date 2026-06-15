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
        if (function_exists('add_action')) {
            if (is_admin()) {
                add_action('admin_menu', [$this, 'addAdminMenu']);
                add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
                add_action('admin_init', [$this, 'handleManualSync']);
                add_action('admin_notices', [$this, 'displayAdminBarSyncNotice']);
                add_action('admin_post_hsp_admin_bar_sync', [$this, 'handleAdminBarSync']);
            }
            
            // Register Admin Bar button globally (for frontend and backend admin bar)
            add_action('admin_bar_menu', [$this, 'addAdminBarButton'], 100);
        }
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

        add_submenu_page(
            'headless-sync',
            'API Playground',
            'API Playground',
            'manage_options',
            'headless-sync-api',
            [$this, 'renderApiPlayground']
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
        if (strpos($hook, 'headless-sync') === false) {
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

    /**
     * Add sync button to the WordPress admin bar.
     *
     * @param \WP_Admin_Bar $wp_admin_bar
     * @return void
     */
    public function addAdminBarButton($wp_admin_bar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $wp_admin_bar->add_node([
            'id'    => 'hsp-sync-button',
            'title' => '<span class="ab-icon dashicons dashicons-update" style="top:2px;"></span><span class="ab-label">HSP: Sync Now</span>',
            'href'  => wp_nonce_url(admin_url('admin-post.php?action=hsp_admin_bar_sync'), 'hsp_admin_bar_sync_nonce'),
            'meta'  => [
                'title' => 'Sync Headless Outbox Queue Immediately',
            ]
        ]);
    }

    /**
     * Handle manual sync triggered from the Admin Bar.
     *
     * @return void
     */
    public function handleAdminBarSync(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'headless-sync'));
        }

        check_admin_referer('hsp_admin_bar_sync_nonce');

        try {
            $worker = $this->app->make(WorkerEngine::class);
            
            // Run worker to process all pending jobs in the queue
            $worker->work('content', [
                'max_jobs' => 1000,
                'max_runtime' => 30,
                'stop_when_empty' => true
            ]);

            $referrer = wp_get_referer();
            if ($referrer && strpos($referrer, 'wp-admin') !== false) {
                // If clicked from an admin page, redirect back there
                $referrer = remove_query_arg(['hsp_sync_status', 'hsp_sync_error'], $referrer);
                $redirectUrl = add_query_arg(['hsp_sync_status' => 'success'], $referrer);
            } else {
                // If clicked from frontend, redirect to Headless Sync admin page to show metrics
                $redirectUrl = add_query_arg(['hsp_sync_status' => 'success'], menu_page_url('headless-sync', false));
            }
            
            wp_safe_redirect($redirectUrl);
            exit;
        } catch (Throwable $e) {
            $referrer = wp_get_referer();
            if ($referrer && strpos($referrer, 'wp-admin') !== false) {
                $referrer = remove_query_arg(['hsp_sync_status', 'hsp_sync_error'], $referrer);
                $redirectUrl = add_query_arg([
                    'hsp_sync_status' => 'error',
                    'hsp_sync_error' => urlencode($e->getMessage())
                ], $referrer);
            } else {
                $redirectUrl = add_query_arg([
                    'hsp_sync_status' => 'error',
                    'hsp_sync_error' => urlencode($e->getMessage())
                ], menu_page_url('headless-sync', false));
            }
            
            wp_safe_redirect($redirectUrl);
            exit;
        }
    }

    /**
     * Display a notice in the WordPress admin panel after manual sync is run.
     *
     * @return void
     */
    public function displayAdminBarSyncNotice(): void
    {
        if (!isset($_GET['hsp_sync_status'])) {
            return;
        }

        if ($_GET['hsp_sync_status'] === 'success') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong><?php _e('HSP Sync:', 'headless-sync'); ?></strong> <?php _e('Queue processed successfully. Outbox is up to date.', 'headless-sync'); ?></p>
            </div>
            <?php
        } elseif ($_GET['hsp_sync_status'] === 'error') {
            $error = isset($_GET['hsp_sync_error']) ? urldecode($_GET['hsp_sync_error']) : 'Unknown error';
            ?>
            <div class="notice notice-error is-dismissible">
                <p><strong><?php _e('HSP Sync Failed:', 'headless-sync'); ?></strong> <?php echo esc_html($error); ?></p>
            </div>
            <?php
        }
    }

    /**
     * Render the API Playground tab/page.
     *
     * @return void
     */
    public function renderApiPlayground(): void
    {
        ?>
        <div class="wrap hsp-dashboard">
            <div class="hsp-header">
                <h1>API Playground</h1>
                <span class="hsp-status-badge connected">
                    <span class="hsp-status-dot"></span> REST Delivery API
                </span>
            </div>

            <div class="hsp-alert hsp-alert-info" style="background-color: #eff6ff; border-left: 4px solid #3b82f6; color: #1e3a8a; padding: 16px; border-radius: 12px; margin-bottom: 24px;">
                <strong>API Endpoint Testing Console:</strong> Use this playground to execute queries directly against your REST Delivery API server and inspect the returned JSON payloads.
            </div>

            <!-- Base URL configuration -->
            <div class="hsp-card" style="margin-bottom: 24px; padding: 20px; display: flex; align-items: center; gap: 16px; width: 100%; box-sizing: border-box;">
                <div style="flex-grow: 1;">
                    <label for="hsp-api-base-url" style="font-weight: 600; display: block; margin-bottom: 6px; font-size: 14px; color: #475569;">Delivery API Base URL</label>
                    <input type="url" id="hsp-api-base-url" value="http://localhost:9000" style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; font-family: monospace;" placeholder="http://localhost:9000" />
                </div>
                <div style="margin-top: 22px;">
                    <button id="hsp-test-connection" class="hsp-btn" style="height: 40px; padding: 0 20px; font-size: 14px; font-weight: 600; border-radius: 8px; border: none; cursor: pointer; transition: all 0.2s ease;">Test Connection</button>
                </div>
            </div>

            <!-- Main Layout Grid -->
            <div style="display: grid; grid-template-columns: 1.1fr 0.9fr; gap: 24px;">
                <!-- Endpoints Column -->
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <h2 style="font-size: 18px; font-weight: 700; margin: 0; color: #0f172a;">Available Endpoints</h2>

                    <!-- Endpoints Loop -->
                    <?php
                    $endpoints = [
                        [
                            'title' => 'API Health Status',
                            'method' => 'GET',
                            'path' => '/',
                            'description' => 'Verify that the Delivery API is online.',
                            'params' => []
                        ],
                        [
                            'title' => 'List All Published Posts',
                            'method' => 'GET',
                            'path' => '/api/v1/posts',
                            'description' => 'Retrieve a list of all published blog posts (excluding drafts/deleted).',
                            'params' => []
                        ],
                        [
                            'title' => 'Get Post by Slug',
                            'method' => 'GET',
                            'path' => '/api/v1/posts',
                            'description' => 'Query details of a single post by its URL slug.',
                            'params' => [
                                ['name' => 'slug', 'placeholder' => 'my-1st-post', 'value' => 'my-1st-post']
                            ]
                        ],
                        [
                            'title' => 'Filter Posts by Category Slug',
                            'method' => 'GET',
                            'path' => '/api/v1/posts',
                            'description' => 'Get all published posts matching a specific category.',
                            'params' => [
                                ['name' => 'category', 'placeholder' => 'headless', 'value' => 'headless']
                            ]
                        ],
                        [
                            'title' => 'List All Published Pages',
                            'method' => 'GET',
                            'path' => '/api/v1/pages',
                            'description' => 'Retrieve all synced pages.',
                            'params' => []
                        ],
                        [
                            'title' => 'Get Page by Slug',
                            'method' => 'GET',
                            'path' => '/api/v1/pages',
                            'description' => 'Query details of a single static page by its slug.',
                            'params' => [
                                ['name' => 'slug', 'placeholder' => 'about', 'value' => 'about']
                            ]
                        ],
                        [
                            'title' => 'List All Active Categories',
                            'method' => 'GET',
                            'path' => '/api/v1/categories',
                            'description' => 'Retrieve a list of all active categories.',
                            'params' => []
                        ],
                        [
                            'title' => 'Get Category by Slug',
                            'method' => 'GET',
                            'path' => '/api/v1/categories',
                            'description' => 'Query details of a single category by its slug.',
                            'params' => [
                                ['name' => 'slug', 'placeholder' => 'headless', 'value' => 'headless']
                            ]
                        ]
                    ];

                    foreach ($endpoints as $idx => $ep): ?>
                        <div class="hsp-card hsp-endpoint-card" data-path="<?php echo esc_attr($ep['path']); ?>" data-method="<?php echo esc_attr($ep['method']); ?>" style="padding: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                                <div>
                                    <span class="hsp-method-badge method-get" style="background-color: #dbeafe; color: #1e40af; padding: 4px 8px; border-radius: 6px; font-weight: 700; font-size: 12px; font-family: monospace; margin-right: 8px;"><?php echo esc_html($ep['method']); ?></span>
                                    <strong style="font-size: 15px; color: #0f172a;"><?php echo esc_html($ep['title']); ?></strong>
                                </div>
                                <span style="font-family: monospace; font-size: 13px; color: #64748b; background-color: #f1f5f9; padding: 2px 8px; border-radius: 4px;"><?php echo esc_html($ep['path']); ?></span>
                            </div>
                            <p style="color: #64748b; margin: 0 0 16px 0; font-size: 13px;"><?php echo esc_html($ep['description']); ?></p>

                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px;">
                                <div style="display: flex; gap: 8px; flex-grow: 1;">
                                    <?php foreach ($ep['params'] as $p): ?>
                                        <div style="display: flex; align-items: center; gap: 6px; width: 100%; max-width: 280px;">
                                            <span style="font-size: 12px; font-family: monospace; color: #94a3b8;"><?php echo esc_html($p['name']); ?>=</span>
                                            <input type="text" 
                                                   class="hsp-param-input" 
                                                   data-param-name="<?php echo esc_attr($p['name']); ?>" 
                                                   placeholder="<?php echo esc_attr($p['placeholder']); ?>" 
                                                   value="<?php echo esc_attr($p['value']); ?>" 
                                                   style="flex-grow: 1; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; font-family: monospace;" />
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button class="hsp-btn hsp-run-btn" style="padding: 8px 16px; font-size: 13px; font-weight: 600;">Run Endpoint</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Response Column -->
                <div>
                    <h2 style="font-size: 18px; font-weight: 700; margin: 0 0 16px 0; color: #0f172a;">Response Console</h2>
                    <div class="hsp-card" style="padding: 20px; position: sticky; top: 50px; min-height: 480px; display: flex; flex-direction: column;">
                        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 16px;">
                            <div>
                                <span id="hsp-resp-badge" class="hsp-status-badge" style="display: none; padding: 4px 10px; font-size: 12px;"></span>
                                <span id="hsp-resp-url" style="font-family: monospace; font-size: 12px; color: #94a3b8; margin-left: 8px;">No request sent yet</span>
                            </div>
                            <button id="hsp-copy-btn" class="hsp-btn hsp-btn-secondary" style="padding: 6px 12px; font-size: 12px; font-weight: 600; display: none;">Copy JSON</button>
                        </div>
                        <div id="hsp-console-body" style="flex-grow: 1; overflow-y: auto; background-color: #0f172a; border-radius: 8px; border: 1px solid #1e293b; color: #38bdf8; padding: 16px; font-family: 'Courier New', Courier, monospace; font-size: 13px; line-height: 1.5; white-space: pre; max-height: 600px;">
                            <div id="hsp-console-placeholder" style="color: #475569; text-align: center; margin-top: 180px;">
                                Click "Run Endpoint" to inspect query output here.
                            </div>
                            <code id="hsp-console-code" style="color: #38bdf8; background: none; border: none; padding: 0; display: none; white-space: pre-wrap; word-break: break-all;"></code>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const baseUrlInput = document.getElementById('hsp-api-base-url');
            const testConnBtn = document.getElementById('hsp-test-connection');
            const runButtons = document.querySelectorAll('.hsp-run-btn');
            
            const respBadge = document.getElementById('hsp-resp-badge');
            const respUrl = document.getElementById('hsp-resp-url');
            const copyBtn = document.getElementById('hsp-copy-btn');
            const consoleBody = document.getElementById('hsp-console-body');
            const consoleCode = document.getElementById('hsp-console-code');
            const placeholder = document.getElementById('hsp-console-placeholder');

            // Format JSON with simple html/color highlight if needed, or just plain text
            function updateConsole(text, isError = false, statusText = '') {
                placeholder.style.display = 'none';
                consoleCode.style.display = 'block';
                consoleCode.textContent = text;
                consoleCode.style.color = isError ? '#f87171' : '#38bdf8';
                
                if (statusText) {
                    respBadge.style.display = 'inline-flex';
                    respBadge.textContent = statusText;
                    respBadge.className = 'hsp-status-badge ' + (isError ? 'disconnected' : 'connected');
                } else {
                    respBadge.style.display = 'none';
                }
            }

            // Copy to clipboard
            copyBtn.addEventListener('click', function() {
                navigator.clipboard.writeText(consoleCode.textContent).then(function() {
                    const originalText = copyBtn.textContent;
                    copyBtn.textContent = 'Copied!';
                    setTimeout(() => { copyBtn.textContent = originalText; }, 1500);
                });
            });

            // Test Connection
            testConnBtn.addEventListener('click', function() {
                const baseUrl = baseUrlInput.value.replace(/\/$/, '');
                testConnBtn.textContent = 'Connecting...';
                testConnBtn.disabled = true;
                
                respUrl.textContent = baseUrl + '/';
                updateConsole('Pinging API Server health check endpoint...', false, 'PINGING');

                fetch(baseUrl + '/')
                    .then(response => {
                        if (!response.ok) throw new Error('HTTP status ' + response.status);
                        return response.json();
                    })
                    .then(data => {
                        testConnBtn.textContent = 'Connection OK!';
                        testConnBtn.style.backgroundColor = '#059669';
                        testConnBtn.style.color = '#ffffff';
                        copyBtn.style.display = 'inline-block';
                        updateConsole(JSON.stringify(data, null, 4), false, '200 OK');
                        setTimeout(() => {
                            testConnBtn.textContent = 'Test Connection';
                            testConnBtn.removeAttribute('style');
                        }, 2500);
                    })
                    .catch(err => {
                        testConnBtn.textContent = 'Failed';
                        testConnBtn.style.backgroundColor = '#dc2626';
                        testConnBtn.style.color = '#ffffff';
                        copyBtn.style.display = 'none';
                        updateConsole('API Connection Failed.\n\nVerify that:\n1. The Delivery API server is running on the specified port (default: php -S localhost:9000 delivery-api.php).\n2. No CORS block exists.\n\nError: ' + err.message, true, 'ERROR');
                        setTimeout(() => {
                            testConnBtn.textContent = 'Test Connection';
                            testConnBtn.removeAttribute('style');
                        }, 2500);
                    })
                    .finally(() => {
                        testConnBtn.disabled = false;
                    });
            });

            // Run Endpoints
            runButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    const card = btn.closest('.hsp-endpoint-card');
                    const path = card.getAttribute('data-path');
                    const method = card.getAttribute('data-method');
                    const baseUrl = baseUrlInput.value.replace(/\/$/, '');
                    
                    // Compile query parameters
                    let queryParams = [];
                    const inputs = card.querySelectorAll('.hsp-param-input');
                    inputs.forEach(input => {
                        const name = input.getAttribute('data-param-name');
                        const val = input.value.trim();
                        if (val) {
                            queryParams.push(encodeURIComponent(name) + '=' + encodeURIComponent(val));
                        }
                    });

                    let finalUrl = baseUrl + path;
                    if (queryParams.length > 0) {
                        finalUrl += '?' + queryParams.join('&');
                    }

                    // Set status
                    respUrl.textContent = finalUrl;
                    copyBtn.style.display = 'none';
                    updateConsole('Sending ' + method + ' request to ' + finalUrl + '...', false, 'LOADING');

                    fetch(finalUrl)
                        .then(response => {
                            const statusText = response.status + ' ' + response.statusText;
                            if (!response.ok) {
                                return response.text().then(text => {
                                    throw { statusText, message: text };
                                });
                            }
                            return response.json().then(json => ({ statusText, json }));
                        })
                        .then(({ statusText, json }) => {
                            copyBtn.style.display = 'inline-block';
                            updateConsole(JSON.stringify(json, null, 4), false, statusText);
                        })
                        .catch(err => {
                            copyBtn.style.display = 'none';
                            const errMsg = err.message || err.toString() || 'Unknown network error';
                            const statusLabel = err.statusText || 'ERR_CONNECTION';
                            updateConsole('Request failed.\n\nError Details:\n' + errMsg, true, statusLabel);
                        });
                });
            });
        });
        </script>
        <?php
    }
}
