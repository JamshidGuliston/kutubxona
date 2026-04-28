<?php

return [
    'paths'                    => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods'          => ['*'],
    'allowed_origins'          => ['https://sehrlikitoblar.uz', 'http://localhost:3000'],
    'allowed_origins_patterns' => [],
    'allowed_headers'          => ['*'],
    'exposed_headers'          => ['X-Request-ID'],
    'max_age'                  => 86400,
    'supports_credentials'     => false,
];
