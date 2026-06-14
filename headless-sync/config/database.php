<?php

return [
    'default' => 'pgsql',
    'connections' => [
        'mysql' => [
            'host' => getenv('WORDPRESS_DB_HOST') ?: '127.0.0.1:3306',
            'database' => getenv('WORDPRESS_DB_NAME') ?: 'wordpress_db',
            'username' => getenv('WORDPRESS_DB_USER') ?: 'wordpress',
            'password' => getenv('WORDPRESS_DB_PASSWORD') ?: 'wordpress_pass',
            'charset' => 'utf8mb4',
        ],
        'pgsql' => [
            'host' => getenv('HSP_DB_HOST') ?: (getenv('PG_DB_HOST') ?: '127.0.0.1'),
            'port' => getenv('HSP_DB_PORT') ?: (getenv('PG_DB_PORT') ?: '5433'),
            'database' => getenv('HSP_DB_NAME') ?: (getenv('PG_DB_NAME') ?: 'hsp_delivery'),
            'username' => getenv('HSP_DB_USER') ?: (getenv('PG_DB_USER') ?: 'hsp_admin'),
            'password' => getenv('HSP_DB_PASSWORD') ?: (getenv('PG_DB_PASSWORD') ?: 'hsp_secret'),
            'schema' => 'system',
        ],
    ],
];
