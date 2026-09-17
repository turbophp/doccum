<?php

declare(strict_types=1);

return [
    'version' => env('DOCCUM_VERSION', '0.1.0'),

    // Whether the operator set APP_URL at all, as opposed to the framework
    // falling back to config/app.php's 'http://localhost'. Recorded here, in
    // config, because that is the one place env() is correct: a config cache
    // is built with .env present, so this keeps its answer once cached, while
    // a bare env('APP_URL') read from a provider would start returning null
    // and silently flip the fallback on for an operator who HAD set it.
    // ForceRootUrlFromRequest is the only consumer.
    'app_url_is_set' => env('APP_URL') !== null,

    // Never under storage/: that path lives inside the container image, not on
    // the data volume, so anything written there is lost on the next rebuild.
    'runtime_config_path' => env('DOCCUM_RUNTIME_CONFIG', '/data/runtime.json'),

    // Defaults for operator-editable settings. Rows in the `settings` table
    // override these at runtime; see app/Services/Settings.php. An empty
    // settings table must leave the application fully functional.
    //
    // NESTED, not flat dotted keys. Arr::get() tests the full path as one
    // literal key, then explodes on "." and walks segment by segment — it
    // never recombines segments. So a literal 'auth.public_signup' key is
    // unreachable via config('doccum.settings.auth.public_signup').
    // Settings::get('auth.public_signup') resolves against this nesting,
    // while `settings` table rows keep the flat dotted key as their `key`.
    'settings' => [
        'instance' => [
            'name' => 'doccum',
        ],
        'auth' => [
            'public_signup' => false,
            'default_role' => 'member',
        ],
        'directories' => [
            'auto_home' => true,
        ],
    ],

    'storage' => [
        'disk' => env('DOCCUM_DISK', 'documents'),
        'staging_prefix' => 'uploads',
        'files_prefix' => 'files',
        // Where docker/entrypoint.d/48-doccum-storage.sh generates embedded
        // MinIO's root credentials, and the endpoint to reach it at. Both are
        // overridden by an operator's own storage.* settings -- see
        // RuntimeConfigServiceProvider::boot().
        'embedded_env' => env('DOCCUM_EMBEDDED_ENV', '/data/minio.env'),
        'endpoint' => env('AWS_ENDPOINT', 'http://127.0.0.1:9000'),
    ],

    'extraction' => [
        // Below this many characters, a PDF is treated as scanned and sent to OCR.
        'scanned_pdf_threshold' => 100,
        'ocr_page_limit' => 50,
        'timeout_seconds' => 600,
    ],

    'retention' => [
        'purge_after_years' => null,
        'auto_purge' => false,
    ],
];
