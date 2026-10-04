<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

use IPTools\Range;
use Pterodactyl\Models\ApiKey;
use Illuminate\Validation\Validator;
use Pterodactyl\Services\Acl\Api\AdminAcl;

/**
 * Mirrors the panel's Client\Account\StoreApiKeyRequest so keys created here are
 * validated exactly like keys a user creates from their own account page.
 */
class StoreUserApiKeyRequest extends UserApiKeyRequest
{
    protected int $permission = AdminAcl::WRITE;

    public function rules(): array
    {
        $rules = ApiKey::getRules();

        return [
            'description' => $rules['memo'],
            'allowed_ips' => [...$rules['allowed_ips'], 'max:50'],
            'allowed_ips.*' => 'string',
        ];
    }

    /**
     * Check that each allowed IP is a valid address or CIDR range.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (!is_array($ips = $this->input('allowed_ips'))) {
                return;
            }

            foreach ($ips as $index => $ip) {
                $valid = false;
                try {
                    $valid = Range::parse($ip)->valid();
                } catch (\Exception $exception) {
                    if ($exception->getMessage() !== 'Invalid IP address format') {
                        throw $exception;
                    }
                } finally {
                    $validator->errors()->addIf(!$valid, "allowed_ips.{$index}", '"' . $ip . '" is not a valid IP address or CIDR range.');
                }
            }
        });
    }
}
