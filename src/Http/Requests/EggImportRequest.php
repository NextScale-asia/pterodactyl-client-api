<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

/**
 * Body of the egg import endpoints. `egg` is the PTDL_v1/v2 JSON object itself, not a string.
 *   update-import/preview: egg, drop_variables[]
 *   update-import:         egg (normalized_egg from the preview), expected_fingerprint, expected_removed[]
 *   nests/{nest}/eggs/import[/preview]: egg
 */
class EggImportRequest extends EggWriteRequest
{
    public function rules(): array
    {
        $rules = ['egg' => 'required|array'];

        if ($this->routeIs('api.application.eggs.update-import.preview')) {
            $rules += ['drop_variables' => 'sometimes|array', 'drop_variables.*' => 'string|max:191'];
        }

        if ($this->routeIs('api.application.eggs.update-import')) {
            $rules += [
                'expected_fingerprint' => 'required|string|size:64',
                'expected_removed' => 'present|array',
                'expected_removed.*' => 'string|max:191',
            ];
        }

        return $rules;
    }
}
