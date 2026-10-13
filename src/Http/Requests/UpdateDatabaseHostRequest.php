<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

/**
 * Body of PATCH /api/application/database-hosts/{databaseHost}: every field is optional and
 * only the ones sent change. `node_id: null` unlinks the node; a missing or empty password
 * keeps the stored one.
 */
class UpdateDatabaseHostRequest extends StoreDatabaseHostRequest
{
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|min:1|max:191',
            'host' => ['sometimes', 'required', 'string', 'max:191', 'regex:/^[\w\-\.]+$/'],
            'port' => 'sometimes|required|integer|between:1,65535',
            'username' => 'sometimes|required|string|min:1|max:32',
            'password' => 'sometimes|nullable|string|max:191',
            'node_id' => 'sometimes|nullable|integer|exists:nodes,id',
        ];
    }
}
