<?php

namespace HSP\Modules\Commerce\Adapters;

use HSP\Core\Contracts\AdapterInterface;
use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Core\Events\EventBuilder;
use HSP\Modules\Commerce\CanonicalModels\Product;
use HSP\Modules\Commerce\CanonicalModels\ProductVariation;
use PDO;
use RuntimeException;
use Throwable;

class ProductPostgresAdapter implements AdapterInterface
{
    /**
     * @var PDO
     */
    protected PDO $pdo;

    /**
     * ProductPostgresAdapter constructor.
     *
     * @param PDO $pdo
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Persist a canonical model to PostgreSQL.
     *
     * @param CanonicalModelInterface $model
     * @return void
     * @throws Throwable
     */
    public function persist(CanonicalModelInterface $model): void
    {
        if ($model instanceof Product) {
            $this->persistProduct($model);
        } elseif ($model instanceof ProductVariation) {
            $this->persistVariation($model);
        } else {
            throw new RuntimeException('Unsupported model type for ProductPostgresAdapter: ' . get_class($model));
        }
    }

    /**
     * Delete an aggregate from the target store.
     *
     * @param string $aggregateType
     * @param string $aggregateId
     * @return void
     */
    public function delete(string $aggregateType, string $aggregateId): void
    {
        if ($aggregateType === 'product') {
            $sql = "UPDATE content.products SET deleted_at = NOW(), status = 'trash' WHERE source_post_id = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $aggregateId, PDO::PARAM_STR);
            $stmt->execute();
        } elseif ($aggregateType === 'product_variation') {
            // Hard delete variation
            $sql = "DELETE FROM content.product_variations WHERE source_variation_id = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $aggregateId, PDO::PARAM_STR);
            $stmt->execute();
        }
    }

