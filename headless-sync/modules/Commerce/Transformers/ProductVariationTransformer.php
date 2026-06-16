<?php

namespace HSP\Modules\Commerce\Transformers;

use HSP\Modules\Commerce\CanonicalModels\ProductVariation;

class ProductVariationTransformer
{
    /**
     * Map a raw variation payload array to a ProductVariation canonical model.
     *
     * @param array $payload
     * @param int $aggregateVersion
     * @return ProductVariation
     */
    public static function fromEventPayload(array $payload, int $aggregateVersion = 1): ProductVariation
    {
        return new ProductVariation([
            'sourceVariationId' => (string) ($payload['variation_id'] ?? ''),
            'productId'         => '', // Will be mapped to PostgreSQL product UUID inside the Adapter
            'parentProductId'   => (string) ($payload['parent_product_id'] ?? ''),
            'regularPrice'      => isset($payload['regular_price']) && $payload['regular_price'] !== '' ? (string) $payload['regular_price'] : null,
            'salePrice'         => isset($payload['sale_price']) && $payload['sale_price'] !== '' ? (string) $payload['sale_price'] : null,
            'price'             => isset($payload['price']) && $payload['price'] !== '' ? (string) $payload['price'] : null,
            'sku'               => isset($payload['sku']) && $payload['sku'] !== '' ? (string) $payload['sku'] : null,
            'manageStock'       => (bool) ($payload['manage_stock'] ?? false),
            'stockQuantity'     => isset($payload['stock_quantity']) && $payload['stock_quantity'] !== '' ? (int) $payload['stock_quantity'] : null,
            'stockStatus'       => (string) ($payload['stock_status'] ?? 'instock'),
            'backordersAllowed' => (bool) ($payload['backorders_allowed'] ?? false),
            'imageUrl'          => (string) ($payload['image_url'] ?? ''),
            'attributes'        => (array) ($payload['attributes'] ?? []),
            'description'       => (string) ($payload['description'] ?? ''),
            'isEnabled'         => (bool) ($payload['is_enabled'] ?? true),
            'menuOrder'         => (int) ($payload['menu_order'] ?? 0),
            'aggregateVersion'  => $aggregateVersion
        ]);
    }
}
