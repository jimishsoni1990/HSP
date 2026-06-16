<?php

namespace HSP\Core\Events;

class WordpressProductEventListener
{
    /**
     * @var OutboxService
     */
    protected OutboxService $outbox;

    /**
     * WordpressProductEventListener constructor.
     *
     * @param OutboxService $outbox
     */
    public function __construct(OutboxService $outbox)
    {
        $this->outbox = $outbox;
    }

    /**
     * Register WooCommerce hooks.
     *
     * @return void
     */
    public function registerHooks(): void
    {
        if (!function_exists('add_action')) {
            return;
        }

        // Standard Product save / delete
        add_action('woocommerce_new_product', [$this, 'handleProductSave'], 10, 1);
        add_action('woocommerce_update_product', [$this, 'handleProductSave'], 10, 1);
        add_action('before_delete_post', [$this, 'handleProductDelete'], 10, 2);
        add_action('wp_trash_post', [$this, 'handleProductTrash'], 10, 1);
        add_action('untrashed_post', [$this, 'handleProductUntrash'], 10, 1);

        // Variation save / delete
        add_action('woocommerce_save_product_variation', [$this, 'handleVariationSave'], 10, 2);
        add_action('woocommerce_delete_product_variation', [$this, 'handleVariationDelete'], 10, 2);

        // Duplicate & stock changes
        add_action('woocommerce_product_duplicate', [$this, 'handleProductDuplicate'], 10, 2);
        add_action('woocommerce_update_product_stock', [$this, 'handleStockUpdate'], 10, 1);

        if (function_exists('add_filter')) {
            add_filter('hsp_sync_product_seo_data', [$this, 'defaultSeoMapping'], 10, 3);
        }
    }

