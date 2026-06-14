<?php

/**
 * Content module configuration.
 *
 * This file returns a plain array consumed by the module at boot time.
 * It centralises table names, supported post types, and taxonomy types
 * so that they can be referenced without magic strings throughout the
 * Content module codebase.
 *
 * @return array<string, mixed>
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Content Schema Tables
    |--------------------------------------------------------------------------
    |
    | All PostgreSQL tables managed by the Content module, fully qualified
    | with the 'content' schema prefix.
    |
    */
    'tables' => [
        'content.posts',
        'content.pages',
        'content.taxonomies',
        'content.entity_taxonomies',
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported WordPress Post Types
    |--------------------------------------------------------------------------
    |
    | WordPress post types that the Content module will capture events for.
    | Any post type not listed here will be ignored by the capture hooks.
    |
    */
    'supported_post_types' => [
        'post',
        'page',
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported WordPress Taxonomies
    |--------------------------------------------------------------------------
    |
    | WordPress taxonomy types that the Content module will capture events for.
    |
    */
    'supported_taxonomies' => [
        'category',
    ],
];
