<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

/**
 * DELETE /eggs/{egg} and DELETE /eggs/{egg}/variables/{env}.
 */
class FingerprintedEggRequest extends EggWriteRequest
{
    public function rules(): array
    {
        return ['expected_fingerprint' => 'required|string|size:64'];
    }
}
