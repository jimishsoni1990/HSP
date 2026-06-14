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

        if (!$postUuid) {
            $postUuid = EventBuilder::generateUuidV7();
            $stmt = $this->pdo->prepare("
                INSERT INTO content.posts (id, source_post_id, source_entity_type, slug, title, excerpt, content, status, deleted_at, created_at, updated_at)
                VALUES (:uuid, :id, :type, :slug, :title, :excerpt, :content, :status, :deleted_at, NOW(), NOW())
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
                'deleted_at' => $deletedAt
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE content.posts
                SET slug = :slug, title = :title, excerpt = :excerpt, content = :content, status = :status, deleted_at = :deleted_at, updated_at = NOW()
                WHERE id = :uuid
            ");
            $stmt->execute([
                'uuid' => $postUuid,
                'slug' => $slug,
                'title' => $title,
                'excerpt' => $excerpt,
                'content' => $content,
                'status' => $status,
                'deleted_at' => $deletedAt
            ]);
        }

        // Sync taxonomy relationships
        $categories = $post->getCategories();

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

        $stmt = $this->pdo->prepare("DELETE FROM content.posts WHERE source_post_id = :id");
        $stmt->execute(['id' => $postId]);
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

        if (!$pageUuid) {
            $pageUuid = EventBuilder::generateUuidV7();
            $stmt = $this->pdo->prepare("
                INSERT INTO content.pages (id, source_post_id, source_entity_type, slug, title, status, deleted_at, created_at, updated_at)
                VALUES (:uuid, :id, :type, :slug, :title, :status, :deleted_at, NOW(), NOW())
            ");
            $stmt->execute([
                'uuid' => $pageUuid,
                'id' => $pageId,
                'type' => 'page',
                'slug' => $slug,
                'title' => $title,
                'status' => $status,
                'deleted_at' => $deletedAt
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE content.pages
                SET slug = :slug, title = :title, status = :status, deleted_at = :deleted_at, updated_at = NOW()
                WHERE id = :uuid
            ");
            $stmt->execute([
                'uuid' => $pageUuid,
                'slug' => $slug,
                'title' => $title,
                'status' => $status,
                'deleted_at' => $deletedAt
            ]);
        }
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

        $stmt = $this->pdo->prepare("DELETE FROM content.pages WHERE source_post_id = :id");
        $stmt->execute(['id' => $pageId]);
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

        $stmt = $this->pdo->prepare("SELECT id FROM content.taxonomies WHERE source_term_id = :id");
        $stmt->execute(['id' => $termId]);
        $taxUuid = $stmt->fetchColumn();

        if (!$taxUuid) {
            $taxUuid = EventBuilder::generateUuidV7();
            $stmt = $this->pdo->prepare("
                INSERT INTO content.taxonomies (id, source_term_id, taxonomy_type, slug, name, description, deleted_at, created_at, updated_at)
                VALUES (:uuid, :id, 'category', :slug, :name, :description, NULL, NOW(), NOW())
            ");
            $stmt->execute([
                'uuid' => $taxUuid,
                'id' => $termId,
                'slug' => $slug,
                'name' => $name,
                'description' => $description
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE content.taxonomies
                SET slug = :slug, name = :name, description = :description, deleted_at = NULL, updated_at = NOW()
                WHERE id = :uuid
            ");
            $stmt->execute([
                'uuid' => $taxUuid,
                'slug' => $slug,
                'name' => $name,
                'description' => $description
            ]);
        }
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
            // Soft delete the taxonomy
            $stmt = $this->pdo->prepare("UPDATE content.taxonomies SET deleted_at = NOW() WHERE id = :uuid");
            $stmt->execute(['uuid' => $taxUuid]);

            // Hard delete relationships
            $stmt = $this->pdo->prepare("DELETE FROM content.entity_taxonomies WHERE taxonomy_id = :uuid");
            $stmt->execute(['uuid' => $taxUuid]);
        }
    }
}
