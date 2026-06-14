<?php

namespace HSP\Modules\Content\Transformers;

use HSP\Modules\Content\CanonicalModels\Page;

/**
 * Transforms raw WordPress event payloads into canonical Page models.
 *
 * This transformer maps the WordPress-specific field names to the
 * domain-neutral property names used by the canonical {@see Page} model.
 */
final class PageTransformer
{
    /**
     * Create a canonical Page from a raw event payload array.
     *
     * The payload is expected to contain standard WordPress post fields:
     * - `ID`           → sourcePostId
     * - `post_name`    → slug
     * - `post_title`   → title
     * - `post_status`  → status
     *
     * @param array<string, mixed> $payload Raw event payload from WordPress.
     * @return Page
     */
    public static function fromEventPayload(array $payload): Page
    {
        $status    = (string) ($payload['post_status'] ?? 'publish');
        $deletedAt = ($status === 'trash') ? date('Y-m-d H:i:s') : null;

        return new Page([
            'sourcePostId' => (string) ($payload['ID'] ?? ''),
            'slug'         => (string) ($payload['post_name'] ?? ''),
            'title'        => (string) ($payload['post_title'] ?? ''),
            'status'       => $status,
            'deletedAt'    => $deletedAt,
        ]);
    }
}
