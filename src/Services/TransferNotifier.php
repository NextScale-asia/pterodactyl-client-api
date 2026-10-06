<?php

namespace Byzic\PterodactylClientApi\Services;

use Lcobucci\JWT\UnencryptedToken;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Illuminate\Http\Client\ConnectionException;
use Byzic\PterodactylClientApi\Exceptions\WingsRejectedTransferException;

/**
 * Sends the source Wings the same request as the panel's DaemonTransferRepository::notify(),
 * field for field, except that `url` is supplied by the caller (the agent relay).
 *
 * Wings answers 202 only after it has stopped the server and started streaming in a
 * goroutine; any 4xx/500 means nothing was started. A timeout or a network error is
 * different: Wings may have received the request and be streaming already, so the caller
 * must not roll back.
 */
class TransferNotifier extends WingsHttp
{
    public const STARTED = 'started';

    public const UNCERTAIN = 'uncertain';

    /**
     * @return string self::STARTED or self::UNCERTAIN
     *
     * @throws WingsRejectedTransferException when Wings explicitly refused the request
     */
    public function notify(Server $server, Node $source, string $url, UnencryptedToken $token): string
    {
        try {
            $response = $this->client($source, (int) config('pterodactyl-client-api.transfer.notifier_timeout', 60))
                ->post(sprintf('/api/servers/%s/transfer', $server->uuid), [
                    'server_id' => $server->uuid,
                    'url' => $url,
                    'token' => 'Bearer ' . $token->toString(),
                    'server' => [
                        'uuid' => $server->uuid,
                        'start_on_completion' => false,
                    ],
                ]);
        } catch (ConnectionException) {
            return self::UNCERTAIN;
        }

        if ($response->successful()) {
            return self::STARTED;
        }

        if (self::isExplicitRejection($response->status())) {
            throw new WingsRejectedTransferException($response->status());
        }

        // 3xx (never followed, see WingsHttp), or a 5xx a proxy/tunnel in front of Wings could have produced while Wings
        // itself accepted the request (502/503/504, Cloudflare 52x).
        return self::UNCERTAIN;
    }

    /**
     * Wings itself only answers 202, 4xx (bad request, auth, 409 already transferring) or
     * 500 (failed to stop the server). Those are certain refusals; other 5xx are not.
     */
    public static function isExplicitRejection(int $status): bool
    {
        return $status >= 400 && $status <= 500;
    }
}
