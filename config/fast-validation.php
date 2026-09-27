<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Validation Mode
    |--------------------------------------------------------------------------
    |
    | 'parallel' => use FastValidator as a separate, opt-in API.
    | 'override' => route Laravel's Validator::make() through the fast engine.
    |
    */
    'mode' => 'parallel',

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configure schema caching to improve performance by storing compiled
    | validation schemas.
    |
    */
    'cache' => [
        'enabled' => env('FAST_VALIDATION_CACHE', true),
        'driver' => env('FAST_VALIDATION_CACHE_DRIVER', 'array'), // array, file
        'ttl' => env('FAST_VALIDATION_CACHE_TTL', 3600),
        'path' => storage_path('framework/validation/cache'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compilation Configuration
    |--------------------------------------------------------------------------
    |
    | Configure precompilation of validation schemas for production use.
    |
    */
    'compilation' => [
        'precompile' => env('FAST_VALIDATION_PRECOMPILE', false),
        'cache_path' => storage_path('framework/validation/compiled'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | Secret used to HMAC-sign file-cached schemas and generated native
    | validators, so files planted in the cache directories by anyone without
    | the secret are never unserialized or required. Defaults to APP_KEY when
    | left null. See docs/native-compilation.md for the trust model.
    |
    */
    'security' => [
        'signing_key' => env('FAST_VALIDATION_SIGNING_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Options
    |--------------------------------------------------------------------------
    |
    | Fine-tune validation performance behavior.
    |
    */
    'performance' => [
        // Stop validation on first error for faster responses
        'fail_fast' => env('FAST_VALIDATION_FAIL_FAST', false),
        
        // Maximum number of errors to collect before stopping
        'max_errors' => env('FAST_VALIDATION_MAX_ERRORS', 100),
        
        // Enable optimized fast-path rules for common validations
        'fast_path_rules' => env('FAST_VALIDATION_FAST_PATH', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Localization
    |--------------------------------------------------------------------------
    |
    | Configure message translation and localization options.
    |
    */
    'localization' => [
        'locale' => env('FAST_VALIDATION_LOCALE', 'en'),
        'fallback_locale' => env('FAST_VALIDATION_FALLBACK_LOCALE', 'en'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Long-running Process Support
    |--------------------------------------------------------------------------
    |
    | Configuration for Octane, Swoole, and RoadRunner environments.
    |
    */
    'runtime' => [
        // Enable validator instance pooling
        'pooling' => env('FAST_VALIDATION_POOLING', false),
        
        // Pool size for long-running processes
        'pool_size' => env('FAST_VALIDATION_POOL_SIZE', 10),
        
        // Auto-detect and optimize for long-running environments
        'auto_detect' => env('FAST_VALIDATION_AUTO_DETECT', true),
    ],
];
