<?php

namespace Byzic\PterodactylClientApi\Http\Controllers;

use Pterodactyl\Models\User;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Facades\Activity;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Transformers\Api\Client\ApiKeyTransformer;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;
use Byzic\PterodactylClientApi\Http\Requests\GetUsersApiKeysRequest;
use Byzic\PterodactylClientApi\Http\Requests\StoreUserApiKeyRequest;
use Byzic\PterodactylClientApi\Http\Requests\DeleteUserApiKeyRequest;

/**
 * Lets an Application API key manage another user's account (ptlc_) API keys.
 * Behaves like the panel's Client\ApiKeyController, but for the user in the route.
 */
class ApiKeyController extends ApplicationApiController
{
    /**
     * Returns all the account API keys that exist for the given user.
     */
    public function index(GetUsersApiKeysRequest $request, User $user): array
    {
        return $this->fractal->collection($user->apiKeys)
            ->transformWith($this->getTransformer(ApiKeyTransformer::class))
            ->toArray();
    }

    /**
     * Store a new account API key for the given user.
     *
     * The secret is returned once in meta.secret_token; the full key is
     * attributes.identifier followed by meta.secret_token.
     *
     * @throws DisplayException
     */
    public function store(StoreUserApiKeyRequest $request, User $user): array
    {
        $max = (int) config('pterodactyl-client-api.api_key.max_keys_per_user', 5);

        $token = DB::transaction(function () use ($request, $user, $max) {
            if ($user->apiKeys()->lockForUpdate()->count() >= $max) {
                throw new DisplayException("This user has reached the limit of {$max} API keys.");
            }

            return $user->createToken(
                $request->input('description'),
                $request->input('allowed_ips')
            );
        });

        Activity::event('user:api-key.create')
            ->subject($user, $token->accessToken)
            ->property('identifier', $token->accessToken->identifier)
            ->log();

        return $this->fractal->item($token->accessToken)
            ->transformWith($this->getTransformer(ApiKeyTransformer::class))
            ->addMeta(['secret_token' => $token->plainTextToken])
            ->toArray();
    }

    /**
     * Deletes one of the given user's account API keys.
     */
    public function delete(DeleteUserApiKeyRequest $request, User $user, string $identifier): JsonResponse
    {
        $key = $user->apiKeys()->where('identifier', $identifier)->firstOrFail();

        Activity::event('user:api-key.delete')
            ->subject($user)
            ->property('identifier', $key->identifier)
            ->log();

        $key->delete();

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }
}
