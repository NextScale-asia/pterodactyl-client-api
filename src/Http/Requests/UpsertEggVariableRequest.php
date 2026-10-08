<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Http\Requests\Api\Application\ApplicationApiRequest;

/**
 * Body of PUT /api/application/eggs/{egg}/variables/{env}. The env name comes from the URL;
 * reserved names and the validation rules themselves are checked by the panel's own
 * VariableCreationService / VariableUpdateService.
 */
class UpsertEggVariableRequest extends ApplicationApiRequest
{
    protected ?string $resource = AdminAcl::RESOURCE_EGGS;

    protected int $permission = AdminAcl::WRITE;

    public function rules(): array
    {
        return [
            'name' => 'required|string|min:1|max:191',
            'description' => 'nullable|string',
            'default_value' => 'nullable|string',
            'user_viewable' => 'required|boolean',
            'user_editable' => 'required|boolean',
            'rules' => 'nullable|string',
        ];
    }
}
