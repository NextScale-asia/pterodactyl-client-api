<?php

namespace Byzic\PterodactylClientApi\Http\Controllers;

use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;
use Byzic\PterodactylClientApi\Http\Requests\GetUsersApiKeysRequest;
use Byzic\PterodactylClientApi\Http\Requests\StoreUserApiKeyRequest;
use Byzic\PterodactylClientApi\Http\Requests\DeleteUserApiKeyRequest;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Models\User;
use Pterodactyl\Models\AuditLog;
use Pterodactyl\Transformers\Api\Application\ApiKeyTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Pterodactyl\Exceptions\Http\HttpForbiddenException;

class ApiKeyController extends ApplicationApiController
{
    /**
     * Maximum number of API keys per user.
     */
    private const MAX_KEYS_PER_USER = 5;

    /**
     * Returns all of the API keys that exist for the given user.
     *
     * @return array
     */
    public function index(GetUsersApiKeysRequest $request, int $userId): array
    {
        $user = User::findOrFail($userId);
        
        // Audit log for viewing API keys
        activity()
            ->causedBy($request->user())
            ->performedOn($user)
            ->withProperties(['user_id' => $user->id])
            ->log('Viewed API keys for user');

        $apiKeys = $user->apiKeys()
            ->where('key_type', ApiKey::TYPE_ACCOUNT)
            ->get();

        return $this->fractal->collection($apiKeys)
            ->transformWith($this->getTransformer(ApiKeyTransformer::class))
            ->toArray();
    }

    /**
     * Store a new API key for a user's account.
     *
     * @return array
     *
     * @throws \Pterodactyl\Exceptions\Http\HttpForbiddenException
     */
    public function store(StoreUserApiKeyRequest $request, int $userId): array
    {
        $user = User::findOrFail($userId);

        // Check if user has reached maximum API keys limit
        $currentKeyCount = $user->apiKeys()
            ->where('key_type', ApiKey::TYPE_ACCOUNT)
            ->count();

        if ($currentKeyCount >= self::MAX_KEYS_PER_USER) {
            throw new HttpForbiddenException('You have reached the maximum number of API keys (' . self::MAX_KEYS_PER_USER . ').');
        }

        // Generate secure API key
        $identifier = Str::random(16);
        $token = Str::random(48);

        $apiKey = ApiKey::create([
            'user_id' => $user->id,
            'key_type' => ApiKey::TYPE_ACCOUNT,
            'identifier' => $identifier,
            'token' => hash('sha256', $token),
            'allowed_ips' => $request->input('allowed_ips') ?: null,
            'memo' => $request->input('description'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Audit log for API key creation
        activity()
            ->causedBy($request->user())
            ->performedOn($user)
            ->withProperties([
                'user_id' => $user->id,
                'api_key_id' => $apiKey->id,
                'identifier' => $identifier,
                'memo' => $request->input('description'),
                'allowed_ips' => $request->input('allowed_ips'),
            ])
            ->log('Created API key for user');

        return $this->fractal->item($apiKey)
            ->transformWith($this->getTransformer(ApiKeyTransformer::class))
            ->addMeta([
                'secret_token' => $identifier . $token
            ])
            ->toArray();
    }

    /**
     * Deletes a given API key.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete(DeleteUserApiKeyRequest $request, int $userId, string $identifier): JsonResponse
    {
        $user = User::findOrFail($userId);
        
        $apiKey = $user->apiKeys()
            ->where('key_type', ApiKey::TYPE_ACCOUNT)
            ->where('identifier', $identifier)
            ->firstOrFail();

        // Store key info for audit log before deletion
        $keyInfo = [
            'user_id' => $user->id,
            'api_key_id' => $apiKey->id,
            'identifier' => $identifier,
            'memo' => $apiKey->memo,
        ];

        $apiKey->delete();

        // Audit log for API key deletion
        activity()
            ->causedBy($request->user())
            ->performedOn($user)
            ->withProperties($keyInfo)
            ->log('Deleted API key for user');

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }
}