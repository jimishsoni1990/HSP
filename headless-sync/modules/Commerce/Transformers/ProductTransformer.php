<?php

namespace HSP\Modules\Commerce\Transformers;

use HSP\Modules\Commerce\CanonicalModels\Product;

class ProductTransformer
{
    /**
     * Map a raw product event payload to a Product canonical model.
     *
     * @param array $payload
     * @param int $aggregateVersion
     * @return Product
     */
    public static function fromEventPayload(array $payload, int $aggregateVersion = 1): Product
    {
        $status = (string) ($payload['post_status'] ?? 'publish');
        $deletedAt = null;
        if ($status === 'trash' || $status === 'deleted') {
            $deletedAt = (string) ($payload['post_modified_gmt'] ?? date('Y-m-d H:i:s'));
        }

        // Map media
        $galleryImages = [];
        if (!empty($payload['gallery_images'])) {
            $galleryImages = ProductMediaTransformer::fromGallery($payload['gallery_images']);
        }

        // Map attributes
        $attributes = [];
        if (!empty($payload['attributes'])) {
            foreach ($payload['attributes'] as $attrData) {
                $attributes[] = ProductAttributeTransformer::fromArray($attrData);
            }
        }

        // Map featured image into gallery position 0 if it is not already featured
        $featuredUrl = (string) ($payload['featured_image_url'] ?? '');
        if ($featuredUrl !== '') {
            $hasFeatured = false;
            foreach ($galleryImages as $img) {
                if ($img['isFeatured']) {
                    $hasFeatured = true;
                    break;
                }
            }
            if (!$hasFeatured) {
                array_unshift($galleryImages, [
                    'sourceAttachmentId' => '',
                    'url'                => $featuredUrl,
                    'thumbnailUrl'       => $featuredUrl,
                    'mediumUrl'          => $featuredUrl,
                    'largeUrl'           => $featuredUrl,
                    'altText'            => (string) ($payload['post_title'] ?? ''),
                    'caption'            => '',
                    'position'           => 0,
                    'isFeatured'         => true,
                ]);
            }
        }

        return new Product([
            'sourcePostId'      => (string) ($payload['ID'] ?? ''),
            'productType'       => (string) ($payload['product_type'] ?? 'simple'),
            'slug'              => (string) ($payload['post_name'] ?? ''),
            'name'              => (string) ($payload['post_title'] ?? ''),
            'description'       => (string) ($payload['post_content'] ?? ''),
            'shortDescription'  => (string) ($payload['post_excerpt'] ?? ''),
            'status'            => $status,
            'regularPrice'      => isset($payload['regular_price']) && $payload['regular_price'] !== '' ? (string) $payload['regular_price'] : null,
            'salePrice'         => isset($payload['sale_price']) && $payload['sale_price'] !== '' ? (string) $payload['sale_price'] : null,
            'price'             => isset($payload['price']) && $payload['price'] !== '' ? (string) $payload['price'] : null,
            'priceMin'          => isset($payload['price_min']) ? (string) $payload['price_min'] : null,
            'priceMax'          => isset($payload['price_max']) ? (string) $payload['price_max'] : null,
            'sku'               => isset($payload['sku']) && $payload['sku'] !== '' ? (string) $payload['sku'] : null,
            'manageStock'       => (bool) ($payload['manage_stock'] ?? false),
            'stockQuantity'     => isset($payload['stock_quantity']) && $payload['stock_quantity'] !== '' ? (int) $payload['stock_quantity'] : null,
            'stockStatus'       => (string) ($payload['stock_status'] ?? 'instock'),
            'backordersAllowed' => (bool) ($payload['backorders_allowed'] ?? false),
            'externalUrl'       => (string) ($payload['external_url'] ?? ''),
            'buttonText'        => (string) ($payload['button_text'] ?? ''),
            'groupedProductIds' => (array) ($payload['grouped_product_ids'] ?? []),
            'categoryIds'       => (array) ($payload['category_ids'] ?? []),
            'tagIds'            => (array) ($payload['tag_ids'] ?? []),
            'featuredImageUrl'  => $featuredUrl,
            'weight'            => isset($payload['weight']) && $payload['weight'] !== '' ? (string) $payload['weight'] : null,
            'dimensions'        => (array) ($payload['dimensions'] ?? []),
            'seo'               => $payload['seo'] ?? null,
            'createdAt'         => $payload['post_date_gmt'] ?? null,
            'updatedAt'         => $payload['post_modified_gmt'] ?? null,
            'deletedAt'         => $deletedAt,
            'galleryImages'     => $galleryImages,
            'attributes'        => $attributes,
            'variationIds'      => (array) ($payload['variation_ids'] ?? []),
            'aggregateVersion'  => $aggregateVersion
        ]);
    }
}
