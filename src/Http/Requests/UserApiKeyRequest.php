<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

use Pterodactyl\Models\User;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Http\Requests\Api\Application\ApplicationApiRequest;

/**
 * Base request for managing another user's account API keys through the
 * Application API. ACL is checked against the "users" resource.
 */
abstract class UserApiKeyRequest extends ApplicationApiRequest
{
    protected ?string $resource = AdminAcl::RESOURCE_USERS;

    public function authorize(): bool
    {
        if (!parent::authorize()) {
            return false;
        }

        // An account key minted for a root admin is a full panel takeover that the
        // admin never sees, so admin targets are refused unless explicitly allowed.
        return !$this->parameter('user', User::class)->root_admin
            || config('pterodactyl-client-api.api_key.allow_admin_targets', false);
    }
}
