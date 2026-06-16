<?php

namespace HSP\Modules\Commerce\Transformers;

use HSP\Modules\Commerce\CanonicalModels\ProductAttribute;

class ProductAttributeTransformer
{
    /**
     * Map a raw attribute payload array to a ProductAttribute model.
     *
     * @param array $attrData
     * @return ProductAttribute
     */
    public static function fromArray(array $attrData): ProductAttribute
    {
        return new ProductAttribute([
            'key'             => (string) ($attrData['key'] ?? ''),
            'label'           => (string) ($attrData['label'] ?? ''),
            'type'            => (string) ($attrData['type'] ?? 'custom'),
            'values'          => (array) ($attrData['values'] ?? []),
            'isVisible'       => (bool) ($attrData['is_visible'] ?? true),
            'isForVariations' => (bool) ($attrData['is_for_variations'] ?? false),
            'position'        => (int) ($attrData['position'] ?? 0),
        ]);
    }
}
