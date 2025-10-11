<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

use Pterodactyl\Http\Requests\Api\Application\ApplicationApiRequest;
use Pterodactyl\Services\Acl\Api\AdminAcl as Acl;

/**
 * Class DeleteUserApiKeyRequest
 * 
 * Request for deleting user API keys via Application API.
 * Requires DELETE permission on USERS resource.
 */
class DeleteUserApiKeyRequest extends ApplicationApiRequest
{
    /**
     * @var string
     */
    protected ?string $resource = Acl::RESOURCE_USERS;

    /**
     * @var int
     */
    protected int $permission = Acl::DELETE;

    /**
     * Validation rules for the route parameters.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'user' => 'required|integer|exists:users,id',
            'identifier' => 'required|string|size:16',
        ];
    }

    /**
     * Custom error messages.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'user.exists' => 'The specified user does not exist.',
            'identifier.size' => 'The API key identifier must be exactly 16 characters.',
        ];
    }
}