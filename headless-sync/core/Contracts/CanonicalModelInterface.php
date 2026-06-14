<?php

namespace HSP\Core\Contracts;

/**
 * Interface for canonical domain models.
 *
 * Canonical models represent the normalized, source-agnostic shape of domain
 * entities as they are stored in the PostgreSQL projection layer. Every module
 * that projects aggregate state must implement this contract on its models.
 */
interface CanonicalModelInterface
{
    /**
     * Convert the model to an associative array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * Get the aggregate type identifier (e.g. 'post', 'page', 'category').
     *
     * @return string
     */
    public function getAggregateType(): string;

    /**
     * Get the source-system aggregate identifier.
     *
     * @return string
     */
    public function getAggregateId(): string;

    /**
     * Get the current aggregate version number.
     *
     * @return int
     */
    public function getAggregateVersion(): int;
}
