<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Base Domain
    |--------------------------------------------------------------------------
    |
    | The base domain used for automatic subdomain generation.
    | Tenants with slug "library1" will have URL: library1.kutubxona.uz
    |
    */
    'base_domain' => env('APP_BASE_DOMAIN', 'kutubxona.uz'),

    /*
    |--------------------------------------------------------------------------
    | Tenant Resolution
    |--------------------------------------------------------------------------
    |
    | Order in which tenant is detected from the incoming request.
    | Available resolvers: header, jwt, subdomain, domain
    |
    */
    'resolvers' => [
        'header'    => true,     // X-Tenant-ID header
        'jwt'       => true,     // JWT "tid" claim
        'subdomain' => true,     // library1.kutubxona.uz
        'domain'    => true,     // Custom domain (my-library.school.edu)
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Cache TTL
    |--------------------------------------------------------------------------
    |
    | How long to cache tenant-by-domain lookups (seconds).
    | Critical for performance — called on every request.
    |
    */
    'cache_ttl' => env('TENANT_CACHE_TTL', 600),

    /*
    |--------------------------------------------------------------------------
    | Tenant Database Mode
    |--------------------------------------------------------------------------
    |
    | 'shared'    → All tenants share one DB (default, scalable)
    | 'dedicated' → Premium tenants can have dedicated DB connections
    |
    */
    'database_mode' => env('TENANT_DATABASE_MODE', 'shared'),

    /*
    |--------------------------------------------------------------------------
    | Dedicated Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Connection name used when switching to a tenant's dedicated DB.
    |
    */
    'dedicated_connection' => 'tenant_dedicated',

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    */
    'storage' => [
        'disk'           => env('FILESYSTEM_DISK', 's3'),
        'prefix_pattern' => 'tenants/{tenant_id}',
        'sub_folders'    => [
            'books',
            'audio',
            'images/authors',
            'images/publishers',
            'users',
            'temp',
        ],
        'temp_ttl_hours' => 24, // Lifecycle rule on S3
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Key Prefix Pattern
    |--------------------------------------------------------------------------
    |
    | All tenant-scoped Redis keys follow this pattern.
    | Example: tenant:42:books:popular
    |
    */
    'cache_prefix' => 'tenant:{tenant_id}',

    /*
    |--------------------------------------------------------------------------
    | Default Plan
    |--------------------------------------------------------------------------
    |
    | Default plan slug assigned to new tenants when no plan is specified.
    |
    */
    'default_plan' => env('DEFAULT_PLAN_SLUG', 'free'),

    /*
    |--------------------------------------------------------------------------
    | Trial Period (days)
    |--------------------------------------------------------------------------
    */
    'trial_days' => env('TENANT_TRIAL_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Default Limits (when no plan assigned)
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'max_users'      => 10,
        'max_books'      => 100,
        'storage_quota'  => 1073741824, // 1 GB
        'api_rate_limit' => 120,        // requests per minute
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Onboarding
    |--------------------------------------------------------------------------
    */
    'onboarding' => [
        'send_welcome_email' => true,
        'setup_storage'      => true,
        'create_sample_data' => env('TENANT_SAMPLE_DATA', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Suspended Tenant Behavior
    |--------------------------------------------------------------------------
    |
    | HTTP status code returned when a suspended tenant attempts access.
    |
    */
    'suspended_http_code' => 403,

    /*
    |--------------------------------------------------------------------------
    | Routes Excluded from Tenant Middleware
    |--------------------------------------------------------------------------
    |
    | These routes bypass tenant detection (e.g., platform-level health checks).
    |
    */
    'excluded_routes' => [
        'api/health',
    ],

];
