<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Http\Requests\Api\Application\ApplicationApiRequest;

class GetFreeAllocationsRequest extends ApplicationApiRequest
{
    protected ?string $resource = AdminAcl::RESOURCE_ALLOCATIONS;

    protected int $permission = AdminAcl::READ;

    public function rules(): array
    {
        return [
            'per_page' => 'sometimes|integer|min:1|max:' . config('pterodactyl-client-api.allocations.max_per_page', 100),
        ];
    }
}
