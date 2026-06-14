<?php

namespace HSP\Modules\Content\Transformers;

use HSP\Modules\Content\CanonicalModels\Post;

/**
 * Transforms raw WordPress event payloads into canonical Post models.
 *
 * This transformer maps the WordPress-specific field names (e.g. `post_title`,
 * `post_name`) to the domain-neutral property names used by the canonical
 * {@see Post} model, acting as the anti-corruption layer between the event
 * bus and the projection/query side.
 */
final class PostTransformer
{
    /**
     * Create a canonical Post from a raw event payload array.
     *
     * The payload is expected to contain standard WordPress post fields:
     * - `ID`            → sourcePostId
     * - `post_name`     → slug
     * - `post_title`    → title
     * - `post_excerpt`  → excerpt
     * - `post_content`  → content
     * - `post_status`   → status
     * - `categories`    → categories (array of term IDs)
     *
     * @param array<string, mixed> $payload Raw event payload from WordPress.
     * @return Post
     */
    public static function fromEventPayload(array $payload): Post
    {
        $status   = (string) ($payload['post_status'] ?? 'publish');
        $deletedAt = ($status === 'trash') ? date('Y-m-d H:i:s') : null;

        return new Post([
            'sourcePostId' => (string) ($payload['ID'] ?? ''),
            'slug'         => (string) ($payload['post_name'] ?? ''),
            'title'        => (string) ($payload['post_title'] ?? ''),
            'excerpt'      => (string) ($payload['post_excerpt'] ?? ''),
            'content'      => (string) ($payload['post_content'] ?? ''),
            'status'       => $status,
            'deletedAt'    => $deletedAt,
            'categories'   => (array) ($payload['categories'] ?? []),
        ]);
    }
}
