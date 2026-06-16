<?php

namespace HSP\Modules\Commerce;

use HSP\Core\Contracts\ModuleInterface;
use HSP\Core\Workers\WorkerEngine;
use HSP\Core\Events\EventEnvelope;
use HSP\Modules\Commerce\Events\CommerceEventTypes;
use HSP\Modules\Commerce\Transformers\ProductTransformer;
use HSP\Modules\Commerce\Transformers\ProductVariationTransformer;
use HSP\Modules\Commerce\Adapters\ProductPostgresAdapter;
use PDO;
use Throwable;

class Module implements ModuleInterface
{
    /**
     * @var PDO
     */
    protected PDO $pdo;

    /**
     * Module constructor.
     *
     * @param PDO $pdo
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Register services and dependencies.
     *
     * @return void
     */
    public function register(): void
    {
    }

    /**
     * Initialize runtime hooks and events.
     *
     * @return void
     */
    public function boot(): void
    {
        if (class_exists('HSP\Core\Workers\WorkerEngine')) {
            WorkerEngine::subscribe(CommerceEventTypes::PRODUCT_CREATED,   [$this, 'handleProductCreatedOrUpdated']);
            WorkerEngine::subscribe(CommerceEventTypes::PRODUCT_UPDATED,   [$this, 'handleProductCreatedOrUpdated']);
            WorkerEngine::subscribe(CommerceEventTypes::PRODUCT_DELETED,   [$this, 'handleProductDeleted']);
            WorkerEngine::subscribe(CommerceEventTypes::STOCK_UPDATED,     [$this, 'handleStockUpdated']);
            WorkerEngine::subscribe(CommerceEventTypes::VARIATION_CREATED, [$this, 'handleVariationCreatedOrUpdated']);
            WorkerEngine::subscribe(CommerceEventTypes::VARIATION_UPDATED, [$this, 'handleVariationCreatedOrUpdated']);
            WorkerEngine::subscribe(CommerceEventTypes::VARIATION_DELETED, [$this, 'handleVariationDeleted']);
        }
    }

    /**
     * Initialize migrations and resources.
     *
     * @return void
     */
    public function activate(): void
    {
        $migrationFile = __DIR__ . '/Migrations/01_create_commerce_tables.sql';
        if (file_exists($migrationFile)) {
            $sql = file_get_contents($migrationFile);
            $this->pdo->exec($sql);
        }
    }

    /**
     * Cleanup runtime hooks.
     *
     * @return void
     */
    public function deactivate(): void
    {
    }

    /**
     * Apply version migrations.
     *
     * @return void
     */
    public function upgrade(): void
    {
    }

    /**
     * Handle product created or updated events.
     *
     * @param EventEnvelope $envelope
     * @return void
     * @throws Throwable
     */
    public function handleProductCreatedOrUpdated(EventEnvelope $envelope): void
    {
        $payload = $envelope->getPayload();
        $product = ProductTransformer::fromEventPayload($payload, $envelope->getAggregateVersion());

        $adapter = new ProductPostgresAdapter($this->pdo);

        // Fetch old slug if exists to revalidate
        $oldSlug = null;
        $stmt = $this->pdo->prepare("SELECT slug FROM content.products WHERE source_post_id = :id");
        $stmt->execute([':id' => $product->getSourcePostId()]);
        $oldSlug = $stmt->fetchColumn() ?: null;

        $adapter->persist($product);

        $this->triggerRevalidation('product', 'update', $product->getSlug(), $oldSlug, $product->getCategoryIds());
    }

