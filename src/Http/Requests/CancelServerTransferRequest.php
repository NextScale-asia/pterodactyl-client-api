<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Http\Requests\Api\Application\ApplicationApiRequest;

/**
 * Marks a dead transfer as failed (POST /servers/{server}/transfer/cancel).
 *
 * `confirm_agents_idle` is a caller ATTESTATION, not something the panel verifies: the caller
 * states that it has checked both nodes' agents and neither is relaying this server any more.
 * Wings' API cannot tell whether the source node is still streaming, so the panel cannot
 * check that part itself. The controller additionally refuses until the transfer JWT has
 * expired (`transfer.delete_min_age_seconds`), so no new stream can start afterwards.
 */
class CancelServerTransferRequest extends ApplicationApiRequest
{
    protected ?string $resource = AdminAcl::RESOURCE_SERVERS;

    // Same level as the panel's own state-changing server requests.
    protected int $permission = AdminAcl::WRITE;

    public function rules(): array
    {
        return [
            'confirm_agents_idle' => 'required|accepted',
        ];
    }
}
