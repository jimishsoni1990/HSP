<?php

namespace HSP\Core\Contracts;

/**
 * Contract for delivery adapters that persist or remove canonical models.
 *
 * Adapters are the final write-side of the outbox pipeline — they receive
 * fully-formed canonical models and apply them to a target data store.
 * Implementations must be idempotent: replaying the same model at the
 * same aggregate version should produce no side-effects.
 */
interface AdapterInterface
{
    /**
     * Persist a canonical model to the target store.
     *
     * Implementations should use the model's aggregate type to determine
     * the target table and the model's aggregate version for optimistic
     * concurrency checks.
     *
     * @param CanonicalModelInterface $model
     * @return void
     */
    public function persist(CanonicalModelInterface $model): void;

    /**
     * Delete an aggregate from the target store.
     *
     * @param string $aggregateType The aggregate type (e.g. 'post', 'page', 'category').
     * @param string $aggregateId   The unique aggregate identifier.
     * @return void
     */
    public function delete(string $aggregateType, string $aggregateId): void;
}