    /**
     * Handle product deleted events.
     *
     * @param EventEnvelope $envelope
     * @return void
     */
    public function handleProductDeleted(EventEnvelope $envelope): void
    {
        $adapter = new ProductPostgresAdapter($this->pdo);
        $sourcePostId = $envelope->getAggregateId();

        // Get slug before updating
        $stmt = $this->pdo->prepare("SELECT slug, category_ids FROM content.products WHERE source_post_id = :id");
        $stmt->execute([':id' => $sourcePostId]);
        $row = $stmt->fetch();

        $adapter->delete('product', $sourcePostId);

        if ($row) {
            $categoryIds = json_decode($row['category_ids'] ?? '[]', true) ?: [];
            $this->triggerRevalidation('product', 'delete', $row['slug'], null, $categoryIds);
        }
    }

    /**
     * Handle stock updated events.
     *
     * @param EventEnvelope $envelope
     * @return void
     */
    public function handleStockUpdated(EventEnvelope $envelope): void
    {
        $payload = $envelope->getPayload();
        $sourcePostId = (string) ($payload['ID'] ?? '');

        $sql = "UPDATE content.products 
                SET stock_quantity = :qty, stock_status = :status, manage_stock = :manage, updated_at = NOW() 
                WHERE source_post_id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':qty' => isset($payload['stock_quantity']) ? (int) $payload['stock_quantity'] : null,
            ':status' => (string) ($payload['stock_status'] ?? 'instock'),
            ':manage' => (bool) ($payload['manage_stock'] ?? false),
            ':id' => $sourcePostId
        ]);

        // Get parent slug to revalidate
        $slugStmt = $this->pdo->prepare("SELECT slug, category_ids FROM content.products WHERE source_post_id = :id");
        $slugStmt->execute([':id' => $sourcePostId]);
        $row = $slugStmt->fetch();
        if ($row) {
            $categoryIds = json_decode($row['category_ids'] ?? '[]', true) ?: [];
            $this->triggerRevalidation('product', 'update', $row['slug'], null, $categoryIds);
        }
    }

    /**
     * Handle variation created or updated events.
     *
     * @param EventEnvelope $envelope
     * @return void
     * @throws Throwable
     */
    public function handleVariationCreatedOrUpdated(EventEnvelope $envelope): void
    {
        $payload = $envelope->getPayload();
        $variation = ProductVariationTransformer::fromEventPayload($payload, $envelope->getAggregateVersion());

        $adapter = new ProductPostgresAdapter($this->pdo);
        $adapter->persist($variation);

        // Parent product needs revalidation
        $parentPostId = (string) ($payload['parent_product_id'] ?? '');
        $stmt = $this->pdo->prepare("SELECT slug, category_ids FROM content.products WHERE source_post_id = :id");
        $stmt->execute([':id' => $parentPostId]);
        $row = $stmt->fetch();
        if ($row) {
            $categoryIds = json_decode($row['category_ids'] ?? '[]', true) ?: [];
            $this->triggerRevalidation('product', 'update', $row['slug'], null, $categoryIds);
        }
    }

    /**
     * Handle variation deleted events.
     *
     * @param EventEnvelope $envelope
     * @return void
     */
    public function handleVariationDeleted(EventEnvelope $envelope): void
    {
        $adapter = new ProductPostgresAdapter($this->pdo);
        $sourceVariationId = $envelope->getAggregateId();

        $payload = $envelope->getPayload();
        $parentPostId = (string) ($payload['parent_product_id'] ?? '');

        $adapter->delete('product_variation', $sourceVariationId);

        // Recalculate parent price ranges
        $parentStmt = $this->pdo->prepare("SELECT id, slug, category_ids FROM content.products WHERE source_post_id = :id");
        $parentStmt->execute([':id' => $parentPostId]);
        $parentRow = $parentStmt->fetch();

        if ($parentRow) {
            $parentUuid = $parentRow['id'];
            $recalcSql = "UPDATE content.products
                          SET price_min = (
                                SELECT MIN(price) FROM content.product_variations
                                WHERE product_id = :product_uuid AND is_enabled = TRUE
                              ),
                              price_max = (
                                SELECT MAX(price) FROM content.product_variations
                                WHERE product_id = :product_uuid AND is_enabled = TRUE
                              ),
                              updated_at = NOW()
                          WHERE id = :product_uuid";
            $recalcStmt = $this->pdo->prepare($recalcSql);
            $recalcStmt->execute([':product_uuid' => $parentUuid]);

            $categoryIds = json_decode($parentRow['category_ids'] ?? '[]', true) ?: [];
            $this->triggerRevalidation('product', 'update', $parentRow['slug'], null, $categoryIds);
        }
    }

    /**
     * Trigger Next.js cache revalidation webhook.
     *
     * @param string $type
     * @param string $action
     * @param string $slug
     * @param string|null $oldSlug
     * @param array $categoryIds
     * @return void
     */
    protected function triggerRevalidation(string $type, string $action, string $slug, ?string $oldSlug = null, array $categoryIds = []): void
    {
        $url = getenv('REVALIDATION_URL');
        $secret = getenv('REVALIDATION_SECRET');

        $paths = ['/', '/products']; // Homepage and PLP are always revalidated

        if ($type === 'product') {
            $paths[] = "/products/{$slug}";
            if ($oldSlug && $oldSlug !== $slug) {
                $paths[] = "/products/{$oldSlug}";
            }

            // Category filtered PLPs
            if (!empty($categoryIds)) {
                try {
                    $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
                    $catStmt = $this->pdo->prepare("SELECT slug FROM content.taxonomies WHERE source_term_id IN ($placeholders) AND deleted_at IS NULL");
                    $catStmt->execute($categoryIds);
                    $catSlugs = $catStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
                    foreach ($catSlugs as $catSlug) {
                        if ($catSlug) {
                            $paths[] = "/products?category={$catSlug}";
                        }
                    }
                } catch (Throwable $e) {
                    // Fail silently
                }
            }
        }

        // Deduplicate paths
        $paths = array_values(array_unique($paths));

        // Log in system.audit_log
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO system.audit_log (action, target_type, target_id, details, created_at)
                VALUES (:action, :target_type, :target_id, :details, NOW())
            ");
            $stmt->execute([
                'action' => 'cache_revalidation_triggered',
                'target_type' => 'commerce_' . $type,
                'target_id' => substr($slug, 0, 50),
                'details' => json_encode([
                    'webhook_url' => $url ?: 'NOT_CONFIGURED',
                    'paths' => $paths,
                    'payload' => [
                        'type' => $type,
                        'action' => $action,
                        'slug' => $slug,
                        'oldSlug' => $oldSlug,
                    ],
                    'status' => (empty($url) || empty($secret)) ? 'skipped_no_config' : 'initiated'
                ])
            ]);
        } catch (Throwable $dbEx) {
            // ignore
        }

        if (empty($url) || empty($secret)) {
            return;
        }

        // Fire HTTP purges
        foreach ($paths as $path) {
            if (function_exists('add_query_arg')) {
                $requestUrl = add_query_arg([
                    'secret' => $secret,
                    'path'   => $path
                ], $url);
            } else {
                $requestUrl = $url . (strpos($url, '?') === false ? '?' : '&')
                    . 'secret=' . urlencode($secret)
                    . '&path=' . urlencode($path);
            }

            if (function_exists('wp_remote_post')) {
                try {
                    wp_remote_post($requestUrl, [
                        'method'      => 'POST',
                        'blocking'    => false,
                        'sslverify'   => false,
                        'timeout'     => 0.05,
                        'headers'     => [
                            'Content-Type' => 'application/json',
                        ],
                        'body'        => json_encode([
                            'secret' => $secret,
                            'path'   => $path
                        ]),
                    ]);
                } catch (Throwable $e) {
                    if (function_exists('error_log')) {
                        error_log("Headless Sync (Commerce): Failed to dispatch revalidation webhook for path {$path}: " . $e->getMessage());
                    }
                }
            }
        }
    }
}