    /**
     * Persist product canonical model.
     *
     * @param Product $product
     * @return void
     * @throws Throwable
     */
    protected function persistProduct(Product $product): void
    {
        $sourcePostId = $product->getSourcePostId();
        $version = $product->getAggregateVersion();

        // 1. Version fencing check
        $stmt = $this->pdo->prepare("SELECT id, aggregate_version FROM content.products WHERE source_post_id = :id");
        $stmt->execute([':id' => $sourcePostId]);
        $existing = $stmt->fetch();

        if ($existing && $version <= (int) $existing['aggregate_version']) {
            return; // Out of order replay or identical event, skip
        }

        $uuid = $existing ? $existing['id'] : EventBuilder::generateUuidV7();

        try {
            $this->pdo->beginTransaction();

            // 2. Upsert parent product row
            if (!$existing) {
                $sql = "INSERT INTO content.products (
                            id, source_post_id, source_entity_type, product_type, slug, name, description, 
                            short_description, status, regular_price, sale_price, price, price_min, price_max, 
                            sku, manage_stock, stock_quantity, stock_status, backorders_allowed, external_url, 
                            button_text, grouped_product_ids, category_ids, tag_ids, featured_image_url, 
                            weight, dimensions, seo, aggregate_version, created_at, updated_at
                        ) VALUES (
                            :id, :source_post_id, 'product', :product_type, :slug, :name, :description, 
                            :short_description, :status, :regular_price, :sale_price, :price, :price_min, :price_max, 
                            :sku, :manage_stock, :stock_quantity, :stock_status, :backorders_allowed, :external_url, 
                            :button_text, :grouped_product_ids, :category_ids, :tag_ids, :featured_image_url, 
                            :weight, :dimensions, :seo, :version, NOW(), NOW()
                        )";
            } else {
                $sql = "UPDATE content.products SET 
                            product_type = :product_type, slug = :slug, name = :name, description = :description, 
                            short_description = :short_description, status = :status, regular_price = :regular_price, 
                            sale_price = :sale_price, price = :price, sku = :sku, manage_stock = :manage_stock, 
                            stock_quantity = :stock_quantity, stock_status = :stock_status, 
                            backorders_allowed = :backorders_allowed, external_url = :external_url, 
                            button_text = :button_text, grouped_product_ids = :grouped_product_ids, 
                            category_ids = :category_ids, tag_ids = :tag_ids, featured_image_url = :featured_image_url, 
                            weight = :weight, dimensions = :dimensions, seo = :seo, aggregate_version = :version, 
                            updated_at = NOW(), deleted_at = NULL
                        WHERE id = :id";
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $uuid, PDO::PARAM_STR);
            if (!$existing) {
                $stmt->bindValue(':source_post_id', $sourcePostId, PDO::PARAM_STR);
                $stmt->bindValue(':price_min', $product->getPriceMin() ?: $product->getPrice(), PDO::PARAM_STR);
                $stmt->bindValue(':price_max', $product->getPriceMax() ?: $product->getPrice(), PDO::PARAM_STR);
            }
            $stmt->bindValue(':product_type', $product->getProductType(), PDO::PARAM_STR);
            $stmt->bindValue(':slug', $product->getSlug(), PDO::PARAM_STR);
            $stmt->bindValue(':name', $product->getName(), PDO::PARAM_STR);
            $stmt->bindValue(':description', $product->getDescription(), PDO::PARAM_STR);
            $stmt->bindValue(':short_description', $product->getShortDescription(), PDO::PARAM_STR);
            $stmt->bindValue(':status', $product->getStatus(), PDO::PARAM_STR);
            $stmt->bindValue(':regular_price', $product->getRegularPrice(), PDO::PARAM_STR);
            $stmt->bindValue(':sale_price', $product->getSalePrice(), PDO::PARAM_STR);
            $stmt->bindValue(':price', $product->getPrice(), PDO::PARAM_STR);
            $stmt->bindValue(':sku', $product->getSku(), PDO::PARAM_STR);
            $stmt->bindValue(':manage_stock', $product->getManageStock(), PDO::PARAM_BOOL);
            $stmt->bindValue(':stock_quantity', $product->getStockQuantity(), PDO::PARAM_INT);
            $stmt->bindValue(':stock_status', $product->getStockStatus(), PDO::PARAM_STR);
            $stmt->bindValue(':backorders_allowed', $product->getBackordersAllowed(), PDO::PARAM_BOOL);
            $stmt->bindValue(':external_url', $product->getExternalUrl(), PDO::PARAM_STR);
            $stmt->bindValue(':button_text', $product->getButtonText(), PDO::PARAM_STR);
            $stmt->bindValue(':grouped_product_ids', json_encode($product->getGroupedProductIds()), PDO::PARAM_STR);
            $stmt->bindValue(':category_ids', json_encode($product->getCategoryIds()), PDO::PARAM_STR);
            $stmt->bindValue(':tag_ids', json_encode($product->getTagIds()), PDO::PARAM_STR);
            $stmt->bindValue(':featured_image_url', $product->getFeaturedImageUrl(), PDO::PARAM_STR);
            $stmt->bindValue(':weight', $product->getWeight(), PDO::PARAM_STR);
            $stmt->bindValue(':dimensions', json_encode($product->getDimensions()), PDO::PARAM_STR);
            $stmt->bindValue(':seo', $product->getSeo() ? json_encode($product->getSeo()) : null, PDO::PARAM_STR);
            $stmt->bindValue(':version', $version, PDO::PARAM_INT);
            $stmt->execute();

            // 3. Sync Attributes (Delete & Insert)
            $delAttrSql = "DELETE FROM content.product_attributes WHERE product_id = :product_id";
            $delStmt = $this->pdo->prepare($delAttrSql);
            $delStmt->execute([':product_id' => $uuid]);

            $insAttrSql = "INSERT INTO content.product_attributes (
                                id, product_id, attribute_key, attribute_label, attribute_type, values, is_visible, is_for_variations, position
                           ) VALUES (
                                :id, :product_id, :key, :label, :type, :values, :is_visible, :is_for_variations, :position
                           )";
            $insAttrStmt = $this->pdo->prepare($insAttrSql);
            foreach ($product->getAttributes() as $attr) {
                $insAttrStmt->execute([
                    ':id' => EventBuilder::generateUuidV7(),
                    ':product_id' => $uuid,
                    ':key' => $attr->getKey(),
                    ':label' => $attr->getLabel(),
                    ':type' => $attr->getType(),
                    ':values' => json_encode($attr->getValues()),
                    ':is_visible' => $attr->isVisible() ? 1 : 0,
                    ':is_for_variations' => $attr->isForVariations() ? 1 : 0,
                    ':position' => $attr->getPosition()
                ]);
            }