    /**
     * Handle product save hooks.
     *
     * @param int $productId
     * @return void
     */
    public function handleProductSave(int $productId): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!function_exists('wc_get_product')) {
            return;
        }

        $product = wc_get_product($productId);
        if (!$product || $product->is_type('variation')) {
            return;
        }

        $status = $product->get_status();
        // Only sync published state or transitions to trash (handled by trash action)
        if ($status !== 'publish' && $status !== 'trash') {
            return;
        }

        $payload = $this->extractProductPayload($productId);
        if (empty($payload)) {
            return;
        }

        try {
            $this->outbox->publishProduct($payload, 'commerce.product.updated');
        } catch (\Throwable $e) {
            error_log('HSP Commerce Sync Error (Product Save): ' . $e->getMessage());
        }
    }

    /**
     * Handle product duplicate hook.
     *
     * @param mixed $duplicate
     * @param mixed $product
     * @return void
     */
    public function handleProductDuplicate($duplicate, $product): void
    {
        if (!$duplicate) {
            return;
        }

        $duplicateId = $duplicate->get_id();
        $payload = $this->extractProductPayload($duplicateId);
        if (empty($payload)) {
            return;
        }

        try {
            $this->outbox->publishProduct($payload, 'commerce.product.created');
        } catch (\Throwable $e) {
            error_log('HSP Commerce Sync Error (Product Duplicate): ' . $e->getMessage());
        }
    }

    /**
     * Handle product hard deletion hook.
     *
     * @param int $postId
     * @param mixed $post
     * @return void
     */
    public function handleProductDelete(int $postId, $post = null): void
    {
        if (!$post && function_exists('get_post')) {
            $post = get_post($postId);
        }

        if (!$post || $post->post_type !== 'product') {
            return;
        }

        $payload = [
            'ID' => $postId,
            'post_type' => 'product'
        ];

        try {
            $this->outbox->publishProduct($payload, 'commerce.product.deleted');
        } catch (\Throwable $e) {
            error_log('HSP Commerce Sync Error (Product Delete): ' . $e->getMessage());
        }
    }

    /**
     * Handle product soft-delete (trash) hook.
     *
     * @param int $postId
     * @return void
     */
    public function handleProductTrash(int $postId): void
    {
        if (function_exists('get_post_type') && get_post_type($postId) !== 'product') {
            return;
        }

        $payload = [
            'ID' => $postId,
            'post_type' => 'product',
            'post_status' => 'trash'
        ];

        try {
            $this->outbox->publishProduct($payload, 'commerce.product.deleted');
        } catch (\Throwable $e) {
            error_log('HSP Commerce Sync Error (Product Trash): ' . $e->getMessage());
        }
    }

    /**
     * Handle product untrash (restore) hook.
     *
     * @param int $postId
     * @return void
     */
    public function handleProductUntrash(int $postId): void
    {
        if (function_exists('get_post_type') && get_post_type($postId) !== 'product') {
            return;
        }

        $payload = $this->extractProductPayload($postId);
        if (empty($payload)) {
            return;
        }

        try {
            $this->outbox->publishProduct($payload, 'commerce.product.created');
        } catch (\Throwable $e) {
            error_log('HSP Commerce Sync Error (Product Untrash): ' . $e->getMessage());
        }
    }

    /**
     * Handle individual product variation save hook.
     *
     * @param int $variationId
     * @param int $i
     * @return void
     */
    public function handleVariationSave(int $variationId, int $i = 0): void
    {
        if (!function_exists('wc_get_product')) {
            return;
        }

        $payload = $this->extractVariationPayload($variationId);
        if (empty($payload)) {
            return;
        }

        try {
            $this->outbox->publishVariation($payload, 'commerce.product_variation.updated');
        } catch (\Throwable $e) {
            error_log('HSP Commerce Sync Error (Variation Save): ' . $e->getMessage());
        }
    }

    /**
     * Handle individual product variation deletion hook.
     *
     * @param int $variationId
     * @param int $parentId
     * @return void
     */
    public function handleVariationDelete(int $variationId, int $parentId = 0): void
    {
        $payload = [
            'variation_id' => (string) $variationId,
            'parent_product_id' => (string) $parentId
        ];

        try {
            $this->outbox->publishVariation($payload, 'commerce.product_variation.deleted');
        } catch (\Throwable $e) {
            error_log('HSP Commerce Sync Error (Variation Delete): ' . $e->getMessage());
        }
    }

    /**
     * Handle stock quantity update hook (triggered during order placement/stock sync).
     *
     * @param mixed $product
     * @return void
     */
    public function handleStockUpdate($product): void
    {
        if (!$product || !function_exists('wc_get_product')) {
            return;
        }

        if (is_numeric($product)) {
            $product = wc_get_product($product);
        }

        if (!$product) {
            return;
        }

        $payload = [
            'ID' => $product->get_id(),
            'post_type' => $product->is_type('variation') ? 'product_variation' : 'product',
            'stock_quantity' => $product->get_stock_quantity(),
            'stock_status' => $product->get_stock_status(),
            'manage_stock' => $product->get_manage_stock()
        ];

        try {
            if ($product->is_type('variation')) {
                // If variation stock updated, update variation entity
                $variationPayload = $this->extractVariationPayload($product->get_id());
                if (!empty($variationPayload)) {
                    $this->outbox->publishVariation($variationPayload, 'commerce.product_variation.updated');
                }
            } else {
                $this->outbox->publishProduct($payload, 'commerce.product.stock_updated');
            }
        } catch (\Throwable $e) {
            error_log('HSP Commerce Sync Error (Stock Update): ' . $e->getMessage());
        }
    }

    /**
     * Extract full product payload for outbox mapping.
     *
     * @param int $productId
     * @return array
     */
    public function extractProductPayload(int $productId): array
    {
        if (!function_exists('wc_get_product')) {
            return [];
        }

        $product = wc_get_product($productId);
        if (!$product) {
            return [];
        }

        $post = get_post($productId);
        if (!$post) {
            return [];
        }

        // Extract gallery images
        $galleryIds = $product->get_gallery_image_ids();
        $galleryImages = [];
        foreach ($galleryIds as $index => $attachmentId) {
            $galleryImages[] = [
                'attachment_id' => (string) $attachmentId,
                'url' => wp_get_attachment_image_url($attachmentId, 'full') ?: '',
                'thumbnail_url' => wp_get_attachment_image_url($attachmentId, 'thumbnail') ?: '',
                'medium_url' => wp_get_attachment_image_url($attachmentId, 'medium') ?: '',
                'large_url' => wp_get_attachment_image_url($attachmentId, 'large') ?: '',
                'alt_text' => get_post_meta($attachmentId, '_wp_attachment_image_alt', true) ?: '',
                'caption' => wp_get_attachment_caption($attachmentId) ?: '',
                'position' => $index + 1
            ];
        }

        // Extract product attributes
        $wcAttributes = $product->get_attributes();
        $attributes = [];
        foreach ($wcAttributes as $wcAttr) {
            $key = $wcAttr->get_name();
            $label = wc_attribute_label($key);
            $type = $wcAttr->is_taxonomy() ? 'taxonomy' : 'custom';
            
            $values = [];
            if ($wcAttr->is_taxonomy()) {
                $terms = $wcAttr->get_terms();
                if ($terms) {
                    foreach ($terms as $term) {
                        $values[] = $term->name;
                    }
                }
            } else {
                $values = $wcAttr->get_options();
            }

            $attributes[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'values' => $values,
                'is_visible' => (bool) $wcAttr->get_visible(),
                'is_for_variations' => (bool) $wcAttr->get_variation(),
                'position' => (int) $wcAttr->get_position()
            ];
        }

        $payload = [
            'ID' => $productId,
            'post_type' => 'product',
            'post_name' => $product->get_slug(),
            'post_title' => $product->get_name(),
            'post_content' => $product->get_description(),
            'post_excerpt' => $product->get_short_description(),
            'post_status' => $product->get_status(),
            'post_modified_gmt' => $post->post_modified_gmt,
            'product_type' => $product->get_type(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'price' => $product->get_price(),
            'sku' => $product->get_sku(),
            'manage_stock' => (bool) $product->get_manage_stock(),
            'stock_quantity' => $product->get_stock_quantity(),
            'stock_status' => $product->get_stock_status(),
            'backorders_allowed' => $product->get_backorders() !== 'no',
            'external_url' => $product->is_type('external') ? $product->get_product_url() : '',
            'button_text' => $product->is_type('external') ? $product->get_button_text() : '',
            'weight' => $product->get_weight(),
            'dimensions' => [
                'length' => $product->get_length(),
                'width' => $product->get_width(),
                'height' => $product->get_height(),
            ],
            'category_ids' => $product->get_category_ids(),
            'tag_ids' => $product->get_tag_ids(),
            'featured_image_url' => wp_get_attachment_image_url($product->get_image_id(), 'large') ?: '',
            'gallery_images' => $galleryImages,
            'attributes' => $attributes,
            'variation_ids' => $product->is_type('variable') ? $product->get_children() : [],
            'grouped_product_ids' => $product->is_type('grouped') ? $product->get_children() : [],
        ];

        // SEO extraction
        $seoData = [
            'meta_title' => '',
            'meta_description' => '',
            'og_title' => '',
            'og_description' => '',
            'og_image' => '',
        ];
        if (function_exists('apply_filters')) {
            $seoData = apply_filters('hsp_sync_product_seo_data', $seoData, $productId, $product);
        }
        $payload['seo'] = $seoData;

        return $payload;
    }

    /**
     * Extract product variation payload for outbox mapping.
     *
     * @param int $variationId
     * @return array
     */
    public function extractVariationPayload(int $variationId): array
    {
        if (!function_exists('wc_get_product')) {
            return [];
        }

        $variation = wc_get_product($variationId);
        if (!$variation || !$variation->is_type('variation')) {
            return [];
        }

        // Clean attributes key prefix
        $wcAttrs = $variation->get_attributes();
        $attributes = [];
        foreach ($wcAttrs as $key => $val) {
            $cleanedKey = str_replace('attribute_', '', $key);
            $attributes[$cleanedKey] = $val;
        }

        return [
            'variation_id' => (string) $variationId,
            'parent_product_id' => (string) $variation->get_parent_id(),
            'regular_price' => $variation->get_regular_price(),
            'sale_price' => $variation->get_sale_price(),
            'price' => $variation->get_price(),
            'sku' => $variation->get_sku(),
            'manage_stock' => (bool) $variation->get_manage_stock(),
            'stock_quantity' => $variation->get_stock_quantity(),
            'stock_status' => $variation->get_stock_status(),
            'backorders_allowed' => $variation->get_backorders() !== 'no',
            'image_url' => wp_get_attachment_image_url($variation->get_image_id(), 'large') ?: '',
            'attributes' => $attributes,
            'description' => $variation->get_description(),
            'is_enabled' => $variation->status === 'publish' || $variation->is_purchasable(),
            'menu_order' => (int) $variation->get_menu_order()
        ];
    }

    /**
     * Default callback to extract SEO data from Yoast SEO if active.
     *
     * @param array $seoData
     * @param int $productId
     * @param mixed $product
     * @return array
     */
    public function defaultSeoMapping(array $seoData, int $productId, $product): array
    {
        if (function_exists('get_post_meta')) {
            $seoData['meta_title']       = get_post_meta($productId, '_yoast_wpseo_title', true) ?: '';
            $seoData['meta_description'] = get_post_meta($productId, '_yoast_wpseo_metadesc', true) ?: '';
            $seoData['og_title']         = get_post_meta($productId, '_yoast_wpseo_opengraph-title', true) ?: '';
            $seoData['og_description']   = get_post_meta($productId, '_yoast_wpseo_opengraph-description', true) ?: '';
            $seoData['og_image']         = get_post_meta($productId, '_yoast_wpseo_opengraph-image', true) ?: '';
        }
        return $seoData;
    }
}
