<?php

namespace Byzic\PterodactylClientApi\Services;

use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Illuminate\Http\Client\ConnectionException;

/**
 * Asks a node's Wings whether it knows a server (GET /api/servers/{uuid}).
 *
 * Wings' response has no "transferring" field, so this can only tell whether the server is
 * registered on that node. On the target node an incoming transfer registers the server
 * when the stream starts and unregisters it when the transfer fails, so "present" on the
 * target means the transfer may still be running (or has completed).
 */
class WingsServerProbe extends WingsHttp
{
    public const PRESENT = 'present';

    public const ABSENT = 'absent';

    public const UNREACHABLE = 'unreachable';

    public function check(Node $node, Server $server): string
    {
        try {
            $response = $this->client($node, (int) config('pterodactyl-client-api.transfer.probe_timeout', 10))
                ->get(sprintf('/api/servers/%s', $server->uuid));
        } catch (ConnectionException) {
            return self::UNREACHABLE;
        }

        // 2xx only. A 3xx (redirects are not followed, see WingsHttp) falls through to UNREACHABLE.
        if ($response->successful()) {
            return self::PRESENT;
        }

        // Only trust a 404 that carries Wings' own error body: a 404 from a proxy or tunnel
        // in front of the node says nothing about the server.
        if ($response->status() === 404 && is_string($response->json('error'))) {
            return self::ABSENT;
        }

        return self::UNREACHABLE;
    }
}
