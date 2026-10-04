<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

use Pterodactyl\Services\Acl\Api\AdminAcl;

class GetUsersApiKeysRequest extends UserApiKeyRequest
{
    protected int $permission = AdminAcl::READ;
}
