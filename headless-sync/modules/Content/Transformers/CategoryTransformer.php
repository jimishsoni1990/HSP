<?php

namespace HSP\Modules\Content\Transformers;

use HSP\Modules\Content\CanonicalModels\Category;

/**
 * Transforms raw WordPress event payloads into canonical Category models.
 *
 * This transformer maps WordPress taxonomy term field names to the
 * domain-neutral property names used by the canonical {@see Category} model.
 */
final class CategoryTransformer
{
    /**
     * Create a canonical Category from a raw event payload array.
     *
     * The payload is expected to contain standard WordPress term fields:
     * - `term_id`      → sourceTermId
     * - `slug`         → slug
     * - `name`         → name
     * - `description`  → description
     *
     * @param array<string, mixed> $payload Raw event payload from WordPress.
     * @return Category
     */
    public static function fromEventPayload(array $payload): Category
    {
        return new Category([
            'sourceTermId' => (string) ($payload['term_id'] ?? ''),
            'slug'         => (string) ($payload['slug'] ?? ''),
            'name'         => (string) ($payload['name'] ?? ''),
            'description'  => (string) ($payload['description'] ?? ''),
        ]);
    }
}
