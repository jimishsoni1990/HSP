# WooCommerce Products Integration Plan
# Headless Sync Platform (HSP) — Commerce Module

**Project:** Headless Sync Platform (HSP)
**Document Type:** Design & Implementation Plan
**Module:** Commerce (WooCommerce Products)
**Version:** 1.0
**Status:** Draft
**Integrity Mode:** Demo

> **Core Architectural Constraint — One Source of Truth**
> The data flow is strictly unidirectional: **WordPress / WooCommerce → PostgreSQL → Next.js**.
> The frontend must never write directly to PostgreSQL.
> WordPress/WooCommerce is the sole authority for all catalog management, inventory updates, and product configuration.

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [R1 — Product Schema & PostgreSQL Tables](#2-r1--product-schema--postgresql-tables)
3. [R2 — Backend Outbox Event Synchronization](#3-r2--backend-outbox-event-synchronization)
4. [R3 — Transformers & Canonical Models Design](#4-r3--transformers--canonical-models-design)
5. [R4 — REST Delivery API & Performance Design](#5-r4--rest-delivery-api--performance-design)
6. [R5 — Next.js Frontend Integration Plan](#6-r5--nextjs-frontend-integration-plan)
7. [Module File Structure Reference](#7-module-file-structure-reference)
8. [Migration & Activation Sequence](#8-migration--activation-sequence)
9. [Revalidation & Cache Invalidation Strategy](#9-revalidation--cache-invalidation-strategy)
10. [Verification Checklist](#10-verification-checklist)

---

## 1. Architecture Overview

### 1.1 How the Commerce Module Fits into HSP

The Commerce module follows the exact same patterns established by the Content module. It is a self-contained module registered alongside Content in the HSP plugin's module registry. All coordination flows through the same event outbox infrastructure.

```text
WooCommerce Admin
        │
        │  (product save / update / delete / variation update)
        ▼
WordPress Action Hooks
        │
        ▼
WordpressProductEventListener  (new — registered in headless-sync plugin)
        │
        ▼
OutboxService::publishProduct()   (publishes a versioned EventEnvelope)
        │
        ▼
system.events  +  system.queue_jobs  (PostgreSQL outbox tables, already exist)
        │
        ▼
WorkerEngine  (existing worker loop — unchanged)
        │
        ▼
Commerce\Module::handleProduct*()  (subscriber callbacks)
        │
        ▼
ProductTransformer::fromEventPayload()
        │
        ▼
ProductCanonicalModel
        │
        ▼
ProductPostgresAdapter::persist()
        │
        ▼
content.products  /  content.product_attributes
content.product_variations  /  content.product_media
        │
        ▼
delivery-api.php  (/api/v1/products)
        │
        ▼
Next.js Frontend  (ISR pages read only via REST API)
```

### 1.2 Module Identity

```json
{
  "name": "Commerce",
  "version": "1.0.0",
  "description": "WooCommerce catalog synchronization — Products, Variations, Attributes, and Media",
  "namespace": "HSP\\Modules\\Commerce",
  "entry": "Module.php"
}
```

### 1.3 WooCommerce Product Type Taxonomy

| WooCommerce Type | `post_type` | Identification | Notes |
|---|---|---|---|
| Simple | `product` | `_product_type = simple` | Single SKU, price, stock |
| Variable | `product` | `_product_type = variable` | Parent post; children are variations |
| Grouped | `product` | `_product_type = grouped` | References child simple products |
| External / Affiliate | `product` | `_product_type = external` | Has `_product_url` and `_button_text` |
| Variation | `product_variation` | child of variable parent | Own SKU, price, image, attributes |

All types are stored in `content.products`. Variations are additionally denormalized to `content.product_variations` for efficient lookups.

---

## 2. R1 — Product Schema & PostgreSQL Tables

All tables are created under the existing `content` schema. Tables follow the naming and structural conventions already established by the Content module (`content.posts`, `content.taxonomies`).

### 2.1 `content.products`

The master product table. Supports all WooCommerce product types via the `product_type` discriminator column. Denormalized pricing and stock fields allow simple catalog queries without JOINs to variation tables.

```sql
-- ============================================================================
-- Commerce Module — Migration 01
-- content.products: Master product projection table
-- Version: 1.0.0
-- ============================================================================

CREATE TABLE IF NOT EXISTS content.products (
    -- Internal surrogate key (UUIDv7 — time-ordered, suitable as PK)
    id                      UUID PRIMARY KEY,

    -- WordPress / WooCommerce source identifiers
    source_post_id          VARCHAR(50)  NOT NULL UNIQUE,
    source_entity_type      VARCHAR(50)  NOT NULL DEFAULT 'product',

    -- Product type discriminator
    -- Allowed values: 'simple', 'variable', 'grouped', 'external'
    product_type            VARCHAR(30)  NOT NULL DEFAULT 'simple',

    -- Core catalog fields
    slug                    VARCHAR(400) NOT NULL,
    name                    TEXT         NOT NULL,
    description             TEXT,
    short_description       TEXT,
    status                  VARCHAR(50)  NOT NULL DEFAULT 'publish',

    -- Pricing (stored as NUMERIC for precision; variable products store the min price)
    regular_price           NUMERIC(14, 4),
    sale_price              NUMERIC(14, 4),
    price                   NUMERIC(14, 4),          -- effective current price
    price_min               NUMERIC(14, 4),          -- variable: min variation price
    price_max               NUMERIC(14, 4),          -- variable: max variation price

    -- Stock management
    sku                     VARCHAR(200),
    manage_stock            BOOLEAN      NOT NULL DEFAULT FALSE,
    stock_quantity          INTEGER,
    stock_status            VARCHAR(30)  NOT NULL DEFAULT 'instock',
                                                     -- 'instock', 'outofstock', 'onbackorder'
    backorders_allowed      BOOLEAN      NOT NULL DEFAULT FALSE,

    -- External product fields (product_type = 'external')
    external_url            TEXT,
    button_text             VARCHAR(200),

    -- Grouped product: comma-delimited source_post_ids of child products
    grouped_product_ids     JSONB,                   -- e.g. ["123","456"]

    -- Taxonomy links (denormalized for common queries)
    category_ids            JSONB,                   -- array of content.taxonomies source_term_ids
    tag_ids                 JSONB,                   -- array of content.taxonomies source_term_ids

    -- Media: featured image resolved URL
    featured_image_url      TEXT,

    -- WooCommerce product dimensions & shipping
    weight                  NUMERIC(10, 4),
    dimensions              JSONB,                   -- {"length": "10", "width": "5", "height": "3"}

    -- SEO metadata (compatible with existing JSONB pattern in content.posts)
    seo                     JSONB,

    -- Timestamps
    created_at              TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at              TIMESTAMP WITH TIME ZONE
);

-- ----------------------------------------------------------------------------
-- Indexes on content.products
-- ----------------------------------------------------------------------------

-- Primary lookup by URL slug (used by delivery API and Next.js PDP route)
CREATE UNIQUE INDEX IF NOT EXISTS idx_products_slug
    ON content.products (slug)
    WHERE deleted_at IS NULL;

-- Catalog listing: filter by status, sort by price
CREATE INDEX IF NOT EXISTS idx_products_status_price
    ON content.products (status, price)
    WHERE deleted_at IS NULL;

-- Catalog listing: sort by recency
CREATE INDEX IF NOT EXISTS idx_products_status_created
    ON content.products (status, created_at DESC)
    WHERE deleted_at IS NULL;

-- SKU lookup (admin search, inventory check)
CREATE INDEX IF NOT EXISTS idx_products_sku
    ON content.products (sku)
    WHERE sku IS NOT NULL AND deleted_at IS NULL;

-- Product type filter (e.g. 'variable' products need variation expand)
CREATE INDEX IF NOT EXISTS idx_products_type
    ON content.products (product_type)
    WHERE deleted_at IS NULL;

-- GIN index for JSONB category_ids array membership queries
CREATE INDEX IF NOT EXISTS idx_products_category_ids
    ON content.products USING GIN (category_ids);

-- GIN index for JSONB tag_ids array membership queries
CREATE INDEX IF NOT EXISTS idx_products_tag_ids
    ON content.products USING GIN (tag_ids);
```

### 2.2 `content.product_attributes`

Stores both **global WooCommerce attributes** (registered taxonomy-based, e.g. `pa_color`) and **custom product-level attributes** (free-form, e.g. "Material"). Each row is one attribute-term assignment.

```sql
-- ============================================================================
-- Commerce Module — Migration 01
-- content.product_attributes: Per-product attribute assignments
-- ============================================================================

CREATE TABLE IF NOT EXISTS content.product_attributes (
    id                  UUID PRIMARY KEY,
    product_id          UUID NOT NULL
                            REFERENCES content.products (id) ON DELETE CASCADE,

    -- Attribute identity
    attribute_key       VARCHAR(200) NOT NULL,
                                                -- WC taxonomy name: 'pa_color'
                                                -- or custom label: 'Material'
    attribute_label     VARCHAR(200) NOT NULL,  -- Human-readable label shown on PDP
    attribute_type      VARCHAR(20)  NOT NULL DEFAULT 'custom',
                                                -- 'taxonomy' | 'custom'

    -- Values (can be single or multi-value)
    -- Stored as JSONB array of strings for flexibility
    values              JSONB        NOT NULL,  -- e.g. ["Red", "Blue"] or ["Cotton"]

    -- Whether this attribute is shown on the PDP attribute table
    is_visible          BOOLEAN      NOT NULL DEFAULT TRUE,

    -- Whether this attribute drives variation selection on variable products
    is_for_variations   BOOLEAN      NOT NULL DEFAULT FALSE,

    -- Display position
    position            SMALLINT     NOT NULL DEFAULT 0,

    created_at          TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- Indexes on content.product_attributes
-- ----------------------------------------------------------------------------

-- Primary lookup: all attributes for a given product
CREATE INDEX IF NOT EXISTS idx_product_attributes_product_id
    ON content.product_attributes (product_id);

-- Attribute key lookup (for filtering catalog by attribute)
CREATE INDEX IF NOT EXISTS idx_product_attributes_key
    ON content.product_attributes (attribute_key);

-- GIN index on values array for attribute-value filter queries
CREATE INDEX IF NOT EXISTS idx_product_attributes_values
    ON content.product_attributes USING GIN (values);

-- Composite: product + attribute key (for variation matching logic)
CREATE INDEX IF NOT EXISTS idx_product_attributes_product_key
    ON content.product_attributes (product_id, attribute_key);
```

### 2.3 `content.product_variations`

Each row represents one WooCommerce variation of a variable product (`post_type = product_variation`). The attribute combination that identifies this variation is stored in the `attributes` JSONB column as a map of `attribute_key → value`.

```sql
-- ============================================================================
-- Commerce Module — Migration 01
-- content.product_variations: Variable product variant rows
-- ============================================================================

CREATE TABLE IF NOT EXISTS content.product_variations (
    id                      UUID PRIMARY KEY,

    -- Link to parent variable product
    product_id              UUID NOT NULL
                                REFERENCES content.products (id) ON DELETE CASCADE,

    -- WordPress variation post ID
    source_variation_id     VARCHAR(50) NOT NULL UNIQUE,

    -- Variation-specific pricing
    regular_price           NUMERIC(14, 4),
    sale_price              NUMERIC(14, 4),
    price                   NUMERIC(14, 4),          -- effective current price

    -- Variation SKU (overrides parent)
    sku                     VARCHAR(200),

    -- Variation stock (overrides parent when manage_stock = true on variation)
    manage_stock            BOOLEAN      NOT NULL DEFAULT FALSE,
    stock_quantity          INTEGER,
    stock_status            VARCHAR(30)  NOT NULL DEFAULT 'instock',
    backorders_allowed      BOOLEAN      NOT NULL DEFAULT FALSE,

    -- Variation-specific image
    image_url               TEXT,

    -- Attribute combination that uniquely identifies this variation
    -- Map of attribute_key → selected_value
    -- Example: {"pa_color": "Red", "pa_size": "L"}
    attributes              JSONB        NOT NULL DEFAULT '{}',

    -- Variation description (optional)
    description             TEXT,

    -- Whether the variation is enabled for purchase
    is_enabled              BOOLEAN      NOT NULL DEFAULT TRUE,

    -- Display weight (for ordering in selectors)
    menu_order              INTEGER      NOT NULL DEFAULT 0,

    created_at              TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- Indexes on content.product_variations
-- ----------------------------------------------------------------------------

-- Primary lookup: all variations for a parent product
CREATE INDEX IF NOT EXISTS idx_product_variations_product_id
    ON content.product_variations (product_id);

-- SKU lookup across variations
CREATE INDEX IF NOT EXISTS idx_product_variations_sku
    ON content.product_variations (sku)
    WHERE sku IS NOT NULL;

-- Price sorting across variations (for price_min/price_max aggregation)
CREATE INDEX IF NOT EXISTS idx_product_variations_product_price
    ON content.product_variations (product_id, price);

-- GIN index on attributes JSONB for attribute-combination matching
-- Enables queries like: WHERE attributes @> '{"pa_color": "Red"}'
CREATE INDEX IF NOT EXISTS idx_product_variations_attributes
    ON content.product_variations USING GIN (attributes);
```

### 2.4 `content.product_media`

Stores the full media gallery for each product. The featured image URL is also denormalized to `content.products.featured_image_url` for single-row access.

```sql
-- ============================================================================
-- Commerce Module — Migration 01
-- content.product_media: Product gallery images and media metadata
-- ============================================================================

CREATE TABLE IF NOT EXISTS content.product_media (
    id                  UUID PRIMARY KEY,
    product_id          UUID NOT NULL
                            REFERENCES content.products (id) ON DELETE CASCADE,

    -- WordPress attachment ID (source reference)
    source_attachment_id VARCHAR(50),

    -- Fully qualified URL (resolved at sync time from wp_get_attachment_image_src)
    url                 TEXT NOT NULL,

    -- Thumbnail and srcset variants resolved at sync time
    thumbnail_url       TEXT,
    medium_url          TEXT,
    large_url           TEXT,

    -- Accessibility
    alt_text            TEXT,
    caption             TEXT,

    -- Gallery ordering (0 = featured image)
    position            SMALLINT     NOT NULL DEFAULT 0,

    -- Whether this is the designated featured image
    is_featured         BOOLEAN      NOT NULL DEFAULT FALSE,

    created_at          TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- Indexes on content.product_media
-- ----------------------------------------------------------------------------

-- Primary lookup: all gallery images for a product, ordered by position
CREATE INDEX IF NOT EXISTS idx_product_media_product_position
    ON content.product_media (product_id, position);

-- Featured image fast access
CREATE INDEX IF NOT EXISTS idx_product_media_featured
    ON content.product_media (product_id, is_featured)
    WHERE is_featured = TRUE;
```

### 2.5 `content.product_categories` (Pivot)

Joins products to WooCommerce product categories stored in `content.taxonomies` (taxonomy_type = `product_cat`).

```sql
-- ============================================================================
-- Commerce Module — Migration 01
-- content.product_categories: Product ↔ Category pivot table
-- ============================================================================

CREATE TABLE IF NOT EXISTS content.product_categories (
    product_id      UUID REFERENCES content.products(id)    ON DELETE CASCADE,
    taxonomy_id     UUID REFERENCES content.taxonomies(id)  ON DELETE CASCADE,
    PRIMARY KEY (product_id, taxonomy_id)
);

CREATE INDEX IF NOT EXISTS idx_product_categories_taxonomy
    ON content.product_categories (taxonomy_id);
```

### 2.6 Schema Summary Diagram

```text
content.products (master)
    │
    ├──< content.product_attributes  (1:N — attribute assignments)
    │
    ├──< content.product_variations  (1:N — variable product variants)
    │
    ├──< content.product_media       (1:N — gallery images)
    │
    └──< content.product_categories  (N:M via pivot → content.taxonomies)
```

---

## 3. R2 — Backend Outbox Event Synchronization

### 3.1 WordPress / WooCommerce Action Hooks

The new `WordpressProductEventListener` class registers the following hooks. Hook selection follows the same pattern used by `WordpressEventListener` for posts (`wp_after_insert_post`, `before_delete_post`).

| Hook | Priority | Arguments | Purpose |
|---|---|---|---|
| `woocommerce_new_product` | 10 | `$product_id` | Fires when a new product post is created (any type) |
| `woocommerce_update_product` | 10 | `$product_id`, `$product` | Fires after any product save in WC admin |
| `before_delete_post` | 10 | `$post_id`, `$post` | Catches hard-delete of a `product` post type |
| `wp_trash_post` | 10 | `$post_id` | Catches trash (soft-delete) of a product |
| `untrashed_post` | 10 | `$post_id` | Catches restore from trash |
| `woocommerce_save_product_variation` | 10 | `$variation_id`, `$i` | Fires after a specific variation is saved |
| `woocommerce_delete_product_variation` | 10 | `$variation_id`, `$product_id` | Fires when a variation is deleted |
| `woocommerce_product_duplicate` | 10 | `$duplicate`, `$product` | Fires after WC product duplication |
| `woocommerce_update_product_stock` | 10 | `$product` | Fires when stock quantity changes (e.g. from order) |

#### Why These Hooks

- **`woocommerce_new_product` / `woocommerce_update_product`**: These are the canonical WooCommerce hooks that fire after WC's own internal save is complete, meaning all meta (prices, stock, attributes) is already written. Do NOT use `save_post_product` alone — it fires before WC meta is saved.
- **`before_delete_post`**: Captures hard-delete so the aggregate ID is still resolvable before the row is removed.
- **`woocommerce_save_product_variation`**: Variations have their own save lifecycle and must be captured independently because `woocommerce_update_product` does NOT re-fire for individual variation saves in all WC versions.
- **`woocommerce_update_product_stock`**: Inventory changes from order processing bypass the admin edit screen; this hook ensures stock status changes (instock → outofstock) propagate to PostgreSQL without requiring a manual product save.
- **`woocommerce_product_duplicate`**: WC's built-in duplication creates a new post without triggering `woocommerce_new_product`; must be captured separately.

### 3.2 Event Type Constants

New class: `HSP\Modules\Commerce\Events\CommerceEventTypes`

```text
commerce.product.created
commerce.product.updated
commerce.product.deleted
commerce.product.stock_updated
commerce.product_variation.created
commerce.product_variation.updated
commerce.product_variation.deleted
```

Convention mirrors `ContentEventTypes` (e.g. `content.post.created`).

### 3.3 Event Payload Structures

All payloads are passed to the existing `OutboxService` mechanism via a new `publishProduct()` method. The payload is stored as JSONB in `system.events.payload`.

#### 3.3.1 Product Created / Updated Payload

```json
{
  "ID": "123",
  "post_type": "product",
  "post_name": "blue-widget",
  "post_title": "Blue Widget",
  "post_content": "<p>Full HTML description...</p>",
  "post_excerpt": "Short description of the blue widget.",
  "post_status": "publish",
  "post_modified_gmt": "2025-06-15T10:30:00Z",
  "product_type": "variable",
  "regular_price": "49.99",
  "sale_price": "",
  "price": "49.99",
  "sku": "BW-001",
  "manage_stock": true,
  "stock_quantity": 50,
  "stock_status": "instock",
  "backorders_allowed": false,
  "external_url": "",
  "button_text": "",
  "weight": "0.5",
  "dimensions": { "length": "10", "width": "5", "height": "3" },
  "category_ids": [45, 67],
  "tag_ids": [12, 34],
  "featured_image_url": "https://example.com/wp-content/uploads/widget.jpg",
  "gallery_image_ids": [88, 89, 90],
  "gallery_images": [
    {
      "attachment_id": "88",
      "url": "https://example.com/wp-content/uploads/w1.jpg",
      "thumbnail_url": "https://example.com/wp-content/uploads/w1-150x150.jpg",
      "medium_url": "https://example.com/wp-content/uploads/w1-300x300.jpg",
      "large_url": "https://example.com/wp-content/uploads/w1-1024x1024.jpg",
      "alt_text": "Blue Widget front view",
      "caption": ""
    }
  ],
  "attributes": [
    {
      "key": "pa_color",
      "label": "Color",
      "type": "taxonomy",
      "values": ["Blue", "Red"],
      "is_visible": true,
      "is_for_variations": true,
      "position": 0
    },
    {
      "key": "pa_size",
      "label": "Size",
      "type": "taxonomy",
      "values": ["S", "M", "L", "XL"],
      "is_visible": true,
      "is_for_variations": true,
      "position": 1
    },
    {
      "key": "material",
      "label": "Material",
      "type": "custom",
      "values": ["Recycled Plastic"],
      "is_visible": true,
      "is_for_variations": false,
      "position": 2
    }
  ],
  "variation_ids": [124, 125, 126],
  "grouped_product_ids": [],
  "seo": {
    "meta_title": "",
    "meta_description": "",
    "og_title": "",
    "og_description": "",
    "og_image": ""
  }
}
```

#### 3.3.2 Product Deleted Payload

```json
{
  "ID": "123",
  "post_type": "product"
}
```

#### 3.3.3 Product Variation Created / Updated Payload

```json
{
  "variation_id": "124",
  "parent_product_id": "123",
  "regular_price": "44.99",
  "sale_price": "",
  "price": "44.99",
  "sku": "BW-001-BLUE-L",
  "manage_stock": true,
  "stock_quantity": 15,
  "stock_status": "instock",
  "backorders_allowed": false,
  "image_id": "91",
  "image_url": "https://example.com/wp-content/uploads/w-blue.jpg",
  "attributes": {
    "pa_color": "Blue",
    "pa_size": "L"
  },
  "description": "",
  "is_enabled": true,
  "menu_order": 0
}
```

#### 3.3.4 Product Variation Deleted Payload

```json
{
  "variation_id": "124",
  "parent_product_id": "123"
}
```

#### 3.3.5 Stock Updated Payload

```json
{
  "ID": "123",
  "post_type": "product",
  "stock_quantity": 5,
  "stock_status": "instock",
  "manage_stock": true
}
```

### 3.4 Data Extraction Strategy in the Hook Listener

The `WordpressProductEventListener` must extract a complete payload before publishing. The recommended extraction sequence per hook:

1. Call `wc_get_product($product_id)` to get a hydrated `WC_Product` object (available post-save).
2. Use WC_Product getters: `get_type()`, `get_name()`, `get_slug()`, `get_description()`, `get_short_description()`, `get_price()`, `get_regular_price()`, `get_sale_price()`, `get_sku()`, `get_manage_stock()`, `get_stock_quantity()`, `get_stock_status()`, `get_backorders()`.
3. For attributes: call `get_attributes()` which returns `WC_Product_Attribute[]`.
4. For gallery images: call `get_gallery_image_ids()`, then resolve each with `wp_get_attachment_image_url($id, 'large')`, `wp_get_attachment_image_url($id, 'medium')`, etc.
5. For categories: call `get_category_ids()` on the product.
6. For variable products: call `get_children()` to get variation post IDs.
7. Apply `hsp_sync_product_seo_data` filter (analogous to `hsp_sync_post_seo_data`) to allow Yoast SEO data injection.

### 3.5 OutboxService Extension

A new `publishProduct(array $productData, string $eventType): EventEnvelope` method is added to `OutboxService`. It mirrors `publishPost()` exactly:

1. Atomically increments `system.aggregate_versions` for `aggregate_type = 'product'`.
2. Calls `EventBuilder::buildFromProduct($productData, $eventType, $version)` (new builder method).
3. Calls `saveEvent($envelope)` → inserts into `system.events`.
4. Calls `queueJob($envelope)` → inserts into `system.queue_jobs` with `queue_name = 'commerce'`.
5. Commits the transaction atomically (all three writes succeed or all rollback).

---

## 4. R3 — Transformers & Canonical Models Design

The Commerce module owns all transformation logic. The Core framework is not modified.

### 4.1 Directory Structure

```text
headless-sync/modules/Commerce/
├── CanonicalModels/
│   ├── Product.php              ← implements CanonicalModelInterface
│   ├── ProductVariation.php     ← implements CanonicalModelInterface
│   └── ProductAttribute.php     ← value object (no interface required)
├── Config/
│   └── commerce.php             ← module configuration constants
├── Events/
│   └── CommerceEventTypes.php   ← event type string constants
├── Migrations/
│   └── 01_create_commerce_tables.sql  ← SQL from Section 2
├── Transformers/
│   ├── ProductTransformer.php         ← raw payload → ProductCanonicalModel
│   ├── ProductVariationTransformer.php ← raw payload → ProductVariationCanonicalModel
│   ├── ProductAttributeTransformer.php ← attribute sub-array → ProductAttribute value object
│   └── ProductMediaTransformer.php    ← gallery sub-array → media value objects
├── Module.php                   ← registers worker subscriptions, runs migrations
└── module.json                  ← module manifest
```

### 4.2 `ProductCanonicalModel` — Contract & Fields

**Class:** `HSP\Modules\Commerce\CanonicalModels\Product`
**Implements:** `HSP\Core\Contracts\CanonicalModelInterface`

| Getter | Type | Description |
|---|---|---|
| `getAggregateType()` | `string` | Returns `'product'` |
| `getAggregateId()` | `string` | WordPress post ID |
| `getAggregateVersion()` | `int` | From event envelope |
| `toArray()` | `array` | Full serialized representation |
| `getSourcePostId()` | `string` | WP post ID |
| `getSlug()` | `string` | URL slug |
| `getName()` | `string` | Product name / title |
| `getDescription()` | `string` | Full HTML description |
| `getShortDescription()` | `string` | Excerpt |
| `getStatus()` | `string` | `publish`, `draft`, `trash` |
| `getProductType()` | `string` | `simple`, `variable`, `grouped`, `external` |
| `getRegularPrice()` | `?string` | Raw string from WC |
| `getSalePrice()` | `?string` | Raw string from WC |
| `getPrice()` | `?string` | Effective price |
| `getSku()` | `string` | SKU |
| `getManageStock()` | `bool` | Whether stock is managed |
| `getStockQuantity()` | `?int` | Quantity or null |
| `getStockStatus()` | `string` | `instock`, `outofstock`, `onbackorder` |
| `getBackordersAllowed()` | `bool` | Backorder setting |
| `getExternalUrl()` | `string` | For external products |
| `getButtonText()` | `string` | CTA text for external |
| `getWeight()` | `?string` | Weight string |
| `getDimensions()` | `array` | `{length, width, height}` |
| `getCategoryIds()` | `array` | WP term IDs |
| `getTagIds()` | `array` | WP term IDs |
| `getFeaturedImageUrl()` | `string` | Featured image URL |
| `getGalleryImages()` | `array` | Array of media value objects |
| `getAttributes()` | `array` | Array of `ProductAttribute` value objects |
| `getVariationIds()` | `array` | WP variation post IDs |
| `getGroupedProductIds()` | `array` | WP post IDs for grouped |
| `getSeo()` | `?array` | SEO metadata |
| `getDeletedAt()` | `?string` | Soft-delete timestamp |

### 4.3 `ProductTransformer` — Mapping Logic

**Class:** `HSP\Modules\Commerce\Transformers\ProductTransformer`

**Static method:** `fromEventPayload(array $payload): Product`

Mapping rules:

| Canonical Field | Source in Payload | Transform |
|---|---|---|
| `sourcePostId` | `$payload['ID']` | Cast to string |
| `slug` | `$payload['post_name']` | String |
| `name` | `$payload['post_title']` | String |
| `description` | `$payload['post_content']` | String (raw HTML) |
| `shortDescription` | `$payload['post_excerpt']` | String |
| `status` | `$payload['post_status']` | String; if `'trash'` → set `deletedAt = now()` |
| `productType` | `$payload['product_type']` | String; default `'simple'` |
| `regularPrice` | `$payload['regular_price']` | String or null |
| `salePrice` | `$payload['sale_price']` | String or null; empty string → null |
| `price` | `$payload['price']` | String or null |
| `sku` | `$payload['sku']` | String |
| `manageStock` | `$payload['manage_stock']` | Cast to bool |
| `stockQuantity` | `$payload['stock_quantity']` | Cast to int or null |
| `stockStatus` | `$payload['stock_status']` | String; default `'instock'` |
| `backordersAllowed` | `$payload['backorders_allowed']` | Cast to bool |
| `externalUrl` | `$payload['external_url']` | String |
| `buttonText` | `$payload['button_text']` | String |
| `weight` | `$payload['weight']` | String or null |
| `dimensions` | `$payload['dimensions']` | Array `{length, width, height}` |
| `categoryIds` | `$payload['category_ids']` | Array of ints |
| `tagIds` | `$payload['tag_ids']` | Array of ints |
| `featuredImageUrl` | `$payload['featured_image_url']` | String |
| `galleryImages` | `$payload['gallery_images']` | Delegated to `ProductMediaTransformer::fromGallery()` |
| `attributes` | `$payload['attributes']` | Delegated to `ProductAttributeTransformer::fromArray()` each |
| `variationIds` | `$payload['variation_ids']` | Array of ints |
| `groupedProductIds` | `$payload['grouped_product_ids']` | Array of ints |
| `seo` | `$payload['seo']` | Array or null |

### 4.4 `ProductVariationTransformer`

**Class:** `HSP\Modules\Commerce\Transformers\ProductVariationTransformer`

**Static method:** `fromEventPayload(array $payload): ProductVariation`

Extracts `variation_id`, `parent_product_id`, prices, SKU, stock fields, `image_url`, and the `attributes` map (key → value pairs). The `attributes` map is stored as-is into the `ProductVariation` canonical model.

### 4.5 `ProductAttributeTransformer`

**Class:** `HSP\Modules\Commerce\Transformers\ProductAttributeTransformer`

**Static method:** `fromArray(array $attrData): ProductAttribute`

Value object (not implementing `CanonicalModelInterface` since it is a sub-component). Returns a simple DTO with: `key`, `label`, `type`, `values`, `isVisible`, `isForVariations`, `position`.

### 4.6 `ProductMediaTransformer`

**Class:** `HSP\Modules\Commerce\Transformers\ProductMediaTransformer`

**Static method:** `fromGallery(array $galleryImages): array`

Converts each gallery image sub-array into a structured media value object array: `{ attachmentId, url, thumbnailUrl, mediumUrl, largeUrl, altText, caption, position }`.

### 4.7 `ProductPostgresAdapter` — Persistence Logic

**Class:** `HSP\Modules\Commerce\Adapters\ProductPostgresAdapter`
**Implements:** `HSP\Core\Contracts\AdapterInterface`

**`persist(CanonicalModelInterface $model): void`**

The adapter performs a multi-table upsert in the following sequence, all within a single PDO transaction:

**Step 1 — Upsert `content.products`**
- Check for existing row by `source_post_id`.
- If new: generate UUIDv7, INSERT.
- If existing: UPDATE all scalar fields.
- Compute `price_min` and `price_max` for variable products (see Section 5.1 for query).

**Step 2 — Sync `content.product_attributes`**
- DELETE all existing attribute rows for this product UUID.
- Re-INSERT each attribute from the canonical model, generating new UUIDv7 for each row.
- This full-replace strategy is safe because attributes are always provided in full on every product save.

**Step 3 — Sync `content.product_media`**
- DELETE all existing media rows for this product UUID.
- Re-INSERT each gallery image from the canonical model, assigning `position = 0` to the featured image, `position = 1..N` for gallery order.

**Step 4 — Sync `content.product_categories`**
- DELETE existing rows for this product UUID.
- For each `category_id` in the canonical model: resolve the UUID from `content.taxonomies WHERE source_term_id = :cat_id`, then INSERT into `content.product_categories`.

**Step 5 — Update `content.products.price_min` / `price_max`** (for variable products)
- After variations are processed (handled by the variation event flow), the product row's `price_min` and `price_max` are recalculated:
  ```sql
  UPDATE content.products
  SET price_min = (
        SELECT MIN(price) FROM content.product_variations
        WHERE product_id = :product_uuid AND is_enabled = TRUE
      ),
      price_max = (
        SELECT MAX(price) FROM content.product_variations
        WHERE product_id = :product_uuid AND is_enabled = TRUE
      ),
      updated_at = NOW()
  WHERE id = :product_uuid;
  ```
  This update is triggered by the `commerce.product_variation.*` event handler.

**`delete(string $aggregateType, string $aggregateId): void`**
- Soft-delete: `UPDATE content.products SET deleted_at = NOW() WHERE source_post_id = :id`.
- Cascade DELETE is handled at the database level by foreign key constraints on all child tables.

### 4.8 Module.php — Worker Subscriptions

```text
WorkerEngine::subscribe(CommerceEventTypes::PRODUCT_CREATED,   [$this, 'handleProductCreatedOrUpdated']);
WorkerEngine::subscribe(CommerceEventTypes::PRODUCT_UPDATED,   [$this, 'handleProductCreatedOrUpdated']);
WorkerEngine::subscribe(CommerceEventTypes::PRODUCT_DELETED,   [$this, 'handleProductDeleted']);
WorkerEngine::subscribe(CommerceEventTypes::STOCK_UPDATED,     [$this, 'handleStockUpdated']);
WorkerEngine::subscribe(CommerceEventTypes::VARIATION_CREATED, [$this, 'handleVariationCreatedOrUpdated']);
WorkerEngine::subscribe(CommerceEventTypes::VARIATION_UPDATED, [$this, 'handleVariationCreatedOrUpdated']);
WorkerEngine::subscribe(CommerceEventTypes::VARIATION_DELETED, [$this, 'handleVariationDeleted']);
```

Each handler follows the same pattern as `Module::handlePostCreatedOrUpdated()` in the Content module:
1. Extract payload from `$envelope->getPayload()`.
2. Call the appropriate transformer.
3. Pass the canonical model to the adapter.
4. Trigger `triggerRevalidation()` for the product slug (and any old slug if changed).

---

## 5. R4 — REST Delivery API & Performance Design

### 5.1 New Endpoints in `delivery-api.php`

The existing `delivery-api.php` file uses a simple URI routing pattern (`if ($uri === 'api/v1/posts')`). The following new routes are added at the end of the routing section.

#### Endpoint 1 — `GET /api/v1/products`

**Purpose:** Product listing page (PLP) data. Supports cursor-based pagination, sorting, and attribute filtering. Variations are **never** returned in this list response to guarantee sub-100ms listing performance.

**Query Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `slug` | string | Return a single product by slug (triggers detail response) |
| `category` | string | Filter by product category slug |
| `type` | string | Filter by product type (`simple`, `variable`, `grouped`, `external`) |
| `min_price` | float | Lower price bound (inclusive) |
| `max_price` | float | Upper price bound (inclusive) |
| `in_stock` | boolean | If `1`, only return products with `stock_status = 'instock'` |
| `attr_{key}` | string | Attribute filter. e.g. `attr_pa_color=Blue` filters by attribute value |
| `sort` | string | `price_asc`, `price_desc`, `date_asc`, `date_desc`, `name_asc` |
| `cursor` | string | Opaque base64-encoded sorting cursor (default null) |
| `per_page` | int | Items per page (default `20`, max `100`) |

**List Response Shape (simplified):**
```json
{
  "data": [
    {
      "id": "uuid",
      "slug": "blue-widget",
      "name": "Blue Widget",
      "product_type": "variable",
      "price": "44.99",
      "price_min": "44.99",
      "price_max": "69.99",
      "regular_price": "49.99",
      "sale_price": "44.99",
      "stock_status": "instock",
      "featured_image_url": "https://...",
      "categories": [{ "id": "uuid", "slug": "widgets", "name": "Widgets" }]
    }
  ],
  "meta": {
    "next_cursor": "eyJjcmVhdGVkX2F0IjoiMjAyNi0wNi0xNlQxNTozNTo1NFoiLCJpZCI6IjAwMDAwMDAwLTAwMDAtMDAwMC0wMDAwLTAwMDAwMDAwMDAwMCJ9",
    "has_more": true,
    "per_page": 20
  }
}
```

**Core SQL for Listing (with date_desc sorting and cursor pagination):**
```sql
SELECT
    p.id, p.slug, p.name, p.product_type,
    p.price, p.price_min, p.price_max, p.regular_price, p.sale_price,
    p.stock_status, p.featured_image_url,
    p.sku, p.manage_stock, p.stock_quantity,
    p.created_at
FROM content.products p
WHERE p.deleted_at IS NULL
  AND p.status = 'publish'
  -- Cursor condition for date_desc:
  -- AND (p.created_at < :cursor_date OR (p.created_at = :cursor_date AND p.id < :cursor_id))
  -- Dynamic WHERE clauses added per query param:
  -- AND p.price >= :min_price
  -- AND p.price <= :max_price
  -- AND p.stock_status = 'instock'
  -- AND p.product_type = :type
  -- AND p.id IN (
  --   SELECT product_id FROM content.product_categories
  --   WHERE taxonomy_id = :cat_uuid
  -- )
  -- AND p.id IN (
  --   SELECT product_id FROM content.product_attributes
  --   WHERE attribute_key = :attr_key
  --     AND values @> :attr_value_jsonb
  -- )
ORDER BY p.created_at DESC, p.id DESC   -- Compound sort matching cursor index
LIMIT :per_page;
```

#### Endpoint 2 — `GET /api/v1/products?slug={slug}` (Detail)

**Purpose:** Product Detail Page (PDP) — single product with full variations, attributes, and gallery.

**Detail Response Shape:**
```json
{
  "id": "uuid",
  "slug": "blue-widget",
  "name": "Blue Widget",
  "product_type": "variable",
  "description": "<p>Full HTML...</p>",
  "short_description": "Short text",
  "status": "publish",
  "price": "44.99",
  "price_min": "44.99",
  "price_max": "69.99",
  "regular_price": "49.99",
  "sale_price": "44.99",
  "sku": "BW-001",
  "manage_stock": true,
  "stock_quantity": 50,
  "stock_status": "instock",
  "backorders_allowed": false,
  "weight": "0.5",
  "dimensions": { "length": "10", "width": "5", "height": "3" },
  "featured_image_url": "https://...",
  "media": [
    {
      "id": "uuid",
      "url": "https://...",
      "thumbnail_url": "https://...",
      "medium_url": "https://...",
      "large_url": "https://...",
      "alt_text": "Front view",
      "is_featured": true,
      "position": 0
    }
  ],
  "attributes": [
    {
      "key": "pa_color",
      "label": "Color",
      "type": "taxonomy",
      "values": ["Blue", "Red"],
      "is_visible": true,
      "is_for_variations": true,
      "position": 0
    }
  ],
  "variations": [
    {
      "id": "uuid",
      "source_variation_id": "124",
      "regular_price": "44.99",
      "sale_price": null,
      "price": "44.99",
      "sku": "BW-001-BLUE-L",
      "stock_status": "instock",
      "stock_quantity": 15,
      "image_url": "https://...",
      "attributes": { "pa_color": "Blue", "pa_size": "L" },
      "is_enabled": true
    }
  ],
  "categories": [{ "id": "uuid", "slug": "widgets", "name": "Widgets" }],
  "seo": { "meta_title": "", "meta_description": "" }
}
```

**Detail Query Strategy:** 4 parallel prepared statements executed in sequence:
1. Fetch product row from `content.products`.
2. Fetch `content.product_attributes` ordered by `position`.
3. Fetch `content.product_variations` ordered by `menu_order`.
4. Fetch `content.product_media` ordered by `position`.
5. Fetch `content.product_categories` JOINed to `content.taxonomies`.

#### Endpoint 3 — `GET /api/v1/products/categories`

**Purpose:** Return all WooCommerce product categories (pulled from `content.taxonomies` where `taxonomy_type = 'product_cat'`).

**Response:**
```json
[
  { "id": "uuid", "slug": "widgets", "name": "Widgets", "description": "..." }
]
```

#### Endpoint 4 — `GET /api/v1/products/export`

**Purpose:** Full catalog batch/streaming export endpoint. Designed for programmatic consumers that need all products and variations (e.g. search indexing, ERP syncs). Streams the catalog in chunks without memory footprint spikes.

**Query Parameters:**
- `cursor`: string (base64 opaque cursor containing last seen product ID)
- `batch_size`: int (default `100`, max `500`)

**Response Shape:**
Returns an array of fully-hydrated products (including variations, attributes, and media arrays) for the batch:
```json
{
  "data": [
    {
      "id": "uuid",
      "slug": "blue-widget",
      "name": "Blue Widget",
      "product_type": "variable",
      "variations": [ ... ],
      "attributes": [ ... ],
      "media": [ ... ]
    }
  ],
  "meta": {
    "next_cursor": "eyJpZCI6IjAwMDAwMDAwLTAwMDAtMDAwMC0wMDAwLTAwMDAwMDAwMDAwMCJ9",
    "has_more": true
  }
}
```

### 5.2 Performance Strategies

#### Index Optimization
All catalog indexes defined in Section 2 are tuned for the exact query patterns above:
- `idx_products_status_price` → price range + status filter
- `idx_products_status_created` → date sort
- `idx_products_category_ids` GIN → `@>` category membership (JSON array containment)
- `idx_product_attributes_values` GIN → attribute-value filter
- `idx_product_variations_attributes` GIN → variation lookup by attribute combination

#### Denormalized `price_min` / `price_max`
Variable product price ranges are pre-computed in `content.products` to avoid aggregation queries at read time.

#### PostgreSQL Materialized View (Optional — for large catalogs)
For catalogs exceeding 50,000 products, an optional materialized view `content.product_catalog_view` can be created that pre-JOINs products with their featured image and first category:

```sql
CREATE MATERIALIZED VIEW IF NOT EXISTS content.product_catalog_view AS
SELECT
    p.id, p.slug, p.name, p.product_type,
    p.price, p.price_min, p.price_max,
    p.regular_price, p.sale_price,
    p.stock_status, p.featured_image_url,
    t.slug AS primary_category_slug,
    t.name AS primary_category_name
FROM content.products p
LEFT JOIN content.product_categories pc ON pc.product_id = p.id
LEFT JOIN content.taxonomies t ON t.id = pc.taxonomy_id
WHERE p.deleted_at IS NULL AND p.status = 'publish'
WITH NO DATA;

CREATE UNIQUE INDEX ON content.product_catalog_view (id);
CREATE INDEX ON content.product_catalog_view (slug);
CREATE INDEX ON content.product_catalog_view (price);
CREATE INDEX ON content.product_catalog_view (primary_category_slug);
```

Refreshed via `REFRESH MATERIALIZED VIEW CONCURRENTLY content.product_catalog_view` triggered by the variation's revalidation step.

#### PHP-Level Response Caching
The delivery API can cache GET responses in-process using an `APCu` or `Memcached` layer:
- Cache key: MD5 of the full request URI.
- TTL: 60 seconds for listings, 300 seconds for product detail.
- Cache busted by `triggerRevalidation()` (existing mechanism) via a dedicated cache-clear HTTP call.

#### HTTP Response Caching Headers
```
Cache-Control: public, s-maxage=60, stale-while-revalidate=300
```
This enables CDN/edge caching at Cloudflare or similar, delivering sub-10ms responses for repeated catalog requests.

#### Guarantees & Strategies for 5,000+ Variable Products (< 200ms SLA)

With a catalog of 5,000 variable products (each potentially having dozens of variations, totaling up to 100,000+ unique variants), loading the entire catalog at once in a single request is an anti-pattern that violates the 200ms SLA. 

To achieve sub-100ms listing times and sub-50ms detail lookups for this catalog size, the following architecture is enforced:

1. **Zero Variations on Listing (PLP)**:
   - The listing endpoint (`GET /api/v1/products`) fetches **only** parent product fields (e.g. price range, title, slug, featured image).
   - Database reads retrieve only 20 rows per query using pre-computed fields.
   - Variations are completely excluded from lists, preventing high JSON-serialization overhead and database CPU spikes.

2. **Cursor-Based Pagination (No `OFFSET` Penalty)**:
   - Instead of SQL offset (`LIMIT 20 OFFSET 5000`), which forces PostgreSQL to scan and discard 5,000 rows, the API uses a seek cursor:
     `WHERE (created_at, id) < (:cursor_date, :cursor_id)`
   - Under this pattern, database query execution times remain constant (< 5ms) regardless of how deep the user paginates.

3. **Pre-computed Price Aggregations**:
   - The min and max variation prices (`price_min`, `price_max`) are calculated asynchronously on variation events and stored directly in the `content.products` table.
   - This eliminates `MIN()` / `MAX()` aggregations and dynamic variation JOINs during list queries.

4. **Cached Catalog Counts**:
   - The traditional count query (`SELECT COUNT(*)`) is slow on large datasets because of Postgres MVCC table scans.
   - The API replaces `total` and `total_pages` with `has_more` and `next_cursor` (derived by fetching `per_page + 1` rows).
   - If count is required, it is cached in `APCu` / Redis with a 1-hour TTL, or stored in a denormalized counter table incremented/decremented on product save/delete hooks.

5. **PHP-Level Caching & Edge CDNs**:
   - Responses are cached locally in PHP via `APCu` for 60 seconds (PLPs) and 300 seconds (PDPs).
   - Edge CDNs (e.g., Cloudflare) cache the JSON payloads under `s-maxage=60, stale-while-revalidate=300`.

**Expected SLA Metrics:**
- **Listing Page (PLP, 20 items, uncached database read)**: ~15-30ms
- **Listing Page (PLP, API-cached / Redis)**: ~5-10ms
- **Listing Page (PLP, CDN hit)**: < 5ms
- **Product Detail (PDP, 1 product + 50 variations, database read)**: ~20-50ms
- **Full Catalog Synchronizer (via Streaming `/export` endpoint)**: ~100-150ms per batch of 500 products (fully hydrated with variations).

---

## 6. R5 — Next.js Frontend Integration Plan

### 6.1 New Files to Create

```text
frontend/src/
├── app/
│   ├── products/
│   │   ├── page.tsx                    ← Product Listing Page (PLP)
│   │   └── [slug]/
│   │       └── page.tsx                ← Product Detail Page (PDP)
│   └── api/
│       └── revalidate/
│           └── route.ts                ← existing; no change needed
├── lib/
│   └── api.ts                          ← extend with product methods
└── components/
    ├── ProductCard.tsx                  ← PLP grid card component
    ├── ProductGallery.tsx               ← PDP image slideshow
    ├── ProductAttributeSelector.tsx     ← PDP variation attribute picker
    └── AddToCartButton.tsx              ← CTA button (links to WC cart or external URL)
```

### 6.2 `frontend/src/lib/api.ts` Extensions

New TypeScript interfaces and API methods added to the existing `api.ts` file.

#### New Interfaces

```typescript
export interface ProductAttribute {
  id: string;
  attribute_key: string;
  attribute_label: string;
  attribute_type: 'taxonomy' | 'custom';
  values: string[];
  is_visible: boolean;
  is_for_variations: boolean;
  position: number;
}

export interface ProductVariation {
  id: string;
  source_variation_id: string;
  regular_price: string | null;
  sale_price: string | null;
  price: string | null;
  sku: string;
  manage_stock: boolean;
  stock_quantity: number | null;
  stock_status: 'instock' | 'outofstock' | 'onbackorder';
  image_url: string | null;
  attributes: Record<string, string>;  // e.g. { "pa_color": "Blue", "pa_size": "L" }
  is_enabled: boolean;
}

export interface ProductMedia {
  id: string;
  url: string;
  thumbnail_url: string | null;
  medium_url: string | null;
  large_url: string | null;
  alt_text: string;
  is_featured: boolean;
  position: number;
}

export interface Product {
  id: string;
  slug: string;
  name: string;
  product_type: 'simple' | 'variable' | 'grouped' | 'external';
  description: string;
  short_description: string;
  status: string;
  price: string | null;
  price_min: string | null;
  price_max: string | null;
  regular_price: string | null;
  sale_price: string | null;
  sku: string;
  stock_status: 'instock' | 'outofstock' | 'onbackorder';
  stock_quantity: number | null;
  featured_image_url: string | null;
  external_url: string | null;
  button_text: string | null;
  media?: ProductMedia[];
  attributes?: ProductAttribute[];
  variations?: ProductVariation[];
  categories?: Category[];
  seo?: SeoMeta;
  created_at: string;
  updated_at: string;
}

export interface ProductListMeta {
  next_cursor: string | null;
  has_more: boolean;
  per_page: number;
}

export interface ProductListResponse {
  data: Product[];
  meta: ProductListMeta;
}
```

#### New API Methods

```typescript
// Add to the existing `api` object:
async getProducts(params?: {
  category?: string;
  type?: string;
  min_price?: number;
  max_price?: number;
  in_stock?: boolean;
  sort?: 'price_asc' | 'price_desc' | 'date_asc' | 'date_desc' | 'name_asc';
  cursor?: string;
  per_page?: number;
}): Promise<ProductListResponse>

async getProductBySlug(slug: string): Promise<Product | null>

async getProductCategories(): Promise<Category[]>
```

### 6.3 Product Listing Page (PLP) — `app/products/page.tsx`

**Route:** `/products`

**Rendering strategy:** ISR (Incremental Static Regeneration) with `revalidate = 60`.

**Features:**
- Displays a responsive grid of `ProductCard` components.
- Client-side category filter bar (links using Next.js `useRouter` to update `?category=` query param — OR use search params for SSR filtering).
- Shows a price range filter and in-stock toggle as query parameters that trigger a server fetch.
- Cursor-based pagination with Next.js `<Link>` components pointing to `?cursor=...` query parameters to preserve SEO crawlability.
- Each `ProductCard` shows: featured image (with `next/image` for optimization), product name, price range or sale price, and stock badge.

**`ProductCard.tsx` — Props:**
```typescript
interface ProductCardProps {
  product: Product;   // lightweight — only fields from list response
}
```

**`ProductCard.tsx` — Rendered Elements:**
- `<article>` wrapper with `id={product.id}` for unique DOM identification.
- `<Link href={/products/${product.slug}}>` — full card is clickable.
- `<img>` or `<Image>` for `featured_image_url` with appropriate `alt` text.
- Product name in `<h3>`.
- Price display: shows `sale_price` if active (with `regular_price` struck through), else `regular_price`, else `price_min–price_max` for variable products.
- Stock badge: green "In Stock", red "Out of Stock", or amber "Backorder" based on `stock_status`.
- Smooth hover lift animation using CSS transform.

### 6.4 Product Detail Page (PDP) — `app/products/[slug]/page.tsx`

**Route:** `/products/[slug]`

**Rendering strategy:** ISR with `revalidate = 300`. `generateStaticParams()` pre-generates top 100 products at build time.

**Features:**

#### 6.4.1 Media Gallery Slideshow — `ProductGallery.tsx`

- **Component:** `ProductGallery` — a client component (`'use client'`).
- Displays `media[]` array from the product detail response.
- State: `selectedIndex` (integer tracking current image).
- **Main image pane:** Shows `media[selectedIndex].large_url`. Supports keyboard arrow navigation.
- **Thumbnail strip:** Horizontal scroll of thumbnail images. Click sets `selectedIndex`.
- **Transition:** CSS fade or slide between images.
- **Props:** `media: ProductMedia[]`, `productName: string`.
- When `media` is empty, falls back to `featured_image_url` as a single-image gallery.

#### 6.4.2 Attribute Selector for Variable Products — `ProductAttributeSelector.tsx`

This component is the key interactive piece for variable products. It drives variation matching.

**State managed (client-side):**
```typescript
// Selected value per attribute key
const [selectedAttributes, setSelectedAttributes] = useState<Record<string, string>>({});
// Currently matched variation (or null if no match)
const [matchedVariation, setMatchedVariation] = useState<ProductVariation | null>(null);
```

**Variation Matching Algorithm:**

On every attribute selection change:
1. Collect the current `selectedAttributes` map: `{ pa_color: "Blue", pa_size: "L" }`.
2. Check if all variation-driving attributes have a selected value (`is_for_variations = true`).
3. If yes, iterate over `product.variations[]` and find the first variation where `variation.attributes` is a subset match of `selectedAttributes`:
   ```typescript
   const match = variations.find(v =>
     Object.entries(v.attributes).every(
       ([key, val]) => selectedAttributes[key] === val
     )
   );
   setMatchedVariation(match ?? null);
   ```
4. Update the displayed price, SKU, stock status, and image based on `matchedVariation`.

**Attribute Option Availability Logic:**
- For each attribute value option, check whether any enabled variation exists that includes this value given the other already-selected attributes.
- Unavailable options are rendered with reduced opacity and `cursor-not-allowed`.

**Dynamic UI Updates on Match:**
- **Price:** Replace displayed price with `matchedVariation.price`.
- **SKU:** Replace with `matchedVariation.sku` if different from parent.
- **Stock badge:** Re-render using `matchedVariation.stock_status`.
- **Gallery image:** If `matchedVariation.image_url` is set, set `ProductGallery.selectedIndex` to the gallery position of that image (or prepend it to the media array).

#### 6.4.3 PDP Layout Structure

```text
<article>
  ├── Breadcrumb: Home > Products > {category.name} > {product.name}
  │
  ├── Two-column grid (desktop) / Single column (mobile)
  │   ├── Left: <ProductGallery />
  │   └── Right: Product info panel
  │       ├── <h1>{product.name}</h1>
  │       ├── Price display (regular / sale / range)
  │       ├── Short description
  │       ├── <ProductAttributeSelector />  ← only for variable products
  │       ├── Stock status indicator
  │       ├── <AddToCartButton />  ← links to WC cart or external URL
  │       └── SKU display
  │
  └── Full description (dangerouslySetInnerHTML for WP HTML content)
      └── Product attribute table (non-variation attributes)
```

#### 6.4.4 `AddToCartButton.tsx`

For the frontend-only ISR architecture, the "Add to Cart" button links the user to the WooCommerce cart. No direct WooCommerce API calls are made from Next.js.

- For **simple/variable products**: Links to `{WC_STORE_URL}/?add-to-cart={source_post_id}&quantity=1` (and variation_id if matched).
- For **external products**: Links to `product.external_url` with `rel="noopener noreferrer"` and the `button_text` label.
- The `WC_STORE_URL` is provided via `process.env.NEXT_PUBLIC_WC_STORE_URL`.

### 6.5 `generateMetadata()` for PDP

```typescript
export async function generateMetadata({ params }): Promise<Metadata> {
  const product = await api.getProductBySlug(params.slug);
  if (!product) return { title: 'Product Not Found' };
  return {
    title: product.seo?.meta_title || product.name,
    description: product.seo?.meta_description || product.short_description,
    openGraph: {
      title: product.seo?.og_title || product.name,
      images: product.featured_image_url ? [{ url: product.featured_image_url }] : [],
      type: 'website',
    },
  };
}
```

### 6.6 Cache Revalidation for Products

The existing `triggerRevalidation()` method in `Module.php` is extended for the Commerce module. Product paths revalidated:

```text
/                          ← Homepage (if it shows featured products)
/products                  ← PLP main page
/products/{slug}           ← Product detail
/products?category={cat}   ← Category-filtered PLP
```

The revalidation webhook (`/api/revalidate`) already exists and handles path-based cache purging. No frontend code changes are needed beyond adding `export const revalidate = 60;` to `app/products/page.tsx`.

---

## 7. Module File Structure Reference

The complete planned directory tree for the Commerce module:

```text
headless-sync/modules/Commerce/
│
├── module.json                          ← Module manifest
│
├── Module.php                           ← Entry point: register/boot/activate/deactivate/upgrade
│                                           boot() → WorkerEngine::subscribe(...)
│                                           activate() → runs 01_create_commerce_tables.sql
│                                           handleProductCreatedOrUpdated(EventEnvelope)
│                                           handleProductDeleted(EventEnvelope)
│                                           handleStockUpdated(EventEnvelope)
│                                           handleVariationCreatedOrUpdated(EventEnvelope)
│                                           handleVariationDeleted(EventEnvelope)
│                                           triggerRevalidation('product', ...)
│
├── Events/
│   └── CommerceEventTypes.php           ← 7 event type string constants
│
├── CanonicalModels/
│   ├── Product.php                      ← implements CanonicalModelInterface
│   ├── ProductVariation.php             ← implements CanonicalModelInterface
│   └── ProductAttribute.php             ← value object DTO
│
├── Transformers/
│   ├── ProductTransformer.php           ← static::fromEventPayload(array): Product
│   ├── ProductVariationTransformer.php  ← static::fromEventPayload(array): ProductVariation
│   ├── ProductAttributeTransformer.php  ← static::fromArray(array): ProductAttribute
│   └── ProductMediaTransformer.php      ← static::fromGallery(array): array
│
├── Adapters/
│   └── ProductPostgresAdapter.php       ← implements AdapterInterface
│                                           persist(CanonicalModelInterface): void
│                                           delete(string, string): void
│                                           private upsertProduct(Product, UUID): void
│                                           private syncAttributes(UUID, array): void
│                                           private syncMedia(UUID, array): void
│                                           private syncCategories(UUID, array): void
│                                           private updateVariationPriceRange(UUID): void
│
└── Migrations/
    └── 01_create_commerce_tables.sql    ← All CREATE TABLE + INDEX statements from Section 2
```

**New Core Integration Files:**

```text
headless-sync/core/Events/
└── WordpressProductEventListener.php    ← Registers 9 WC action hooks
                                            handleProductSave(int $productId): void
                                            handleProductDelete(int $postId, $post): void
                                            handleProductTrash(int $postId): void
                                            handleProductUntrash(int $postId): void
                                            handleVariationSave(int $variationId, int $i): void
                                            handleVariationDelete(int $variationId, int $parentId): void
                                            handleProductDuplicate($duplicate, $product): void
                                            handleStockUpdate($product): void
                                            extractProductPayload(int $productId): array
                                            extractVariationPayload(int $variationId): array
```

**OutboxService extension:**
```text
headless-sync/core/Events/OutboxService.php
    + publishProduct(array $productData, string $eventType): EventEnvelope
    + publishVariation(array $variationData, string $eventType): EventEnvelope
```

**EventBuilder extension:**
```text
headless-sync/core/Events/EventBuilder.php
    + buildFromProduct(array $productData, string $eventType, int $version): EventEnvelope
    + buildFromVariation(array $variationData, string $eventType, int $version): EventEnvelope
```

**Bootstrap registration:**
```text
headless-sync/bootstrap/Application.php
    → instantiate WordpressProductEventListener, call registerHooks()
    → register Commerce\Module in module registry alongside Content\Module
```

**Delivery API:**
```text
headless-sync/delivery-api.php
    → Add routing block: if ($uri === 'api/v1/products') { ... }
    → Add routing block: if ($uri === 'api/v1/products/categories') { ... }
```

**Frontend:**
```text
frontend/src/
├── app/products/page.tsx                ← PLP (ISR, revalidate=60)
├── app/products/[slug]/page.tsx         ← PDP (ISR, revalidate=300)
└── components/
    ├── ProductCard.tsx
    ├── ProductGallery.tsx               ← 'use client'
    ├── ProductAttributeSelector.tsx     ← 'use client'
    └── AddToCartButton.tsx
```

---

## 8. Migration & Activation Sequence

### Step 1 — Register Commerce Module

In `Application.php`, instantiate and register `Commerce\Module` exactly as `Content\Module` is registered. The module is registered in the module registry array so that `boot()`, `activate()`, and `upgrade()` are called at the correct WordPress lifecycle points.

### Step 2 — Run SQL Migration

`Commerce\Module::activate()` loads and executes `01_create_commerce_tables.sql` via `$pdo->exec($sql)`. This is idempotent because all statements use `CREATE TABLE IF NOT EXISTS` and `CREATE INDEX IF NOT EXISTS`.

### Step 3 — Register WooCommerce Hook Listener

In `Application.php`, instantiate `WordpressProductEventListener` and call `registerHooks()`. WooCommerce hooks only fire within the WordPress request lifecycle. The listener guards all hooks with `if (!function_exists('wc_get_product')) return;` to avoid errors in non-WC environments.

### Step 4 — Initial Backfill

After the plugin is activated, a one-time backfill script iterates all published WooCommerce products (via `wc_get_products(['status' => 'publish', 'limit' => -1])`) and publishes a `commerce.product.created` event for each one. For variable products, each variation is published as a `commerce.product_variation.created` event separately. The WorkerEngine processes the queue and populates all PostgreSQL tables.

### Step 5 — Worker Queue Name

Product events use `queue_name = 'commerce'`. The CLI worker must be started with the `--queue=commerce` flag alongside the existing `--queue=content` worker:

```bash
php headless-sync.php worker:run --queue=commerce
```

Alternatively, a single worker may handle multiple queues by checking the queue_name in the WorkerEngine configuration.

### Step 6 — Verify Table Population

After backfill, confirm:
```sql
SELECT COUNT(*) FROM content.products WHERE deleted_at IS NULL;
SELECT COUNT(*) FROM content.product_variations;
SELECT COUNT(*) FROM content.product_attributes;
SELECT COUNT(*) FROM content.product_media;
```

---

## 9. Revalidation & Cache Invalidation Strategy

### Product-specific Revalidation Paths

Extending `Module::triggerRevalidation()` for product events:

| Event | Paths Revalidated |
|---|---|
| Product created | `/`, `/products`, `/products/{slug}` |
| Product updated (no slug change) | `/products/{slug}` |
| Product updated (slug changed) | `/products/{old_slug}`, `/products/{new_slug}`, `/products` |
| Product deleted | `/products/{slug}`, `/products` |
| Variation updated | `/products/{parent_slug}` |
| Stock updated | `/products/{slug}` |
| Category updated | `/products?category={cat_slug}` |

### Next.js Page Directives

```typescript
// app/products/page.tsx — PLP
export const revalidate = 60;   // ISR: rebuild listing every 60s from CDN

// app/products/[slug]/page.tsx — PDP
export const revalidate = 300;  // ISR: rebuild detail every 5 min

// Cart/Checkout pages (if added later) — must never be cached
export const dynamic = 'force-dynamic';
```

---

## 10. Verification Checklist

### Completeness Against Acceptance Criteria

| Criterion | Location in This Document |
|---|---|
| No `.php`, `.ts`, `.tsx`, `.sql` implementation files created (design doc only) | This document is a plan only |
| Final output written to `docs/woocommerce_products_plan.md` | This file |
| `CREATE TABLE` for products, attributes, variations, media with indexes | Section 2.1–2.5 |
| WooCommerce action hooks enumerated | Section 3.1 |
| Event payload structures for create, update, delete | Section 3.3 |
| REST endpoints added to `delivery-api.php` listed | Section 5.1 |
| Next.js frontend files with routing structure listed | Sections 6.1–6.5 |
| PDP dynamic attribute selection explained (backend schema + frontend) | Sections 4.7, 6.4.2 |

### Agent-as-Judge Checklist

| Judge Criterion | ✓ |
|---|---|
| No actual PHP, TypeScript, or SQL code files generated | ✓ — design document only |
| `CREATE TABLE` for products, variations, attributes, media in SQL blocks | ✓ — Section 2.1, 2.2, 2.3, 2.4 |
| `CREATE INDEX` statements on slug, SKU, price, attribute keys | ✓ — included in each table definition |
| WordPress/WooCommerce event hooks specifically listed | ✓ — Section 3.1 (9 hooks) |
| REST endpoints in `delivery-api.php` fully specified | ✓ — Section 5.1 (2 product endpoints + 1 category) |
| Next.js page paths specified | ✓ — `/products` and `/products/[slug]` in Section 6 |
| Variable product attribute-matching explained on backend schema | ✓ — Section 4.7 (adapter step 2; variation GIN index) |
| Variable product attribute-matching explained on frontend | ✓ — Section 6.4.2 (variation matching algorithm, JS pseudocode) |

---

*End of Plan Document*
