<?php

namespace Pterodactyl\Tests\Integration\Api\Application\Users;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

/**
 * Runs inside a Pterodactyl panel checkout with this package installed;
 * see scripts/test-in-panel.sh.
 */
class NestControllerTest extends ApplicationApiIntegrationTestCase
{
    /** See UserApiKeyControllerTest: the panel's handler rolls back open transactions on 4xx. */
    protected array $connectionsToTransact = [];

    public function setUp(): void
    {
        parent::setUp();

        config()->set('pterodactyl-client-api.egg_write.enabled', true);
    }

    public function testCreateUpdateDeleteAnEmptyNest(): void
    {
        $id = $this->postJson('/api/application/nests', ['name' => 'Survival games', 'description' => 'Test nest'])
            ->assertCreated()
            ->assertJsonPath('object', 'nest')
            ->assertJsonPath('attributes.name', 'Survival games')
            ->json('attributes.id');

        $this->patchJson("/api/application/nests/{$id}", ['name' => 'Survival'])
            ->assertOk()
            ->assertJsonPath('attributes.name', 'Survival')
            ->assertJsonPath('attributes.description', 'Test nest');

        $this->deleteJson("/api/application/nests/{$id}")->assertNoContent();
        $this->assertFalse(Nest::query()->whereKey($id)->exists());
        $this->assertSame(
            ['admin:nest.create', 'admin:nest.update', 'admin:nest.delete'],
            ActivityLog::query()->where('event', 'like', 'admin:nest.%')->latest('id')->limit(3)->pluck('event')->reverse()->values()->all()
        );
    }

    public function testCreateIsValidated(): void
    {
        $this->postJson('/api/application/nests', ['name' => ''])->assertStatus(422);
        $this->postJson('/api/application/nests', ['name' => str_repeat('a', 192)])->assertStatus(422);
    }

    public function testNestWithEggsIsNotDeleted(): void
    {
        $id = $this->postJson('/api/application/nests', ['name' => 'Has eggs'])->assertCreated()->json('attributes.id');
        $egg = $this->cloneEggAndVariables(
            Egg::query()->where('author', 'support@pterodactyl.io')->where('name', 'Bungeecord')->firstOrFail()
        );
        $egg->forceFill(['nest_id' => $id])->save();

        $this->deleteJson("/api/application/nests/{$id}")
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'NestHasEggs')
            ->assertJsonPath('errors.0.meta.eggs_count', 1);

        $this->assertTrue(Nest::query()->whereKey($id)->exists());
        $this->assertTrue(Egg::query()->whereKey($egg->id)->exists());
    }

    public function testUnknownNestIs404(): void
    {
        $this->patchJson('/api/application/nests/999999', ['name' => 'x'])->assertNotFound()->assertJsonPath('errors.0.code', 'NestNotFound');
        $this->deleteJson('/api/application/nests/999999')->assertNotFound()->assertJsonPath('errors.0.code', 'NestNotFound');
    }
}
