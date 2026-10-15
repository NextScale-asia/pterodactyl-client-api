<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

class RestoreServerVariablesRequest extends EggWriteRequest
{
    public const MAX_VALUES = 5000;

    public function rules(): array
    {
        $values = $this->validationData()['values'] ?? null;

        // Wildcard rules are expanded per item when the validator is built; refuse an oversized
        // list on its own rule before that happens.
        if (!is_array($values) || count($values) > self::MAX_VALUES) {
            return ['values' => 'required|array|min:1|max:' . self::MAX_VALUES];
        }

        return [
            'values' => 'required|array|min:1|max:' . self::MAX_VALUES,
            'values.*.server_id' => 'required|integer|min:1',
            'values.*.env' => ['required', 'string', 'regex:/^[A-Za-z_][A-Za-z0-9_]{0,190}$/'],
            'values.*.value' => 'present|nullable|string',
        ];
    }
}
