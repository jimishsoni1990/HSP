-- ============================================================================
-- Content Module – Initial Schema Migration
-- Version: 1.0.0
-- Description: Creates the content schema and all projection tables for the
--              Posts, Pages, and Categories domain aggregates.
-- ============================================================================

CREATE SCHEMA IF NOT EXISTS content;

-- ----------------------------------------------------------------------------
-- Posts table – projected from WordPress 'post' post-type events.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS content.posts (
    id                 UUID PRIMARY KEY,
    source_post_id     VARCHAR(50) NOT NULL UNIQUE,
    source_entity_type VARCHAR(50) NOT NULL,
    slug               VARCHAR(200),
    title              TEXT,
    excerpt            TEXT,
    content            TEXT,
    status             VARCHAR(50),
    seo                JSONB,
    featured_image_url TEXT,
    created_at         TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    deleted_at         TIMESTAMP WITH TIME ZONE
);

-- ----------------------------------------------------------------------------
-- Taxonomies table – projected from WordPress category term events.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS content.taxonomies (
    id              UUID PRIMARY KEY,
    source_term_id  VARCHAR(50) NOT NULL UNIQUE,
    taxonomy_type   VARCHAR(50) NOT NULL,
    slug            VARCHAR(200),
    name            VARCHAR(200),
    description     TEXT,
    seo             JSONB,
    created_at      TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    deleted_at      TIMESTAMP WITH TIME ZONE
);

-- ----------------------------------------------------------------------------
-- Entity ↔ Taxonomy pivot table – links posts to their categories.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS content.entity_taxonomies (
    entity_id   UUID REFERENCES content.posts(id) ON DELETE CASCADE,
    taxonomy_id UUID REFERENCES content.taxonomies(id) ON DELETE CASCADE,
    PRIMARY KEY (entity_id, taxonomy_id)
);

-- ----------------------------------------------------------------------------
-- Pages table – projected from WordPress 'page' post-type events.
-- Separate table because pages have a different field set (no excerpt/content).
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS content.pages (
    id                 UUID PRIMARY KEY,
    source_post_id     VARCHAR(50) NOT NULL UNIQUE,
    source_entity_type VARCHAR(50) NOT NULL,
    slug               VARCHAR(200),
    title              TEXT,
    status             VARCHAR(50),
    seo                JSONB,
    created_at         TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    deleted_at         TIMESTAMP WITH TIME ZONE
);
