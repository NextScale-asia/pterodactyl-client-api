<?php

namespace Pterodactyl\Tests\Integration\Api\Application\Users;

use Pterodactyl\Models\User;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

/**
 * Runs inside a Pterodactyl panel checkout with this package installed;
 * see scripts/test-in-panel.sh.
 */
class FreeAllocationControllerTest extends ApplicationApiIntegrationTestCase
{
    /**
     * The panel's exception handler rolls back every open transaction when it renders
     * an error, which would wipe the test's own wrapping transaction (and its fixtures)
     * after the first 4xx. Run without it, like the panel's client API tests.
     */
    protected array $connectionsToTransact = [];

    public function testOnlyUnassignedAllocationsAreReturned(): void
    {
        $server = $this->createServerModel();
        $free = Allocation::factory()->times(2)->create(['node_id' => $server->node_id]);

        $response = $this->getJson("/api/application/nodes/{$server->node_id}/allocations/free")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', 2);

        $this->assertEqualsCanonicalizing(
            $free->pluck('id')->all(),
            collect($response->json('data'))->pluck('attributes.id')->all()
        );
        $this->assertNotContains($server->allocation_id, collect($response->json('data'))->pluck('attributes.id'));
    }

    public function testPerPageIsValidated(): void
    {
        $server = $this->createServerModel();
        Allocation::factory()->times(3)->create(['node_id' => $server->node_id]);
        $url = "/api/application/nodes/{$server->node_id}/allocations/free";

        $this->getJson($url . '?per_page=2')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($url . '?per_page=101')->assertUnprocessable();
        $this->getJson($url . '?per_page=abc')->assertUnprocessable();
    }

    public function testUnknownNodeReturns404(): void
    {
        $this->getJson('/api/application/nodes/999999/allocations/free')->assertNotFound();
    }

    public function testAllocationsAclIsEnforced(): void
    {
        $server = $this->createServerModel();
        $this->createNewDefaultApiKey($this->getApiUser(), ['r_allocations' => AdminAcl::NONE]);

        $this->getJson("/api/application/nodes/{$server->node_id}/allocations/free")->assertForbidden();
    }

    public function testAccountKeyOfRegularUserIsRejected(): void
    {
        $server = $this->createServerModel();
        $user = User::factory()->create();
        $key = ApiKey::factory()->for($user)->create([
            'key_type' => ApiKey::TYPE_ACCOUNT,
            'identifier' => ApiKey::generateTokenIdentifier(ApiKey::TYPE_ACCOUNT),
        ]);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $key->identifier . decrypt($key->token))
            ->getJson("/api/application/nodes/{$server->node_id}/allocations/free")
            ->assertForbidden();
    }
}
