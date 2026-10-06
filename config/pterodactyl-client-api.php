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

    'transfer' => [
        // Require POST /servers/{server}/transfer to carry a relay_url pointing at the local
        // wings-ops-agent relay. With this on, the transfer JWT is only ever sent to
        // http://127.0.0.1:{relay_port}/relay/... on the source node. Turning it off lets the
        // endpoint fall back to the panel's own URL (the target node's public address).
        'require_relay' => (bool) env('CLIENT_API_TRANSFER_REQUIRE_RELAY', true),

        // The loopback port the agent relay listens on. Kept below 1024 so an unprivileged
        // process on the node cannot bind it while the agent is down.
        'relay_port' => (int) env('CLIENT_API_TRANSFER_RELAY_PORT', 781),

        // Timeout (seconds) for the call that tells the source Wings to start the transfer.
        // Wings stops the server synchronously (up to 15s) before answering, so the panel's
        // default 15s Guzzle timeout is too short.
        'notifier_timeout' => (int) env('CLIENT_API_TRANSFER_NOTIFIER_TIMEOUT', 60),

        // Timeout (seconds) for the GET /api/servers/{uuid} probes made by transfer/cancel.
        'probe_timeout' => (int) env('CLIENT_API_TRANSFER_PROBE_TIMEOUT', 10),

        // Minimum age (seconds) of a pending transfer before transfer/cancel accepts it. The
        // transfer JWT is valid for 15 minutes, so until it expires the source Wings (or a
        // retried relay) could still open a stream to the target after the cancel. Default:
        // 15 min JWT lifetime + 2 min clock skew between panel and nodes = 1020 s. Lower it only
        // if you accept that risk.
        'delete_min_age_seconds' => (int) env('CLIENT_API_TRANSFER_DELETE_MIN_AGE_SECONDS', 1020),
    ],
];
