<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Http\Requests\Api\Application\ApplicationApiRequest;

/**
 * Body of POST /api/application/database-hosts. Same fields and limits as the admin form
 * (DatabaseHost::$validationRules), except that the password is required here: the panel
 * connects to the host with it before the row is kept.
 */
class StoreDatabaseHostRequest extends ApplicationApiRequest
{
    protected ?string $resource = AdminAcl::RESOURCE_DATABASE_HOSTS;

    protected int $permission = AdminAcl::WRITE;

    public function rules(): array
    {
        return [
            'name' => 'required|string|min:1|max:191',
            'host' => ['required', 'string', 'max:191', 'regex:/^[\w\-\.]+$/'],
            'port' => 'required|integer|between:1,65535',
            'username' => 'required|string|min:1|max:32',
            'password' => 'required|string|min:1|max:191',
            'node_id' => 'sometimes|nullable|integer|exists:nodes,id',
        ];
    }
}
