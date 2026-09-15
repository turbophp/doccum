<?php

declare(strict_types=1);

return [
    'version' => env('DOCCUM_VERSION', '0.1.0'),

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
