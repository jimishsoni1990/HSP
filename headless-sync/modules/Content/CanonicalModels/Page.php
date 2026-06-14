<?php

namespace HSP\Modules\Content\CanonicalModels;

use HSP\Core\Contracts\CanonicalModelInterface;

/**
 * Canonical model representing a WordPress page in the projection layer.
 *
 * This is the source-agnostic, normalised shape of a page as stored in the
 * `content.pages` PostgreSQL table. It is constructed from raw event payloads
 * via {@see \HSP\Modules\Content\Transformers\PageTransformer}.
 */
class Page implements CanonicalModelInterface
{
    /** @var string Internal UUID (UUIDv7). */
    protected string $id;

    /** @var string The original WordPress post ID for the page. */
    protected string $sourcePostId;

    /** @var string URL-safe slug. */
    protected string $slug;

    /** @var string Page title. */
    protected string $title;

    /** @var string Publication status (publish, draft, trash, …). */
    protected string $status;

    /** @var string|null ISO-8601 creation timestamp. */
    protected ?string $createdAt;

    /** @var string|null ISO-8601 last-updated timestamp. */
    protected ?string $updatedAt;

    /** @var string|null ISO-8601 soft-delete timestamp, null when active. */
    protected ?string $deletedAt;

    /** @var int Aggregate version for optimistic concurrency. */
    protected int $aggregateVersion;

    /**
     * Construct a Page canonical model from an associative array.
     *
     * @param array<string, mixed> $properties {
     *     @type string      $id               Internal UUID.
     *     @type string      $sourcePostId     WordPress post ID.
     *     @type string      $slug             URL slug.
     *     @type string      $title            Page title.
     *     @type string      $status           Publication status.
     *     @type string|null $createdAt        Creation timestamp.
     *     @type string|null $updatedAt        Last-updated timestamp.
     *     @type string|null $deletedAt        Soft-delete timestamp.
     *     @type int         $aggregateVersion Version number.
     * }
     */
    public function __construct(array $properties = [])
    {
        $this->id               = (string) ($properties['id'] ?? '');
        $this->sourcePostId     = (string) ($properties['sourcePostId'] ?? '');
        $this->slug             = (string) ($properties['slug'] ?? '');
        $this->title            = (string) ($properties['title'] ?? '');
        $this->status           = (string) ($properties['status'] ?? 'publish');
        $this->createdAt        = $properties['createdAt'] ?? null;
        $this->updatedAt        = $properties['updatedAt'] ?? null;
        $this->deletedAt        = $properties['deletedAt'] ?? null;
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
            'status'           => $this->status,
            'createdAt'        => $this->createdAt,
            'updatedAt'        => $this->updatedAt,
            'deletedAt'        => $this->deletedAt,
            'aggregateVersion' => $this->aggregateVersion,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getAggregateType(): string
    {
        return 'page';
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
