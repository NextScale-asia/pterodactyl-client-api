<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

use Illuminate\Validation\Validator;

/**
 * PATCH /eggs/{egg}: only the fields that do not change what runs on a node. Startup, images,
 * config and scripts go through update-import (preview + fingerprint) instead.
 */
class UpdateEggRequest extends EggWriteRequest
{
    public const FIELDS = ['name', 'description', 'force_outgoing_ip', 'script_is_privileged'];

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|min:1|max:191',
            'description' => 'sometimes|nullable|string',
            'force_outgoing_ip' => 'sometimes|boolean',
            'script_is_privileged' => 'sometimes|boolean',
            'expected_fingerprint' => 'required|string|size:64',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (array_diff(array_keys($this->validationData()), [...self::FIELDS, 'expected_fingerprint']) as $key) {
                $validator->errors()->add($key, "The {$key} field cannot be changed here; use update-import.");
            }
        });
    }
}
