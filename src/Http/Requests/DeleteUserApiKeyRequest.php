<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

use Pterodactyl\Services\Acl\Api\AdminAcl;

/**
 * The user and identifier are route parameters: the user is resolved by route-model
 * binding and the identifier is constrained by the route pattern, so there are no
 * body rules here.
 */
class DeleteUserApiKeyRequest extends UserApiKeyRequest
{
    // AdminAcl has no DELETE level; the panel's own DeleteUserRequest uses WRITE too.
    protected int $permission = AdminAcl::WRITE;
}
