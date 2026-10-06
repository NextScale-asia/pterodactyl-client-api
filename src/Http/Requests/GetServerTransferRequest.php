<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Http\Requests\Api\Application\ApplicationApiRequest;

class GetServerTransferRequest extends ApplicationApiRequest
{
    protected ?string $resource = AdminAcl::RESOURCE_SERVERS;

    protected int $permission = AdminAcl::READ;

    public function rules(): array
    {
        return [
            'verify' => 'sometimes|boolean',
        ];
    }
}
