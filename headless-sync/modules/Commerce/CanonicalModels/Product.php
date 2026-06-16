<?php

namespace HSP\Modules\Commerce\CanonicalModels;

use HSP\Core\Contracts\CanonicalModelInterface;

class Product implements CanonicalModelInterface
{
    protected string $id;
    protected string $sourcePostId;
    protected string $productType;
    protected string $slug;
    protected string $name;
    protected string $description;
    protected string $shortDescription;
    protected string $status;
    protected ?string $regularPrice;
    protected ?string $salePrice;
    protected ?string $price;
    protected ?string $priceMin;
    protected ?string $priceMax;
    protected ?string $sku;
    protected bool $manageStock;
    protected ?int $stockQuantity;
    protected string $stockStatus;
    protected bool $backordersAllowed;
    protected string $externalUrl;
    protected string $buttonText;
    protected array $groupedProductIds;
    protected array $categoryIds;
    protected array $tagIds;
    protected string $featuredImageUrl;
    protected ?string $weight;
    protected array $dimensions;
    protected ?array $seo;
    protected ?string $createdAt;
    protected ?string $updatedAt;
    protected ?string $deletedAt;
    protected array $galleryImages;
    protected array $attributes;
    protected array $variationIds;
    protected int $aggregateVersion;

    public function __construct(array $properties = [])
    {
        $this->id                = (string) ($properties['id'] ?? '');
        $this->sourcePostId      = (string) ($properties['sourcePostId'] ?? '');
        $this->productType       = (string) ($properties['productType'] ?? 'simple');
        $this->slug              = (string) ($properties['slug'] ?? '');
        $this->name              = (string) ($properties['name'] ?? '');
        $this->description       = (string) ($properties['description'] ?? '');
        $this->shortDescription  = (string) ($properties['shortDescription'] ?? '');
        $this->status            = (string) ($properties['status'] ?? 'publish');
        $this->regularPrice      = isset($properties['regularPrice']) ? (string) $properties['regularPrice'] : null;
        $this->salePrice         = isset($properties['salePrice']) ? (string) $properties['salePrice'] : null;
        $this->price             = isset($properties['price']) ? (string) $properties['price'] : null;
        $this->priceMin          = isset($properties['priceMin']) ? (string) $properties['priceMin'] : null;
        $this->priceMax          = isset($properties['priceMax']) ? (string) $properties['priceMax'] : null;
        $this->sku               = isset($properties['sku']) ? (string) $properties['sku'] : null;
        $this->manageStock       = (bool) ($properties['manageStock'] ?? false);
        $this->stockQuantity     = isset($properties['stockQuantity']) ? (int) $properties['stockQuantity'] : null;
        $this->stockStatus       = (string) ($properties['stockStatus'] ?? 'instock');
        $this->backordersAllowed = (bool) ($properties['backordersAllowed'] ?? false);
        $this->externalUrl       = (string) ($properties['externalUrl'] ?? '');
        $this->buttonText        = (string) ($properties['buttonText'] ?? '');
        $this->groupedProductIds = (array) ($properties['groupedProductIds'] ?? []);
        $this->categoryIds       = (array) ($properties['categoryIds'] ?? []);
        $this->tagIds            = (array) ($properties['tagIds'] ?? []);
        $this->featuredImageUrl  = (string) ($properties['featuredImageUrl'] ?? '');
        $this->weight            = isset($properties['weight']) ? (string) $properties['weight'] : null;
        $this->dimensions        = (array) ($properties['dimensions'] ?? []);
        $this->seo               = $properties['seo'] ?? null;
        $this->createdAt         = $properties['createdAt'] ?? null;
        $this->updatedAt         = $properties['updatedAt'] ?? null;
        $this->deletedAt         = $properties['deletedAt'] ?? null;
        $this->galleryImages     = (array) ($properties['galleryImages'] ?? []);
        $this->attributes        = (array) ($properties['attributes'] ?? []);
        $this->variationIds      = (array) ($properties['variationIds'] ?? []);
        $this->aggregateVersion  = (int) ($properties['aggregateVersion'] ?? 1);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSourcePostId(): string
    {
        return $this->sourcePostId;
    }

    public function getProductType(): string
    {
        return $this->productType;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getShortDescription(): string
    {
        return $this->shortDescription;
    }

    public function getStatus(): string
    {
        return $this->status;
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

    public function getPriceMin(): ?string
    {
        return $this->priceMin;
    }

    public function getPriceMax(): ?string
    {
        return $this->priceMax;
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

    public function getExternalUrl(): string
    {
        return $this->externalUrl;
    }

    public function getButtonText(): string
    {
        return $this->buttonText;
    }

    public function getGroupedProductIds(): array
    {
        return $this->groupedProductIds;
    }

    public function getCategoryIds(): array
    {
        return $this->categoryIds;
    }

    public function getTagIds(): array
    {
        return $this->tagIds;
    }

    public function getFeaturedImageUrl(): string
    {
        return $this->featuredImageUrl;
    }

    public function getWeight(): ?string
    {
        return $this->weight;
    }

    public function getDimensions(): array
    {
        return $this->dimensions;
    }

    public function getSeo(): ?array
    {
        return $this->seo;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?string
    {
        return $this->deletedAt;
    }

    public function getGalleryImages(): array
    {
        return $this->galleryImages;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getVariationIds(): array
    {
        return $this->variationIds;
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
            'sourcePostId'      => $this->sourcePostId,
            'productType'       => $this->productType,
            'slug'              => $this->slug,
            'name'              => $this->name,
            'description'       => $this->description,
            'shortDescription'  => $this->shortDescription,
            'status'            => $this->status,
            'regularPrice'      => $this->regularPrice,
            'salePrice'         => $this->salePrice,
            'price'             => $this->price,
            'priceMin'          => $this->priceMin,
            'priceMax'          => $this->priceMax,
            'sku'               => $this->sku,
            'manageStock'       => $this->manageStock,
            'stockQuantity'     => $this->stockQuantity,
            'stockStatus'       => $this->stockStatus,
            'backordersAllowed' => $this->backordersAllowed,
            'externalUrl'       => $this->externalUrl,
            'buttonText'        => $this->buttonText,
            'groupedProductIds' => $this->groupedProductIds,
            'categoryIds'       => $this->categoryIds,
            'tagIds'            => $this->tagIds,
            'featuredImageUrl'  => $this->featuredImageUrl,
            'weight'            => $this->weight,
            'dimensions'        => $this->dimensions,
            'seo'               => $this->seo,
            'createdAt'         => $this->createdAt,
            'updatedAt'         => $this->updatedAt,
            'deletedAt'         => $this->deletedAt,
            'galleryImages'     => $this->galleryImages,
            'variationIds'      => $this->variationIds,
            'aggregateVersion'  => $this->aggregateVersion,
        ];
    }

    public function getAggregateType(): string
    {
        return 'product';
    }

    public function getAggregateId(): string
    {
        return $this->sourcePostId;
    }
}
