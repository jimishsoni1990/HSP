<?php

namespace HSP\Modules\Content\CanonicalModels;

use HSP\Core\Contracts\CanonicalModelInterface;

/**
 * Canonical model representing a WordPress post in the projection layer.
 *
 * This is the source-agnostic, normalised shape of a post as stored in the
 * `content.posts` PostgreSQL table. It is constructed from raw event payloads
 * via {@see \HSP\Modules\Content\Transformers\PostTransformer}.
 */
class Post implements CanonicalModelInterface
{
    /** @var string Internal UUID (UUIDv7). */
    protected string $id;

    /** @var string The original WordPress post ID. */
    protected string $sourcePostId;

    /** @var string URL-safe slug. */
    protected string $slug;

    /** @var string Post title. */
    protected string $title;

    /** @var string Short excerpt / summary. */
    protected string $excerpt;

    /** @var string Full post content (HTML). */
    protected string $content;

    /** @var string Publication status (publish, draft, trash, …). */
    protected string $status;

    /** @var string|null ISO-8601 creation timestamp. */
    protected ?string $createdAt;

    /** @var string|null ISO-8601 last-updated timestamp. */
    protected ?string $updatedAt;

    /** @var string|null ISO-8601 soft-delete timestamp, null when active. */
    protected ?string $deletedAt;

    /** @var array<int|string> Associated category source IDs. */
    protected array $categories;

    /** @var array|null Extensible SEO metadata. */
    protected ?array $seo;

    /** @var int Aggregate version for optimistic concurrency. */
    protected int $aggregateVersion;

    /**
     * Construct a Post canonical model from an associative array.
     *
     * @param array<string, mixed> $properties {
     *     @type string      $id               Internal UUID.
     *     @type string      $sourcePostId     WordPress post ID.
     *     @type string      $slug             URL slug.
     *     @type string      $title            Post title.
     *     @type string      $excerpt          Post excerpt.
     *     @type string      $content          Full HTML content.
     *     @type string      $status           Publication status.
     *     @type string|null $createdAt        Creation timestamp.
     *     @type string|null $updatedAt        Last-updated timestamp.
     *     @type string|null $deletedAt        Soft-delete timestamp.
     *     @type array       $categories       Category source IDs.
     *     @type int         $aggregateVersion Version number.
     * }
     */
    public function __construct(array $properties = [])
    {
        $this->id               = (string) ($properties['id'] ?? '');
        $this->sourcePostId     = (string) ($properties['sourcePostId'] ?? '');
        $this->slug             = (string) ($properties['slug'] ?? '');
        $this->title            = (string) ($properties['title'] ?? '');
        $this->excerpt          = (string) ($properties['excerpt'] ?? '');
        $this->content          = (string) ($properties['content'] ?? '');
        $this->status           = (string) ($properties['status'] ?? 'publish');
        $this->createdAt        = $properties['createdAt'] ?? null;
        $this->updatedAt        = $properties['updatedAt'] ?? null;
        $this->deletedAt        = $properties['deletedAt'] ?? null;
        $this->categories       = (array) ($properties['categories'] ?? []);
        $this->seo              = $properties['seo'] ?? null;
        $this->aggregateVersion = (int) ($properties['aggregateVersion'] ?? 1);
    }

    // ── Getters ─────────────────────────────────────────────────────────

    public function getId(): string
    {
        return $this->id;
    }

    public function getSourcePostId(): string
    {
        return $this->sourcePostId;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getExcerpt(): string
    {
        return $this->excerpt;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?string
    {
        return $this->deletedAt;
    }

    /**
     * @return array<int|string>
     */
    public function getCategories(): array
    {
        return $this->categories;
    }

    public function getSeo(): ?array
    {
        return $this->seo;
    }

    // ── CanonicalModelInterface ─────────────────────────────────────────

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'sourcePostId'     => $this->sourcePostId,
            'slug'             => $this->slug,
            'title'            => $this->title,
            'excerpt'          => $this->excerpt,
            'content'          => $this->content,
            'status'           => $this->status,
            'createdAt'        => $this->createdAt,
            'updatedAt'        => $this->updatedAt,
            'deletedAt'        => $this->deletedAt,
            'categories'       => $this->categories,
            'seo'              => $this->seo,
            'aggregateVersion' => $this->aggregateVersion,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getAggregateType(): string
    {
        return 'post';
    }

    /**
     * {@inheritDoc}
     */
    public function getAggregateId(): string
    {
        return $this->sourcePostId;
    }

    /**
     * {@inheritDoc}
     */
    public function getAggregateVersion(): int
    {
        return $this->aggregateVersion;
    }
}
