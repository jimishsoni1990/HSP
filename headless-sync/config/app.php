<?php

return [
    'name' => 'Headless Sync Platform',
    'env' => getenv('APP_ENV') ?: 'production',
    'debug' => (bool)(getenv('APP_DEBUG') ?: false),
    'modules' => [
        \HSP\Modules\Content\Module::class,
    ],
];
