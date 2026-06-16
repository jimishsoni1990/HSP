<?php

namespace HSP\Modules\Commerce\CanonicalModels;

use HSP\Core\Contracts\CanonicalModelInterface;

class ProductVariation implements CanonicalModelInterface
{
    protected string $id;
    protected string $productId;
    protected string $parentProductId;
    protected string $sourceVariationId;
    protected ?string $regularPrice;
    protected ?string $salePrice;
    protected ?string $price;
    protected ?string $sku;
    protected bool $manageStock;
    protected ?int $stockQuantity;
    protected string $stockStatus;
    protected bool $backordersAllowed;
    protected string $imageUrl;
    protected array $attributes;
    protected string $description;
    protected bool $isEnabled;
    protected int $menuOrder;
    protected int $aggregateVersion;

    public function __construct(array $properties = [])
    {
        $this->id                = (string) ($properties['id'] ?? '');
        $this->productId         = (string) ($properties['productId'] ?? '');
        $this->parentProductId   = (string) ($properties['parentProductId'] ?? '');
        $this->sourceVariationId = (string) ($properties['sourceVariationId'] ?? '');
        $this->regularPrice      = isset($properties['regularPrice']) ? (string) $properties['regularPrice'] : null;
        $this->salePrice         = isset($properties['salePrice']) ? (string) $properties['salePrice'] : null;
        $this->price             = isset($properties['price']) ? (string) $properties['price'] : null;
        $this->sku               = isset($properties['sku']) ? (string) $properties['sku'] : null;
        $this->manageStock       = (bool) ($properties['manageStock'] ?? false);
        $this->stockQuantity     = isset($properties['stockQuantity']) ? (int) $properties['stockQuantity'] : null;
        $this->stockStatus       = (string) ($properties['stockStatus'] ?? 'instock');
        $this->backordersAllowed = (bool) ($properties['backordersAllowed'] ?? false);
        $this->imageUrl          = (string) ($properties['imageUrl'] ?? '');
        $this->attributes        = (array) ($properties['attributes'] ?? []);
        $this->description       = (string) ($properties['description'] ?? '');
        $this->isEnabled         = (bool) ($properties['isEnabled'] ?? true);
        $this->menuOrder         = (int) ($properties['menuOrder'] ?? 0);
        $this->aggregateVersion  = (int) ($properties['aggregateVersion'] ?? 1);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function getParentProductId(): string
    {
        return $this->parentProductId;
    }

    public function getSourceVariationId(): string
    {
        return $this->sourceVariationId;
    }

    public function getRegularPrice(): ?string
    {
        return $this->regularPrice;
    }

    public function getSalePrice(): ?string
    {
        return $this->salePrice;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function getManageStock(): bool
    {
        return $this->manageStock;
    }

    public function getStockQuantity(): ?int
    {
        return $this->stockQuantity;
    }

    public function getStockStatus(): string
    {
        return $this->stockStatus;
    }

    public function getBackordersAllowed(): bool
    {
        return $this->backordersAllowed;
    }

    public function getImageUrl(): string
    {
        return $this->imageUrl;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function getMenuOrder(): int
    {
        return $this->menuOrder;
    }

    public function getAggregateVersion(): int
    {
        return $this->aggregateVersion;
    }

    // ── CanonicalModelInterface ─────────────────────────────────────────

    public function toArray(): array
    {
        return [
            'id'                => $this->id,
            'productId'         => $this->productId,
            'parentProductId'   => $this->parentProductId,
            'sourceVariationId' => $this->sourceVariationId,
            'regularPrice'      => $this->regularPrice,
            'salePrice'         => $this->salePrice,
            'price'             => $this->price,
            'sku'               => $this->sku,
            'manageStock'       => $this->manageStock,
            'stockQuantity'     => $this->stockQuantity,
            'stockStatus'       => $this->stockStatus,
            'backordersAllowed' => $this->backordersAllowed,
            'imageUrl'          => $this->imageUrl,
            'attributes'        => $this->attributes,
            'description'       => $this->description,
            'isEnabled'         => $this->isEnabled,
            'menuOrder'         => $this->menuOrder,
            'aggregateVersion'  => $this->aggregateVersion,
        ];
    }

    public function getAggregateType(): string
    {
        return 'product_variation';
    }

    public function getAggregateId(): string
    {
        return $this->sourceVariationId;
    }
}
