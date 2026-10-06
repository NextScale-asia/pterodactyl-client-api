<?php

namespace Byzic\PterodactylClientApi\Services;

use Pterodactyl\Models\Node;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

/**
 * Builds an HTTP client for a node's Wings API with the same base URL, credentials and TLS
 * verification as the panel's DaemonRepository, but with a caller-chosen timeout.
 *
 * Redirects are never followed: Wings itself never redirects, so a 3xx comes from something
 * in front of it, and following it would resend the node's bearer token (and, for a notify,
 * the transfer JWT) to wherever the Location header points. Callers treat a 3xx as
 * "uncertain" (notify) or "unreachable" (probe).
 */
abstract class WingsHttp
{
    protected function client(Node $node, int $timeout): PendingRequest
    {
        return Http::baseUrl($node->getConnectionAddress())
            ->withOptions(['verify' => app()->environment('production')])
            ->withoutRedirecting()
            ->withToken($node->getDecryptedKey())
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->connectTimeout((int) config('pterodactyl.guzzle.connect_timeout', 5));
    }
}
