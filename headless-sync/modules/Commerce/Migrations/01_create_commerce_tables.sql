-- ============================================================================
-- Commerce Module — Migration 01
-- Defines schema structures for products, variations, attributes, and media
-- Version: 1.0.0
-- ============================================================================

CREATE TABLE IF NOT EXISTS content.products (
    id                      UUID PRIMARY KEY,
    source_post_id          VARCHAR(50)  NOT NULL UNIQUE,
    source_entity_type      VARCHAR(50)  NOT NULL DEFAULT 'product',
    product_type            VARCHAR(30)  NOT NULL DEFAULT 'simple',
    slug                    VARCHAR(400) NOT NULL,
    name                    TEXT         NOT NULL,
    description             TEXT,
    short_description       TEXT,
    status                  VARCHAR(50)  NOT NULL DEFAULT 'publish',
    regular_price           NUMERIC(14, 4),
    sale_price              NUMERIC(14, 4),
    price                   NUMERIC(14, 4),
    price_min               NUMERIC(14, 4),
    price_max               NUMERIC(14, 4),
    sku                     VARCHAR(200),
    manage_stock            BOOLEAN      NOT NULL DEFAULT FALSE,
    stock_quantity          INTEGER,
    stock_status            VARCHAR(30)  NOT NULL DEFAULT 'instock',
    backorders_allowed      BOOLEAN      NOT NULL DEFAULT FALSE,
    external_url            TEXT,
    button_text             VARCHAR(200),
    grouped_product_ids     JSONB,
    category_ids            JSONB,
    tag_ids                 JSONB,
    featured_image_url      TEXT,
    weight                  NUMERIC(10, 4),
    dimensions              JSONB,
    seo                     JSONB,
    aggregate_version       INTEGER      NOT NULL DEFAULT 0,
    created_at              TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at              TIMESTAMP WITH TIME ZONE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_products_slug
    ON content.products (slug)
    WHERE deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_products_status_price
    ON content.products (status, price)
    WHERE deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_products_status_created
    ON content.products (status, created_at DESC)
    WHERE deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_products_sku
    ON content.products (sku)
    WHERE sku IS NOT NULL AND deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_products_type
    ON content.products (product_type)
    WHERE deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_products_category_ids
    ON content.products USING GIN (category_ids);

CREATE INDEX IF NOT EXISTS idx_products_tag_ids
    ON content.products USING GIN (tag_ids);

-- ----------------------------------------------------------------------------
-- content.product_attributes
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS content.product_attributes (
    id                  UUID PRIMARY KEY,
    product_id          UUID NOT NULL REFERENCES content.products (id) ON DELETE CASCADE,
    attribute_key       VARCHAR(200) NOT NULL,
    attribute_label     VARCHAR(200) NOT NULL,
    attribute_type      VARCHAR(20)  NOT NULL DEFAULT 'custom',
    values              JSONB        NOT NULL,
    is_visible          BOOLEAN      NOT NULL DEFAULT TRUE,
    is_for_variations   BOOLEAN      NOT NULL DEFAULT FALSE,
    position            SMALLINT     NOT NULL DEFAULT 0,
    created_at          TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_product_attributes_product_id
    ON content.product_attributes (product_id);

CREATE INDEX IF NOT EXISTS idx_product_attributes_key
    ON content.product_attributes (attribute_key);

CREATE INDEX IF NOT EXISTS idx_product_attributes_values
    ON content.product_attributes USING GIN (values);

CREATE INDEX IF NOT EXISTS idx_product_attributes_product_key
    ON content.product_attributes (product_id, attribute_key);

-- ----------------------------------------------------------------------------
-- content.product_variations
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS content.product_variations (
    id                      UUID PRIMARY KEY,
    product_id              UUID NOT NULL REFERENCES content.products (id) ON DELETE CASCADE,
    source_variation_id     VARCHAR(50) NOT NULL UNIQUE,
    regular_price           NUMERIC(14, 4),
    sale_price              NUMERIC(14, 4),
    price                   NUMERIC(14, 4),
    sku                     VARCHAR(200),
    manage_stock            BOOLEAN      NOT NULL DEFAULT FALSE,
    stock_quantity          INTEGER,
    stock_status            VARCHAR(30)  NOT NULL DEFAULT 'instock',
    backorders_allowed      BOOLEAN      NOT NULL DEFAULT FALSE,
    image_url               TEXT,
    attributes              JSONB        NOT NULL DEFAULT '{}',
    description             TEXT,
    is_enabled              BOOLEAN      NOT NULL DEFAULT TRUE,
    menu_order              INTEGER      NOT NULL DEFAULT 0,
    aggregate_version       INTEGER      NOT NULL DEFAULT 0,
    created_at              TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_product_variations_product_id
    ON content.product_variations (product_id);

CREATE INDEX IF NOT EXISTS idx_product_variations_sku
    ON content.product_variations (sku)
    WHERE sku IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_product_variations_product_price
    ON content.product_variations (product_id, price);

CREATE INDEX IF NOT EXISTS idx_product_variations_attributes
    ON content.product_variations USING GIN (attributes);

-- ----------------------------------------------------------------------------
-- content.product_media
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS content.product_media (
    id                  UUID PRIMARY KEY,
    product_id          UUID NOT NULL REFERENCES content.products (id) ON DELETE CASCADE,
    source_attachment_id VARCHAR(50),
    url                 TEXT NOT NULL,
    thumbnail_url       TEXT,
    medium_url          TEXT,
    large_url           TEXT,
    alt_text            TEXT,
    caption             TEXT,
    position            SMALLINT     NOT NULL DEFAULT 0,
    is_featured         BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at          TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_product_media_product_position
    ON content.product_media (product_id, position);

CREATE INDEX IF NOT EXISTS idx_product_media_featured
    ON content.product_media (product_id, is_featured)
    WHERE is_featured = TRUE;

-- ----------------------------------------------------------------------------
-- content.product_categories
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS content.product_categories (
    product_id      UUID REFERENCES content.products(id)    ON DELETE CASCADE,
    taxonomy_id     UUID REFERENCES content.taxonomies(id)  ON DELETE CASCADE,
    PRIMARY KEY (product_id, taxonomy_id)
);

CREATE INDEX IF NOT EXISTS idx_product_categories_taxonomy
    ON content.product_categories (taxonomy_id);
