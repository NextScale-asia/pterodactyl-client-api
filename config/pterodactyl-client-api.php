<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Pterodactyl Client API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for the Pterodactyl Client API addon
    |
    */

    'version' => '1.0.0',
    
    // API Key settings
    'api_key' => [
        'max_keys_per_user' => 10,
        'default_permissions' => [
            'user.read',
            'server.read',
        ],
    ],
    
    // Free allocation settings
    'allocations' => [
        'show_free_only' => true,
        'include_node_info' => true,
    ],
];