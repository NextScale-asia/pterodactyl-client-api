<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

use Pterodactyl\Services\Acl\Api\AdminAcl;

class NestWriteRequest extends EggWriteRequest
{
    protected ?string $resource = AdminAcl::RESOURCE_NESTS;

    public function rules(): array
    {
        if ($this->isMethod('DELETE')) {
            return [];
        }

        return [
            'name' => ($this->isMethod('POST') ? 'required' : 'sometimes') . '|string|min:1|max:191',
            'description' => 'sometimes|nullable|string',
        ];
    }
}
