<?php

namespace HSP\Modules\Content\CanonicalModels;

use HSP\Core\Contracts\CanonicalModelInterface;

/**
 * Canonical model representing a WordPress category taxonomy term.
 *
 * This is the source-agnostic, normalised shape of a category as stored in
 * the `content.taxonomies` PostgreSQL table. It is constructed from raw event
 * payloads via {@see \HSP\Modules\Content\Transformers\CategoryTransformer}.
 */
class Category implements CanonicalModelInterface
{
    /** @var string Internal UUID (UUIDv7). */
    protected string $id;

    /** @var string The original WordPress term ID. */
    protected string $sourceTermId;

    /** @var string URL-safe slug. */
    protected string $slug;

    /** @var string Human-readable category name. */
    protected string $name;

    /** @var string Optional category description. */
    protected string $description;

    /** @var string|null ISO-8601 creation timestamp. */
    protected ?string $createdAt;

    /** @var string|null ISO-8601 last-updated timestamp. */
    protected ?string $updatedAt;

    /** @var string|null ISO-8601 soft-delete timestamp, null when active. */
    protected ?string $deletedAt;

    /** @var array|null Extensible SEO metadata. */
    protected ?array $seo;

    /** @var int Aggregate version for optimistic concurrency. */
    protected int $aggregateVersion;

    /**
     * Construct a Category canonical model from an associative array.
     *
     * @param array<string, mixed> $properties {
     *     @type string      $id               Internal UUID.
     *     @type string      $sourceTermId     WordPress term ID.
     *     @type string      $slug             URL slug.
     *     @type string      $name             Category name.
     *     @type string      $description      Category description.
     *     @type string|null $createdAt        Creation timestamp.
     *     @type string|null $updatedAt        Last-updated timestamp.
     *     @type string|null $deletedAt        Soft-delete timestamp.
     *     @type int         $aggregateVersion Version number.
     * }
     */
    public function __construct(array $properties = [])
    {
        $this->id               = (string) ($properties['id'] ?? '');
        $this->sourceTermId     = (string) ($properties['sourceTermId'] ?? '');
        $this->slug             = (string) ($properties['slug'] ?? '');
        $this->name             = (string) ($properties['name'] ?? '');
        $this->description      = (string) ($properties['description'] ?? '');
        $this->createdAt        = $properties['createdAt'] ?? null;
        $this->updatedAt        = $properties['updatedAt'] ?? null;
        $this->deletedAt        = $properties['deletedAt'] ?? null;
        $this->seo              = $properties['seo'] ?? null;
        $this->aggregateVersion = (int) ($properties['aggregateVersion'] ?? 1);
    }

    // ── Getters ─────────────────────────────────────────────────────────

    public function getId(): string
    {
        return $this->id;
    }

    public function getSourceTermId(): string
    {
        return $this->sourceTermId;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
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
            'sourceTermId'     => $this->sourceTermId,
            'slug'             => $this->slug,
            'name'             => $this->name,
            'description'      => $this->description,
            'createdAt'        => $this->createdAt,
            'updatedAt'        => $this->updatedAt,
            'deletedAt'        => $this->deletedAt,
            'seo'              => $this->seo,
            'aggregateVersion' => $this->aggregateVersion,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getAggregateType(): string
    {
        return 'category';
    }

    /**
     * {@inheritDoc}
     */
    public function getAggregateId(): string
    {
        return $this->sourceTermId;
    }

    /**
     * {@inheritDoc}
     */
    public function getAggregateVersion(): int
    {
        return $this->aggregateVersion;
    }
}
