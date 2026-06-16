<?php

namespace HSP\Core\Events;

use DateTime;
use DateTimeZone;

class EventBuilder
{
    /**
     * Generate a cryptographically secure UUIDv7 string.
     *
     * @param int|null $timeMs Millisecond timestamp
     * @return string
     */
    public static function generateUuidV7(?int $timeMs = null): string
    {
        if ($timeMs === null) {
            $timeMs = (int) floor(microtime(true) * 1000);
        }

        // Convert millisecond timestamp to a 48-bit hex string (12 hex chars)
        $timestampHex = str_pad(dechex($timeMs), 12, '0', STR_PAD_LEFT);

        // Generate 10 random bytes (20 hex chars)
        $randomBytes = random_bytes(10);
        $randomHex = bin2hex($randomBytes);

        // Format according to UUID v7 specification:
        // xxxxxxxx-xxxx-7xxx-yxxx-xxxxxxxxxxxx
        // M (version) = 7
        // N (variant y) = 8, 9, a, or b (binary 10xx)
        $timeLow = substr($timestampHex, 0, 8);
        $timeMid = substr($timestampHex, 8, 4);
        
        $verAndRand = '7' . substr($randomHex, 0, 3);
        
        $varByteInt = hexdec(substr($randomHex, 3, 2));
        $varByteInt = ($varByteInt & 0x3f) | 0x80; // set variant bits to 10
        $varAndRand2 = str_pad(dechex($varByteInt), 2, '0', STR_PAD_LEFT) . substr($randomHex, 5, 14);

        return sprintf('%s-%s-%s-%s-%s',
            $timeLow,
            $timeMid,
            $verAndRand,
            substr($varAndRand2, 0, 4),
            substr($varAndRand2, 4, 12)
        );
    }

    /**
     * Build an EventEnvelope from WordPress Post data.
     *
     * @param array $postData
     * @param string $eventType
     * @param int $aggregateVersion
     * @return EventEnvelope
     */
    public function buildFromPost(array $postData, string $eventType, int $aggregateVersion): EventEnvelope
    {
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $nowIso = $now->format('Y-m-d\TH:i:s\Z');

        $sourceUpdatedAt = $nowIso;
        if (!empty($postData['post_modified_gmt']) && $postData['post_modified_gmt'] !== '0000-00-00 00:00:00') {
            $sourceUpdatedAt = (new DateTime($postData['post_modified_gmt'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        }

        $aggregateType = $postData['post_type'] ?? 'post';
        // Map page to post if necessary, but follow original WP types
        if ($aggregateType !== 'page') {
            $aggregateType = 'post';
        }

        return new EventEnvelope(
            self::generateUuidV7(),
            $eventType,
            1, // event_version
            $aggregateType,
            (string) ($postData['ID'] ?? ''),
            $aggregateVersion,
            $sourceUpdatedAt,
            $nowIso,
            $postData
        );
    }

    /**
     * Build an EventEnvelope from WordPress Term data.
     *
     * @param array $termData
     * @param string $eventType
     * @param int $aggregateVersion
     * @param string $taxonomy
     * @return EventEnvelope
     */
    public function buildFromTerm(array $termData, string $eventType, int $aggregateVersion, string $taxonomy = 'category'): EventEnvelope
    {
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $nowIso = $now->format('Y-m-d\TH:i:s\Z');

        return new EventEnvelope(
            self::generateUuidV7(),
            $eventType,
            1, // event_version
            $taxonomy,
            (string) ($termData['term_id'] ?? ''),
            $aggregateVersion,
            $nowIso,
            $nowIso,
            $termData
        );
    }

    /**
     * Build an EventEnvelope from WooCommerce Product data.
     *
     * @param array $productData
     * @param string $eventType
     * @param int $aggregateVersion
     * @return EventEnvelope
     */
    public function buildFromProduct(array $productData, string $eventType, int $aggregateVersion): EventEnvelope
    {
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $nowIso = $now->format('Y-m-d\TH:i:s\Z');

        $sourceUpdatedAt = $nowIso;
        if (!empty($productData['post_modified_gmt']) && $productData['post_modified_gmt'] !== '0000-00-00 00:00:00') {
            $sourceUpdatedAt = (new DateTime($productData['post_modified_gmt'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        }

        return new EventEnvelope(
            self::generateUuidV7(),
            $eventType,
            1, // event_version
            'product', // aggregateType
            (string) ($productData['ID'] ?? ''),
            $aggregateVersion,
            $sourceUpdatedAt,
            $nowIso,
            $productData
        );
    }

    /**
     * Build an EventEnvelope from WooCommerce Product Variation data.
     *
     * @param array $variationData
     * @param string $eventType
     * @param int $aggregateVersion
     * @return EventEnvelope
     */
    public function buildFromVariation(array $variationData, string $eventType, int $aggregateVersion): EventEnvelope
    {
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $nowIso = $now->format('Y-m-d\TH:i:s\Z');

        // Variations are updated at parent lifecycle or separately; default to now
        $sourceUpdatedAt = $nowIso;

        return new EventEnvelope(
            self::generateUuidV7(),
            $eventType,
            1, // event_version
            'product_variation', // aggregateType
            (string) ($variationData['variation_id'] ?? ''),
            $aggregateVersion,
            $sourceUpdatedAt,
            $nowIso,
            $variationData
        );
    }
}
