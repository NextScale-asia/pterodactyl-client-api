<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Http\Requests\Api\Application\ApplicationApiRequest;

class ExportEggRequest extends ApplicationApiRequest
{
    protected ?string $resource = AdminAcl::RESOURCE_EGGS;

    protected int $permission = AdminAcl::READ;
}
