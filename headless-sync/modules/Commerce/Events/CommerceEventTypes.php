<?php

namespace HSP\Modules\Commerce\Events;

class CommerceEventTypes
{
    public const PRODUCT_CREATED = 'commerce.product.created';
    public const PRODUCT_UPDATED = 'commerce.product.updated';
    public const PRODUCT_DELETED = 'commerce.product.deleted';
    public const STOCK_UPDATED   = 'commerce.product.stock_updated';
    
    public const VARIATION_CREATED = 'commerce.product_variation.created';
    public const VARIATION_UPDATED = 'commerce.product_variation.updated';
    public const VARIATION_DELETED = 'commerce.product_variation.deleted';
}
