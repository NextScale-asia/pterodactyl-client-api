<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Pterodactyl Client API Configuration
    |--------------------------------------------------------------------------
    */

    'api_key' => [
        // Maximum number of account API keys the Application API may create for one user.
        'max_keys_per_user' => (int) env('CLIENT_API_MAX_KEYS_PER_USER', 5),

        // Allow listing, creating and deleting keys of root admins. A key minted for an
        // admin grants full panel access, so this is off by default.
        'allow_admin_targets' => (bool) env('CLIENT_API_ALLOW_ADMIN_TARGETS', false),
    ],

    'allocations' => [
        // Upper bound for ?per_page on the free allocations endpoint.
        'max_per_page' => (int) env('CLIENT_API_ALLOCATIONS_MAX_PER_PAGE', 100),
    ],
];
