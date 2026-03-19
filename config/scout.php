<?php

return [
    'driver' => env('SCOUT_DRIVER', 'database'),
    'prefix' => env('SCOUT_PREFIX', ''),
    'queue'  => env('SCOUT_QUEUE', false),
    'chunk'  => [
        'searchable'   => 500,
        'unsearchable' => 500,
    ],
    'soft_delete' => false,
    'identify'    => false,
    'algolia'     => ['id' => env('ALGOLIA_APP_ID', ''), 'secret' => env('ALGOLIA_SECRET', '')],
    'meilisearch' => ['host' => env('MEILISEARCH_HOST', 'http://localhost:7700'), 'key' => env('MEILISEARCH_KEY', null), 'index-settings' => []],
    'typesense'   => ['client-settings' => ['api_key' => env('TYPESENSE_API_KEY', 'xyz'), 'nodes' => [['host' => env('TYPESENSE_HOST', 'localhost'), 'port' => env('TYPESENSE_PORT', '8108'), 'protocol' => env('TYPESENSE_PROTOCOL', 'http')]]], 'model-settings' => []],
];
