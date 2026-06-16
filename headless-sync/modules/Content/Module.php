<?php

namespace HSP\Modules\Content;

use HSP\Core\Contracts\ModuleInterface;
use HSP\Core\Workers\WorkerEngine;
use HSP\Core\Events\EventEnvelope;
use HSP\Core\Events\EventBuilder;
use HSP\Modules\Content\Events\ContentEventTypes;
use HSP\Modules\Content\Transformers\PostTransformer;
use HSP\Modules\Content\Transformers\PageTransformer;
use HSP\Modules\Content\Transformers\CategoryTransformer;
use PDO;

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
     * Subscribes to all content event types so the worker engine can
     * dispatch incoming events to the appropriate projection handlers.
     *
     * @return void
     */
    public function boot(): void
    {
        if (class_exists('HSP\Core\Workers\WorkerEngine')) {
            WorkerEngine::subscribe(ContentEventTypes::POST_CREATED, [$this, 'handlePostCreatedOrUpdated']);
            WorkerEngine::subscribe(ContentEventTypes::POST_UPDATED, [$this, 'handlePostCreatedOrUpdated']);
            WorkerEngine::subscribe(ContentEventTypes::POST_DELETED, [$this, 'handlePostDeleted']);

            WorkerEngine::subscribe(ContentEventTypes::PAGE_CREATED, [$this, 'handlePageCreatedOrUpdated']);
            WorkerEngine::subscribe(ContentEventTypes::PAGE_UPDATED, [$this, 'handlePageCreatedOrUpdated']);
            WorkerEngine::subscribe(ContentEventTypes::PAGE_DELETED, [$this, 'handlePageDeleted']);

            WorkerEngine::subscribe(ContentEventTypes::CATEGORY_CREATED, [$this, 'handleCategoryCreatedOrUpdated']);
            WorkerEngine::subscribe(ContentEventTypes::CATEGORY_UPDATED, [$this, 'handleCategoryCreatedOrUpdated']);
            WorkerEngine::subscribe(ContentEventTypes::CATEGORY_DELETED, [$this, 'handleCategoryDeleted']);
        }
    }

    /**
     * Initialize migrations and resources.
     *
     * Loads and executes the content schema migration SQL file to create
     * the required PostgreSQL tables for content projections.
     *
     * @return void
     */
    public function activate(): void
    {
        $migrationFile = __DIR__ . '/Migrations/01_create_content_tables.sql';
        if (file_exists($migrationFile)) {
            $sql = file_get_contents($migrationFile);
            $this->pdo->exec($sql);
        }

        // Run schema updates for existing installations
        try {
            $this->pdo->exec("ALTER TABLE content.posts ADD COLUMN IF NOT EXISTS seo JSONB;");
            $this->pdo->exec("ALTER TABLE content.posts ADD COLUMN IF NOT EXISTS featured_image_url TEXT;");
            $this->pdo->exec("ALTER TABLE content.pages ADD COLUMN IF NOT EXISTS seo JSONB;");
            $this->pdo->exec("ALTER TABLE content.taxonomies ADD COLUMN IF NOT EXISTS seo JSONB;");
        } catch (\Throwable $e) {
            // Ignore if driver doesn't support ADD COLUMN IF NOT EXISTS
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
     * Handle post created/updated events by projecting them to PostgreSQL.
     *
     * Uses PostTransformer to convert the raw event payload into a canonical
     * Post model, then upserts the data into the content.posts table and
     * synchronises taxonomy relationships in content.entity_taxonomies.
     *
     * @param EventEnvelope $envelope
     * @return void
     */
    public function handlePostCreatedOrUpdated(EventEnvelope $envelope): void
    {
        $payload = $envelope->getPayload();
        $post = PostTransformer::fromEventPayload($payload);

        $postId = $post->getAggregateId();
        $slug = $post->getSlug();
        $title = $post->getTitle();
        $excerpt = $post->getExcerpt();
        $content = $post->getContent();
        $status = $post->getStatus();
        $deletedAt = $post->getDeletedAt();

        // Check if exists
        $stmt = $this->pdo->prepare("SELECT id FROM content.posts WHERE source_post_id = :id");
        $stmt->execute(['id' => $postId]);
        $postUuid = $stmt->fetchColumn();

        $isNew = !$postUuid;

        // Retrieve old slug before upsert
        $oldSlug = null;
        if ($postUuid) {
            $stmt = $this->pdo->prepare("SELECT slug FROM content.posts WHERE id = :uuid");
            $stmt->execute(['uuid' => $postUuid]);
            $oldSlug = $stmt->fetchColumn() ?: null;
        }

        $seo = $post->getSeo() ? json_encode($post->getSeo()) : null;
        $featuredImageUrl = $post->getFeaturedImageUrl();

        if (!$postUuid) {
            $postUuid = EventBuilder::generateUuidV7();
            $stmt = $this->pdo->prepare("
                INSERT INTO content.posts (id, source_post_id, source_entity_type, slug, title, excerpt, content, status, seo, featured_image_url, deleted_at, created_at, updated_at)
                VALUES (:uuid, :id, :type, :slug, :title, :excerpt, :content, :status, :seo, :featured_image, :deleted_at, NOW(), NOW())
            ");
            $stmt->execute([
                'uuid' => $postUuid,
                'id' => $postId,
                'type' => 'post',
                'slug' => $slug,
                'title' => $title,
                'excerpt' => $excerpt,
                'content' => $content,
                'status' => $status,
                'seo' => $seo,
                'featured_image' => $featuredImageUrl,
                'deleted_at' => $deletedAt
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE content.posts
                SET slug = :slug, title = :title, excerpt = :excerpt, content = :content, status = :status, seo = :seo, featured_image_url = :featured_image, deleted_at = :deleted_at, updated_at = NOW()
                WHERE id = :uuid
            ");
            $stmt->execute([
                'uuid' => $postUuid,
                'slug' => $slug,
                'title' => $title,
                'excerpt' => $excerpt,
                'content' => $content,
                'status' => $status,
                'seo' => $seo,
                'featured_image' => $featuredImageUrl,
                'deleted_at' => $deletedAt
            ]);
        }

        // Sync taxonomy relationships
        $categories = $post->getCategories();

        $oldCategorySlugs = [];
        if ($postUuid) {
            $oldCatStmt = $this->pdo->prepare("
                SELECT t.slug FROM content.taxonomies t
                JOIN content.entity_taxonomies et ON et.taxonomy_id = t.id
                WHERE et.entity_id = :post_uuid AND t.deleted_at IS NULL
            ");
            $oldCatStmt->execute(['post_uuid' => $postUuid]);
            $oldCategorySlugs = $oldCatStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        $stmt = $this->pdo->prepare("DELETE FROM content.entity_taxonomies WHERE entity_id = :entity_id");
        $stmt->execute(['entity_id' => $postUuid]);

        foreach ($categories as $catId) {
            $stmt = $this->pdo->prepare("SELECT id FROM content.taxonomies WHERE source_term_id = :cat_id");
            $stmt->execute(['cat_id' => (string) $catId]);
            $catUuid = $stmt->fetchColumn();

            if ($catUuid) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO content.entity_taxonomies (entity_id, taxonomy_id)
                    VALUES (:entity_id, :taxonomy_id)
                    ON CONFLICT DO NOTHING
                ");
                $stmt->execute([
                    'entity_id' => $postUuid,
                    'taxonomy_id' => $catUuid
                ]);
            }
        }

        // Get category slugs associated with this post's category IDs
        $categorySlugs = [];
        if (!empty($categories)) {
            $placeholders = implode(',', array_fill(0, count($categories), '?'));
            $catStmt = $this->pdo->prepare("
                SELECT slug FROM content.taxonomies 
                WHERE source_term_id IN ($placeholders) 
                  AND deleted_at IS NULL
            ");
            $stringCategories = array_map('strval', $categories);
            $catStmt->execute($stringCategories);
            $categorySlugs = $catStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            
            // Fallback to WP function if in WP environment and DB didn't find all
            if (count($categorySlugs) < count($categories) && function_exists('get_term')) {
                foreach ($categories as $catId) {
                    $term = get_term($catId, 'category');
                    if ($term && !is_wp_error($term) && !empty($term->slug)) {
                        if (!in_array($term->slug, $categorySlugs)) {
                            $categorySlugs[] = $term->slug;
                        }
                    }
                }
            }
        }

        $action = $isNew ? 'create' : 'update';
        if ($status === 'trash') {
            $action = 'delete';
        }

        $allCategorySlugs = array_unique(array_merge($categorySlugs, $oldCategorySlugs));
        $this->triggerRevalidation('post', $action, $slug, $oldSlug, $allCategorySlugs);
    }

    /**
     * Handle post deleted events.
     *
     * @param EventEnvelope $envelope
     * @return void
     */
    public function handlePostDeleted(EventEnvelope $envelope): void
    {
        $payload = $envelope->getPayload();
        $postId = (string) ($payload['ID'] ?? '');

        // Fetch post slug and category slugs before deleting it
        $slug = null;
        $categorySlugs = [];
        
        $stmt = $this->pdo->prepare("
            SELECT p.slug, p.id 
            FROM content.posts p 
            WHERE p.source_post_id = :id
        ");
        $stmt->execute(['id' => $postId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $slug = $row['slug'];
            $postUuid = $row['id'];
            
            // Get category slugs associated with this post
            $catStmt = $this->pdo->prepare("
                SELECT t.slug 
                FROM content.taxonomies t
                JOIN content.entity_taxonomies et ON et.taxonomy_id = t.id
                WHERE et.entity_id = :post_uuid AND t.deleted_at IS NULL
            ");
            $catStmt->execute(['post_uuid' => $postUuid]);
            $categorySlugs = $catStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        $stmt = $this->pdo->prepare("DELETE FROM content.posts WHERE source_post_id = :id");
        $stmt->execute(['id' => $postId]);

        if ($slug) {
            $this->triggerRevalidation('post', 'delete', $slug, null, $categorySlugs);
        }
    }

    /**
     * Handle page created/updated events by projecting them to PostgreSQL.
     *
     * Uses PageTransformer to convert the raw event payload into a canonical
     * Page model, then upserts the data into the content.pages table.
     *
     * @param EventEnvelope $envelope
     * @return void
     */
    public function handlePageCreatedOrUpdated(EventEnvelope $envelope): void
    {
        $payload = $envelope->getPayload();
        $page = PageTransformer::fromEventPayload($payload);

        $pageId = $page->getAggregateId();
        $slug = $page->getSlug();
        $title = $page->getTitle();
        $status = $page->getStatus();
        $deletedAt = $page->getDeletedAt();

        // Check if exists
        $stmt = $this->pdo->prepare("SELECT id FROM content.pages WHERE source_post_id = :id");
        $stmt->execute(['id' => $pageId]);
        $pageUuid = $stmt->fetchColumn();

        $isNew = !$pageUuid;

        // Retrieve old slug before upsert
        $oldSlug = null;
        if ($pageUuid) {
            $stmt = $this->pdo->prepare("SELECT slug FROM content.pages WHERE id = :uuid");
            $stmt->execute(['uuid' => $pageUuid]);
            $oldSlug = $stmt->fetchColumn() ?: null;
        }

        $seo = $page->getSeo() ? json_encode($page->getSeo()) : null;

        if (!$pageUuid) {
            $pageUuid = EventBuilder::generateUuidV7();
            $stmt = $this->pdo->prepare("
                INSERT INTO content.pages (id, source_post_id, source_entity_type, slug, title, status, seo, deleted_at, created_at, updated_at)
                VALUES (:uuid, :id, :type, :slug, :title, :status, :seo, :deleted_at, NOW(), NOW())
            ");
            $stmt->execute([
                'uuid' => $pageUuid,
                'id' => $pageId,
                'type' => 'page',
                'slug' => $slug,
                'title' => $title,
                'status' => $status,
                'seo' => $seo,
                'deleted_at' => $deletedAt
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE content.pages
                SET slug = :slug, title = :title, status = :status, seo = :seo, deleted_at = :deleted_at, updated_at = NOW()
                WHERE id = :uuid
            ");
            $stmt->execute([
                'uuid' => $pageUuid,
                'slug' => $slug,
                'title' => $title,
                'status' => $status,
                'seo' => $seo,
                'deleted_at' => $deletedAt
            ]);
        }

        $action = $isNew ? 'create' : 'update';
        if ($status === 'trash') {
            $action = 'delete';
        }

        $this->triggerRevalidation('page', $action, $slug, $oldSlug);
    }

    /**
     * Handle page deleted events.
     *
     * @param EventEnvelope $envelope
     * @return void
     */
    public function handlePageDeleted(EventEnvelope $envelope): void
    {
        $payload = $envelope->getPayload();
        $pageId = (string) ($payload['ID'] ?? '');

        // Retrieve slug before deleting
        $slug = null;
        $stmt = $this->pdo->prepare("SELECT slug FROM content.pages WHERE source_post_id = :id");
        $stmt->execute(['id' => $pageId]);
        $slug = $stmt->fetchColumn() ?: null;

        $stmt = $this->pdo->prepare("DELETE FROM content.pages WHERE source_post_id = :id");
        $stmt->execute(['id' => $pageId]);

        if ($slug) {
            $this->triggerRevalidation('page', 'delete', $slug);
        }
    }

    /**
     * Handle category created/updated events.
     *
     * Uses CategoryTransformer to convert the raw event payload into a
     * canonical Category model, then upserts into content.taxonomies.
     *
     * @param EventEnvelope $envelope
     * @return void
     */
    public function handleCategoryCreatedOrUpdated(EventEnvelope $envelope): void
    {
        $payload = $envelope->getPayload();
        $category = CategoryTransformer::fromEventPayload($payload);

        $termId = $category->getAggregateId();
        $name = $category->getName();
        $slug = $category->getSlug();
        $description = $category->getDescription();
        $seo = $category->getSeo() ? json_encode($category->getSeo()) : null;

        $stmt = $this->pdo->prepare("SELECT id FROM content.taxonomies WHERE source_term_id = :id");
        $stmt->execute(['id' => $termId]);
        $taxUuid = $stmt->fetchColumn();

        $isNew = !$taxUuid;

        // Retrieve old slug before upsert
        $oldSlug = null;
        if ($taxUuid) {
            $stmt = $this->pdo->prepare("SELECT slug FROM content.taxonomies WHERE id = :uuid");
            $stmt->execute(['uuid' => $taxUuid]);
            $oldSlug = $stmt->fetchColumn() ?: null;
        }

        if (!$taxUuid) {
            $taxUuid = EventBuilder::generateUuidV7();
            $stmt = $this->pdo->prepare("
                INSERT INTO content.taxonomies (id, source_term_id, taxonomy_type, slug, name, description, seo, deleted_at, created_at, updated_at)
                VALUES (:uuid, :id, 'category', :slug, :name, :description, :seo, NULL, NOW(), NOW())
            ");
            $stmt->execute([
                'uuid' => $taxUuid,
                'id' => $termId,
                'slug' => $slug,
                'name' => $name,
                'description' => $description,
                'seo' => $seo
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE content.taxonomies
                SET slug = :slug, name = :name, description = :description, seo = :seo, deleted_at = NULL, updated_at = NOW()
                WHERE id = :uuid
            ");
            $stmt->execute([
                'uuid' => $taxUuid,
                'slug' => $slug,
                'name' => $name,
                'description' => $description,
                'seo' => $seo
            ]);
        }

        $action = $isNew ? 'create' : 'update';

        $postSlugs = [];
        if ($oldSlug && $oldSlug !== $slug) {
            $postStmt = $this->pdo->prepare("
                SELECT p.slug FROM content.posts p
                JOIN content.entity_taxonomies et ON et.entity_id = p.id
                JOIN content.taxonomies t ON t.id = et.taxonomy_id
                WHERE t.id = :tax_uuid AND p.deleted_at IS NULL
            ");
            $postStmt->execute(['tax_uuid' => $taxUuid]);
            $postSlugs = $postStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        $this->triggerRevalidation('category', $action, $slug, $oldSlug, $postSlugs);
    }

    /**
     * Handle category deletion events.
     *
     * @param EventEnvelope $envelope
     * @return void
     */
    public function handleCategoryDeleted(EventEnvelope $envelope): void
    {
        $payload = $envelope->getPayload();
        $termId = (string) ($payload['term_id'] ?? '');

        $stmt = $this->pdo->prepare("SELECT id FROM content.taxonomies WHERE source_term_id = :id");
        $stmt->execute(['id' => $termId]);
        $taxUuid = $stmt->fetchColumn();

        if ($taxUuid) {
            // Retrieve slug before soft delete/deleting relationships
            $stmtSlug = $this->pdo->prepare("SELECT slug FROM content.taxonomies WHERE id = :uuid");
            $stmtSlug->execute(['uuid' => $taxUuid]);
            $slug = $stmtSlug->fetchColumn() ?: null;

            // Soft delete the taxonomy
            $stmt = $this->pdo->prepare("UPDATE content.taxonomies SET deleted_at = NOW() WHERE id = :uuid");
            $stmt->execute(['uuid' => $taxUuid]);

            // Hard delete relationships
            $stmt = $this->pdo->prepare("DELETE FROM content.entity_taxonomies WHERE taxonomy_id = :uuid");
            $stmt->execute(['uuid' => $taxUuid]);

            if ($slug) {
                $this->triggerRevalidation('category', 'delete', $slug);
            }
        }
    }

    /**
     * Trigger Next.js cache revalidation webhook.
     * Computes the paths to revalidate, logs the list of paths in system.audit_log
     * under the action 'cache_revalidation_triggered', and dispatches non-blocking
     * POST requests to the Next.js API route handler for each path.
     *
     * @param string $type The entity type ('post', 'page', 'category')
     * @param string $action The action ('create', 'update', 'delete')
     * @param string $slug The entity slug
     * @param string|null $oldSlug The old slug if it was changed
     * @param array $categories Array of category slugs (only for post type)
     * @return void
     */
    protected function triggerRevalidation(string $type, string $action, string $slug, ?string $oldSlug = null, array $categories = []): void
    {
        $url = getenv('REVALIDATION_URL');
        $secret = getenv('REVALIDATION_SECRET');

        // 1. Compute paths to revalidate
        $paths = ['/']; // Homepage is always revalidated

        if ($type === 'post') {
            $paths[] = "/posts/{$slug}";
            if ($oldSlug && $oldSlug !== $slug) {
                $paths[] = "/posts/{$oldSlug}";
            }
            foreach ($categories as $catSlug) {
                if ($catSlug) {
                    $paths[] = "/category/{$catSlug}";
                }
            }
        } elseif ($type === 'page') {
            $paths[] = "/pages/{$slug}";
            if ($oldSlug && $oldSlug !== $slug) {
                $paths[] = "/pages/{$oldSlug}";
            }
        } elseif ($type === 'category') {
            $paths[] = "/category/{$slug}";
            if ($oldSlug && $oldSlug !== $slug) {
                $paths[] = "/category/{$oldSlug}";
            }
            foreach ($categories as $postSlug) {
                if ($postSlug) {
                    $paths[] = "/posts/{$postSlug}";
                }
            }
        }

        // Deduplicate paths
        $paths = array_values(array_unique($paths));

        // 2. Log in system.audit_log under action 'cache_revalidation_triggered'
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO system.audit_log (action, target_type, target_id, details, created_at)
                VALUES (:action, :target_type, :target_id, :details, NOW())
            ");
            $stmt->execute([
                'action' => 'cache_revalidation_triggered',
                'target_type' => $type,
                'target_id' => substr($slug, 0, 50),
                'details' => json_encode([
                    'webhook_url' => $url ?: 'NOT_CONFIGURED',
                    'paths' => $paths,
                    'payload' => [
                        'type' => $type,
                        'action' => $action,
                        'slug' => $slug,
                        'oldSlug' => $oldSlug,
                        'categories' => $categories,
                    ],
                    'status' => (empty($url) || empty($secret)) ? 'skipped_no_config' : 'initiated'
                ])
            ]);
        } catch (\Throwable $dbEx) {
            // Gracefully ignore database audit log failures
        }

        if (empty($url) || empty($secret)) {
            return;
        }

        // 3. Fire non-blocking HTTP requests for each path to REVALIDATION_URL
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
                    $response = wp_remote_post($requestUrl, [
                        'method'      => 'POST',
                        'blocking'    => false, // Non-blocking
                        'sslverify'   => false, // Support local dev
                        'timeout'     => 0.05,
                        'headers'     => [
                            'Content-Type' => 'application/json',
                        ],
                        'body'        => json_encode([
                            'secret' => $secret,
                            'path'   => $path
                        ]),
                    ]);
                    if (function_exists('is_wp_error') && is_wp_error($response)) {
                        if (function_exists('error_log')) {
                            error_log("Headless Sync: Failed to dispatch revalidation webhook for path {$path}: " . $response->get_error_message());
                        }
                    }
                } catch (\Throwable $e) {
                    if (function_exists('error_log')) {
                        error_log("Headless Sync: Failed to dispatch revalidation webhook for path {$path}: " . $e->getMessage());
                    }
                }
            }
        }
    }
}
