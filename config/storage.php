<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenant S3 Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Extends Laravel's default filesystems.php with tenant-aware S3 settings.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | S3 Bucket Configuration
    |--------------------------------------------------------------------------
    */
    's3' => [
        'bucket'          => env('AWS_BUCKET', ''),
        'region'          => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'url'             => env('AWS_URL', ''),
        'endpoint'        => env('AWS_ENDPOINT'), // MinIO endpoint for local dev
        'use_path_style'  => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        'encryption'      => 'AES256',            // Server-side encryption
        'visibility'      => 'private',            // Never publicly accessible
    ],

    /*
    |--------------------------------------------------------------------------
    | CDN Configuration
    |--------------------------------------------------------------------------
    */
    'cdn' => [
        'enabled'        => env('CDN_ENABLED', false),
        'url'            => env('CDN_URL', ''),
        'cache_control'  => [
            'books'   => 'max-age=86400, public',
            'covers'  => 'max-age=604800, public',
            'avatars' => 'max-age=86400, public',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Size Limits
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'book_file_mb'   => env('MAX_BOOK_FILE_MB', 100),
        'audio_file_mb'  => env('MAX_AUDIO_FILE_MB', 300),
        'image_mb'       => env('MAX_IMAGE_MB', 5),
        'avatar_mb'      => env('MAX_AVATAR_MB', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME Types
    |--------------------------------------------------------------------------
    */
    'allowed_mimes' => [
        'books' => [
            'application/pdf',
            'application/epub+zip',
            'application/x-mobipocket-ebook',
            'image/vnd.djvu',
            'text/plain',
        ],
        'audio' => [
            'audio/mpeg',
            'audio/mp4',
            'audio/ogg',
            'audio/aac',
            'audio/x-m4a',
        ],
        'images' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed File Extensions
    |--------------------------------------------------------------------------
    */
    'allowed_extensions' => [
        'books' => ['pdf', 'epub', 'mobi', 'djvu', 'fb2', 'txt'],
        'audio' => ['mp3', 'm4a', 'ogg', 'aac'],
        'images'=> ['jpg', 'jpeg', 'png', 'webp', 'gif'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Signed URL TTLs (seconds)
    |--------------------------------------------------------------------------
    */
    'signed_url_ttl' => [
        'download'  => env('SIGNED_URL_DOWNLOAD_TTL', 3600),    // 1 hour
        'streaming' => env('SIGNED_URL_STREAM_TTL', 900),        // 15 minutes
        'avatar'    => env('SIGNED_URL_AVATAR_TTL', 86400),      // 24 hours
    ],

    /*
    |--------------------------------------------------------------------------
    | Virus Scanning
    |--------------------------------------------------------------------------
    */
    'virus_scan_enabled' => env('VIRUS_SCAN_ENABLED', false),
    'virus_scan_command' => env('VIRUS_SCAN_COMMAND', 'clamscan --no-summary'),

    /*
    |--------------------------------------------------------------------------
    | Image Processing
    |--------------------------------------------------------------------------
    */
    'image_processing' => [
        'thumbnail_width'  => 300,
        'thumbnail_height' => 0,    // Auto height (maintain aspect ratio)
        'cover_width'      => 600,
        'cover_quality'    => 85,
        'format'           => 'webp',
    ],

    /*
    |--------------------------------------------------------------------------
    | Temp Storage
    |--------------------------------------------------------------------------
    */
    'temp' => [
        'disk'       => 'local',
        'path'       => storage_path('app/temp'),
        'max_age_hours' => 24,  // Cleanup files older than this
    ],

    /*
    |--------------------------------------------------------------------------
    | S3 Lifecycle Configuration
    |--------------------------------------------------------------------------
    |
    | Rules applied to the S3 bucket for automatic file management.
    |
    */
    'lifecycle_rules' => [
        [
            'id'     => 'cleanup-temp',
            'prefix' => 'tenants/*/temp/',
            'expiry_days' => 1,
        ],
        [
            'id'     => 'archive-exports',
            'prefix' => 'platform/exports/',
            'transition_days' => 30,
            'storage_class'   => 'STANDARD_IA',
            'expiry_days'     => 365,
        ],
    ],

];
