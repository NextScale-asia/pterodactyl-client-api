<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

use Pterodactyl\Http\Requests\Api\Application\ApplicationApiRequest;
use Pterodactyl\Services\Acl\Api\AdminAcl as Acl;

class StoreUserApiKeyRequest extends ApplicationApiRequest
{
    /**
     * @var string
     */
    protected ?string $resource = Acl::RESOURCE_USERS;

    /**
     * @var int
     */
    protected int $permission = Acl::WRITE;

    /**
     * Validation rules for creating API key.
     *
     * @return array
     */
    public function rules(): array
    {
        $userId = $this->route('user');
        
        return [
            'description' => [
                'required',
                'string',
                'min:1',
                'max:100',
                // Kiểm tra unique description per user
                'unique:api_keys,memo,NULL,id,user_id,' . $userId . ',key_type,' . \Pterodactyl\Models\ApiKey::TYPE_ACCOUNT
            ],
            'allowed_ips' => 'sometimes|nullable|array|max:10',
            'allowed_ips.*' => 'ip',
        ];
    }

    /**
     * Custom error messages.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'description.required' => 'A description for this API key is required.',
            'description.unique' => 'You already have an API key with this description.',
            'description.max' => 'API key description cannot exceed 100 characters.',
            'allowed_ips.max' => 'You cannot specify more than 10 allowed IP addresses.',
            'allowed_ips.*.ip' => 'Each allowed IP must be a valid IP address.',
        ];
    }
}