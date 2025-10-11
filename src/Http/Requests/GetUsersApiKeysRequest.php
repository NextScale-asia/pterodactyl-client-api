<?php

namespace Xepare\PterodactylApiAddon\Http\Requests;

use Pterodactyl\Http\Requests\Api\Application\ApplicationApiRequest;
use Pterodactyl\Services\Acl\Api\AdminAcl as Acl;

/**
 * Class GetUsersApiKeysRequest
 * 
 * Request for retrieving user API keys via Application API.
 * Requires READ permission on USERS resource.
 */
class GetUsersApiKeysRequest extends ApplicationApiRequest
{
    /**
     * @var string
     */
    protected ?string $resource = Acl::RESOURCE_USERS;

    /**
     * @var int
     */
    protected int $permission = Acl::READ;

    /**
     * Rules for query parameters.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'per_page' => 'sometimes|integer|min:1|max:100',
        ];
    }
}
