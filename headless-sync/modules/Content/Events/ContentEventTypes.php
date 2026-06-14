<?php

namespace HSP\Modules\Content\Events;

/**
 * Defines all event type constants for the Content module.
 *
 * These string identifiers are used as event type keys in the outbox queue,
 * worker subscriptions, and event envelope construction. Centralising them
 * here eliminates magic strings and enables IDE-assisted refactoring.
 */
final class ContentEventTypes
{
    // ── Post events ─────────────────────────────────────────────────────

    /** @var string Fired when a new WordPress post is created. */
    public const POST_CREATED = 'content.post.created';

    /** @var string Fired when an existing WordPress post is updated. */
    public const POST_UPDATED = 'content.post.updated';

    /** @var string Fired when a WordPress post is permanently deleted. */
    public const POST_DELETED = 'content.post.deleted';

    // ── Page events ─────────────────────────────────────────────────────

    /** @var string Fired when a new WordPress page is created. */
    public const PAGE_CREATED = 'content.page.created';

    /** @var string Fired when an existing WordPress page is updated. */
    public const PAGE_UPDATED = 'content.page.updated';

    /** @var string Fired when a WordPress page is permanently deleted. */
    public const PAGE_DELETED = 'content.page.deleted';

    // ── Category events ─────────────────────────────────────────────────

    /** @var string Fired when a new WordPress category term is created. */
    public const CATEGORY_CREATED = 'content.category.created';

    /** @var string Fired when an existing WordPress category term is updated. */
    public const CATEGORY_UPDATED = 'content.category.updated';

    /** @var string Fired when a WordPress category term is deleted. */
    public const CATEGORY_DELETED = 'content.category.deleted';

    /**
     * Return all defined event type constants as a flat array.
     *
     * Useful for bulk subscription or validation.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::POST_CREATED,
            self::POST_UPDATED,
            self::POST_DELETED,
            self::PAGE_CREATED,
            self::PAGE_UPDATED,
            self::PAGE_DELETED,
            self::CATEGORY_CREATED,
            self::CATEGORY_UPDATED,
            self::CATEGORY_DELETED,
        ];
    }
}
