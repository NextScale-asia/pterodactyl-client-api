<?php

namespace Pterodactyl\Tests\Integration\Api\Application\Users;

use Pterodactyl\Models\User;
use Illuminate\Http\Response;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

/**
 * Runs inside a Pterodactyl panel checkout with this package installed;
 * see scripts/test-in-panel.sh.
 */
class UserApiKeyControllerTest extends ApplicationApiIntegrationTestCase
{
    /**
     * The panel's exception handler rolls back every open transaction when it renders
     * an error, which would wipe the test's own wrapping transaction (and its fixtures)
     * after the first 4xx. Run without it, like the panel's client API tests.
     */
    protected array $connectionsToTransact = [];

    private function url(User $user, string $suffix = ''): string
    {
        return "/api/application/users/{$user->id}/api-keys" . $suffix;
    }

    private function useBearer(ApiKey $key): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $key->identifier . decrypt($key->token));
    }

    public function testKeyIsCreatedAndWorksAgainstTheClientApi(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson($this->url($user), [
            'description' => 'byzic-provision',
            'allowed_ips' => [],
        ]);

        $response->assertOk()
            ->assertJsonPath('object', ApiKey::RESOURCE_NAME)
            ->assertJsonPath('attributes.description', 'byzic-provision')
            ->assertJsonMissingPath('attributes.token');

        $identifier = $response->json('attributes.identifier');
        $secret = $response->json('meta.secret_token');

        $this->assertStringStartsWith('ptlc_', $identifier);
        $this->assertSame(ApiKey::IDENTIFIER_LENGTH, strlen($identifier));
        $this->assertSame(ApiKey::KEY_LENGTH, strlen($secret));

        $key = ApiKey::query()->where('identifier', $identifier)->firstOrFail();
        $this->assertSame($user->id, $key->user_id);
        $this->assertSame(ApiKey::TYPE_ACCOUNT, $key->key_type);

        // The full key (identifier + secret) must authenticate as the target user.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $identifier . $secret)
            ->getJson('/api/client/account')
            ->assertOk()
            ->assertJsonPath('attributes.id', $user->id);

        $this->assertActivityFor('user:api-key.create', $this->getApiUser(), $user);
    }

    public function testOnlyAccountKeysOfTheUserAreListed(): void
    {
        $user = User::factory()->create();
        $mine = ApiKey::factory()->for($user)->create([
            'key_type' => ApiKey::TYPE_ACCOUNT,
            'identifier' => ApiKey::generateTokenIdentifier(ApiKey::TYPE_ACCOUNT),
        ]);
        ApiKey::factory()->for(User::factory()->create())->create([
            'key_type' => ApiKey::TYPE_ACCOUNT,
            'identifier' => ApiKey::generateTokenIdentifier(ApiKey::TYPE_ACCOUNT),
        ]);

        $this->getJson($this->url($user))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.attributes.identifier', $mine->identifier)
            ->assertJsonMissingPath('data.0.attributes.token');
    }

    public function testKeyIsDeleted(): void
    {
        $user = User::factory()->create();
        $key = ApiKey::factory()->for($user)->create([
            'key_type' => ApiKey::TYPE_ACCOUNT,
            'identifier' => ApiKey::generateTokenIdentifier(ApiKey::TYPE_ACCOUNT),
        ]);

        $this->deleteJson($this->url($user, '/' . $key->identifier))->assertNoContent();

        $this->assertModelMissing($key);
        $this->assertActivityFor('user:api-key.delete', $this->getApiUser(), $user);
    }

    public function testKeyOfAnotherUserCannotBeDeleted(): void
    {
        $user = User::factory()->create();
        $other = ApiKey::factory()->for(User::factory()->create())->create([
            'key_type' => ApiKey::TYPE_ACCOUNT,
            'identifier' => ApiKey::generateTokenIdentifier(ApiKey::TYPE_ACCOUNT),
        ]);

        $this->deleteJson($this->url($user, '/' . $other->identifier))->assertNotFound();

        $this->assertModelExists($other);
    }

    public function testMalformedIdentifierOrUnknownUserReturns404(): void
    {
        $user = User::factory()->create();

        $this->deleteJson($this->url($user, '/short'))->assertNotFound();
        $this->getJson('/api/application/users/abc/api-keys')->assertNotFound();
        $this->getJson('/api/application/users/999999/api-keys')->assertNotFound();
    }

    public static function aclProvider(): array
    {
        return [
            'none' => [AdminAcl::NONE, Response::HTTP_FORBIDDEN, Response::HTTP_FORBIDDEN],
            'read' => [AdminAcl::READ, Response::HTTP_OK, Response::HTTP_FORBIDDEN],
            'write' => [AdminAcl::WRITE, Response::HTTP_FORBIDDEN, Response::HTTP_OK],
            'read+write' => [AdminAcl::READ | AdminAcl::WRITE, Response::HTTP_OK, Response::HTTP_OK],
        ];
    }

    #[DataProvider('aclProvider')]
    public function testUsersAclIsEnforced(int $permission, int $readStatus, int $writeStatus): void
    {
        $this->createNewDefaultApiKey($this->getApiUser(), ['r_users' => $permission]);
        $user = User::factory()->create();

        $this->getJson($this->url($user))->assertStatus($readStatus);
        $this->postJson($this->url($user), ['description' => 'acl'])->assertStatus($writeStatus);
    }

    public function testAccountKeyOfRegularUserIsRejected(): void
    {
        $attacker = User::factory()->create();
        $victim = User::factory()->create();
        $this->useBearer(ApiKey::factory()->for($attacker)->create([
            'key_type' => ApiKey::TYPE_ACCOUNT,
            'identifier' => ApiKey::generateTokenIdentifier(ApiKey::TYPE_ACCOUNT),
        ]));

        foreach ([$attacker, $victim] as $target) {
            $this->getJson($this->url($target))->assertForbidden();
            $this->postJson($this->url($target), ['description' => 'x'])->assertForbidden();
        }

        $this->assertSame(0, ApiKey::query()->where('memo', 'x')->count());
    }

    public function testSessionOfRegularUserIsRejected(): void
    {
        $attacker = User::factory()->create();
        $victim = User::factory()->create();

        $this->flushHeaders();
        $this->actingAs($attacker)
            ->postJson($this->url($victim), ['description' => 'x'])
            ->assertForbidden();
    }

    public function testRootAdminTargetIsRefusedUnlessAllowed(): void
    {
        $admin = User::factory()->create(['root_admin' => true]);
        $key = ApiKey::factory()->for($admin)->create([
            'key_type' => ApiKey::TYPE_ACCOUNT,
            'identifier' => ApiKey::generateTokenIdentifier(ApiKey::TYPE_ACCOUNT),
        ]);

        $this->getJson($this->url($admin))->assertForbidden();
        $this->postJson($this->url($admin), ['description' => 'x'])->assertForbidden();
        $this->deleteJson($this->url($admin, '/' . $key->identifier))->assertForbidden();
        $this->assertModelExists($key);

        config()->set('pterodactyl-client-api.api_key.allow_admin_targets', true);

        $this->getJson($this->url($admin))->assertOk();
    }

    public function testKeyLimitIsApplied(): void
    {
        config()->set('pterodactyl-client-api.api_key.max_keys_per_user', 2);
        $user = User::factory()->create();

        $this->postJson($this->url($user), ['description' => 'one'])->assertOk();
        $this->postJson($this->url($user), ['description' => 'two'])->assertOk();
        $this->postJson($this->url($user), ['description' => 'three'])
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('errors.0.code', 'DisplayException')
            ->assertJsonPath('errors.0.detail', 'This user has reached the limit of 2 API keys.');

        $this->assertSame(2, $user->apiKeys()->count());
    }

    public function testSameDescriptionCanBeReused(): void
    {
        $user = User::factory()->create();

        $this->postJson($this->url($user), ['description' => 'byzic-provision'])->assertOk();
        $this->postJson($this->url($user), ['description' => 'byzic-provision'])->assertOk();
    }

    public function testAllowedIpsAreValidated(): void
    {
        $user = User::factory()->create();

        $this->postJson($this->url($user), ['description' => 'cidr', 'allowed_ips' => ['10.0.0.0/24', '127.0.0.1']])
            ->assertOk()
            ->assertJsonPath('attributes.allowed_ips', ['10.0.0.0/24', '127.0.0.1']);

        $this->postJson($this->url($user), ['description' => 'bad', 'allowed_ips' => ['abc']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.meta.source_field', 'allowed_ips.0');

        $ips = array_map(fn ($i) => "10.0.0.{$i}", range(1, 51));
        $this->postJson($this->url($user), ['description' => 'many', 'allowed_ips' => $ips])
            ->assertUnprocessable();

        $this->postJson($this->url($user), [])->assertUnprocessable();
    }
}