            // 4. Sync Media (Delete & Insert)
            $delMediaSql = "DELETE FROM content.product_media WHERE product_id = :product_id";
            $delMediaStmt = $this->pdo->prepare($delMediaSql);
            $delMediaStmt->execute([':product_id' => $uuid]);

            $insMediaSql = "INSERT INTO content.product_media (
                                id, product_id, source_attachment_id, url, thumbnail_url, medium_url, large_url, alt_text, caption, position, is_featured
                            ) VALUES (
                                :id, :product_id, :source_attachment_id, :url, :thumbnail_url, :medium_url, :large_url, :alt_text, :caption, :position, :is_featured
                            )";
            $insMediaStmt = $this->pdo->prepare($insMediaSql);
            foreach ($product->getGalleryImages() as $img) {
                $insMediaStmt->execute([
                    ':id' => EventBuilder::generateUuidV7(),
                    ':product_id' => $uuid,
                    ':source_attachment_id' => $img['sourceAttachmentId'],
                    ':url' => $img['url'],
                    ':thumbnail_url' => $img['thumbnailUrl'],
                    ':medium_url' => $img['mediumUrl'],
                    ':large_url' => $img['largeUrl'],
                    ':alt_text' => $img['altText'],
                    ':caption' => $img['caption'],
                    ':position' => $img['position'],
                    ':is_featured' => $img['isFeatured'] ? 1 : 0
                ]);
            }

            // 5. Sync Categories (Delete & Insert)
            $delCatSql = "DELETE FROM content.product_categories WHERE product_id = :product_id";
            $delCatStmt = $this->pdo->prepare($delCatSql);
            $delCatStmt->execute([':product_id' => $uuid]);

            $insCatSql = "INSERT INTO content.product_categories (product_id, taxonomy_id)
                          SELECT :product_id, id FROM content.taxonomies WHERE source_term_id = :cat_id AND deleted_at IS NULL";
            $insCatStmt = $this->pdo->prepare($insCatSql);
            foreach ($product->getCategoryIds() as $catId) {
                $insCatStmt->execute([
                    ':product_id' => $uuid,
                    ':cat_id' => (string) $catId
                ]);
            }

            // 6. Recalculate price ranges if it is variable
            if ($product->getProductType() === 'variable') {
                $this->recalculatePriceRanges($uuid);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Persist variation canonical model.
     *
     * @param ProductVariation $variation
     * @return void
     * @throws Throwable
     */
    protected function persistVariation(ProductVariation $variation): void
    {
        $sourceVariationId = $variation->getSourceVariationId();
        $parentPostId = $variation->getParentProductId();
        $version = $variation->getAggregateVersion();

        // 1. Resolve parent product UUID
        $parentStmt = $this->pdo->prepare("SELECT id FROM content.products WHERE source_post_id = :parent_id");
        $parentStmt->execute([':parent_id' => $parentPostId]);
        $parentUuid = $parentStmt->fetchColumn();

        if (!$parentUuid) {
            // Parent product doesn't exist yet, we will skip/reject to maintain FK integrity
            return;
        }

        // 2. Version fencing check
        $stmt = $this->pdo->prepare("SELECT id, aggregate_version FROM content.product_variations WHERE source_variation_id = :id");
        $stmt->execute([':id' => $sourceVariationId]);
        $existing = $stmt->fetch();

        if ($existing && $version <= (int) $existing['aggregate_version']) {
            return; // Out of order replay, skip
        }

        $uuid = $existing ? $existing['id'] : EventBuilder::generateUuidV7();

        try {
            $this->pdo->beginTransaction();

            if (!$existing) {
                $sql = "INSERT INTO content.product_variations (
                            id, product_id, source_variation_id, regular_price, sale_price, price, sku, 
                            manage_stock, stock_quantity, stock_status, backorders_allowed, image_url, 
                            attributes, description, is_enabled, menu_order, aggregate_version, created_at, updated_at
                        ) VALUES (
                            :id, :product_id, :source_variation_id, :regular_price, :sale_price, :price, :sku, 
                            :manage_stock, :stock_quantity, :stock_status, :backorders_allowed, :image_url, 
                            :attributes, :description, :is_enabled, :menu_order, :version, NOW(), NOW()
                        )";
            } else {
                $sql = "UPDATE content.product_variations SET 
                            product_id = :product_id, regular_price = :regular_price, sale_price = :sale_price, 
                            price = :price, sku = :sku, manage_stock = :manage_stock, stock_quantity = :stock_quantity, 
                            stock_status = :stock_status, backorders_allowed = :backorders_allowed, image_url = :image_url, 
                            attributes = :attributes, description = :description, is_enabled = :is_enabled, 
                            menu_order = :menu_order, aggregate_version = :version, updated_at = NOW()
                        WHERE id = :id";
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $uuid, PDO::PARAM_STR);
            $stmt->bindValue(':product_id', $parentUuid, PDO::PARAM_STR);
            $stmt->bindValue(':source_variation_id', $sourceVariationId, PDO::PARAM_STR);
            $stmt->bindValue(':regular_price', $variation->getRegularPrice(), PDO::PARAM_STR);
            $stmt->bindValue(':sale_price', $variation->getSalePrice(), PDO::PARAM_STR);
            $stmt->bindValue(':price', $variation->getPrice(), PDO::PARAM_STR);
            $stmt->bindValue(':sku', $variation->getSku(), PDO::PARAM_STR);
            $stmt->bindValue(':manage_stock', $variation->getManageStock(), PDO::PARAM_BOOL);
            $stmt->bindValue(':stock_quantity', $variation->getStockQuantity(), PDO::PARAM_INT);
            $stmt->bindValue(':stock_status', $variation->getStockStatus(), PDO::PARAM_STR);
            $stmt->bindValue(':backorders_allowed', $variation->getBackordersAllowed(), PDO::PARAM_BOOL);
            $stmt->bindValue(':image_url', $variation->getImageUrl(), PDO::PARAM_STR);
            $stmt->bindValue(':attributes', json_encode($variation->getAttributes()), PDO::PARAM_STR);
            $stmt->bindValue(':description', $variation->getDescription(), PDO::PARAM_STR);
            $stmt->bindValue(':is_enabled', $variation->isEnabled(), PDO::PARAM_BOOL);
            $stmt->bindValue(':menu_order', $variation->getMenuOrder(), PDO::PARAM_INT);
            $stmt->bindValue(':version', $version, PDO::PARAM_INT);
            $stmt->execute();

            // 3. Recalculate price ranges on parent product
            $this->recalculatePriceRanges($parentUuid);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Recalculate parent product price ranges based on variations.
     *
     * @param string $productUuid
     * @return void
     */
    protected function recalculatePriceRanges(string $productUuid): void
    {
        $sql = "UPDATE content.products
                SET price_min = (
                      SELECT MIN(price) FROM content.product_variations
                      WHERE product_id = :product_uuid AND is_enabled = TRUE
                    ),
                    price_max = (
                      SELECT MAX(price) FROM content.product_variations
                      WHERE product_id = :product_uuid AND is_enabled = TRUE
                    ),
                    updated_at = NOW()
                WHERE id = :product_uuid";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':product_uuid' => $productUuid]);
    }
}
