<?php

namespace Pterodactyl\Tests\Integration\Api\Application\Users;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\ServerVariable;
use Pterodactyl\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

/**
 * Runs inside a Pterodactyl panel checkout with this package installed;
 * see scripts/test-in-panel.sh.
 */
class EggControllerTest extends ApplicationApiIntegrationTestCase
{
    /** See UserApiKeyControllerTest: the panel's handler rolls back open transactions on 4xx. */
    protected array $connectionsToTransact = [];

    public function setUp(): void
    {
        parent::setUp();

        config()->set('pterodactyl-client-api.egg_write.enabled', true);
    }

    private function egg(): Egg
    {
        return $this->cloneEggAndVariables(
            Egg::query()->where('author', 'support@pterodactyl.io')->where('name', 'Bungeecord')->firstOrFail()
        );
    }

    private function fingerprint(Egg $egg): string
    {
        return $this->getJson("/api/application/eggs/{$egg->id}/export")->assertOk()->json('fingerprint');
    }

    public function testPatchChangesOnlyTheAllowedFields(): void
    {
        $egg = $this->egg();
        $fingerprint = $this->fingerprint($egg);

        $response = $this->patchJson("/api/application/eggs/{$egg->id}", [
            'name' => 'Renamed egg',
            'description' => '',
            'force_outgoing_ip' => true,
            'script_is_privileged' => false,
            'expected_fingerprint' => $fingerprint,
        ])->assertOk()
            ->assertJsonPath('attributes.name', 'Renamed egg')
            ->assertJsonPath('attributes.script.privileged', false);

        $egg->refresh();
        $this->assertSame('Renamed egg', $egg->name);
        $this->assertSame('', $egg->description);
        $this->assertTrue($egg->force_outgoing_ip);
        $this->assertFalse($egg->script_is_privileged);
        $this->assertSame($this->fingerprint($egg), $response->json('meta.fingerprint'));
        $this->assertSame(['name', 'description', 'force_outgoing_ip', 'script_is_privileged'], ActivityLog::query()
            ->where('event', 'admin:egg.update')->latest('id')->firstOrFail()->properties['fields']);
    }

    public function testPatchRefusesOtherFields(): void
    {
        $egg = $this->egg();
        $startup = $egg->startup;

        foreach (['startup', 'docker_images', 'script_install', 'file_denylist', 'config_from'] as $field) {
            $this->patchJson("/api/application/eggs/{$egg->id}", [
                $field => 'x',
                'expected_fingerprint' => $this->fingerprint($egg),
            ])->assertStatus(422);
        }

        $this->assertSame($startup, $egg->fresh()->startup);
    }

    public function testPatchNeedsACurrentFingerprint(): void
    {
        $egg = $this->egg();

        $this->patchJson("/api/application/eggs/{$egg->id}", ['name' => 'x'])->assertStatus(422);
        $this->patchJson("/api/application/eggs/{$egg->id}", ['name' => 'x', 'expected_fingerprint' => str_repeat('0', 64)])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'EggChanged');
        $this->assertNotSame('x', $egg->fresh()->name);
    }

    public function testDeleteIsRefusedWhileAnotherEggCopiesTheScript(): void
    {
        $egg = $this->egg();
        $child = $this->egg();
        $child->forceFill(['copy_script_from' => $egg->id])->save();

        $this->deleteJson("/api/application/eggs/{$egg->id}", ['expected_fingerprint' => $this->fingerprint($egg)])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'EggHasChildren')
            ->assertJsonPath('errors.0.meta.eggs', [$child->id]);

        $child->forceFill(['copy_script_from' => null, 'config_from' => $egg->id])->save();
        $this->deleteJson("/api/application/eggs/{$egg->id}", ['expected_fingerprint' => $this->fingerprint($egg)])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'EggHasChildren');

        $this->assertTrue(Egg::query()->whereKey($egg->id)->exists());
        $this->assertSame($egg->id, $child->fresh()->config_from);
    }

    public function testDeleteIsRefusedWhileServersUseTheEgg(): void
    {
        $egg = $this->egg();
        $this->createServerModel(['egg_id' => $egg->id]);

        $this->deleteJson("/api/application/eggs/{$egg->id}", ['expected_fingerprint' => $this->fingerprint($egg)])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'HasActiveServersException');
        $this->assertTrue(Egg::query()->whereKey($egg->id)->exists());
    }

    public function testDeleteRemovesAnUnusedEgg(): void
    {
        $egg = $this->egg();

        $this->deleteJson("/api/application/eggs/{$egg->id}", ['expected_fingerprint' => str_repeat('a', 64)])
            ->assertStatus(409);
        $this->deleteJson("/api/application/eggs/{$egg->id}", ['expected_fingerprint' => $this->fingerprint($egg)])
            ->assertNoContent();

        $this->assertFalse(Egg::query()->whereKey($egg->id)->exists());
        $this->assertSame($egg->id, ActivityLog::query()->where('event', 'admin:egg.delete')->latest('id')->firstOrFail()->properties['egg_id']);
    }

    public function testDeleteVariableReturnsTheServerValues(): void
    {
        $egg = $this->egg();
        $server = $this->createServerModel(['egg_id' => $egg->id]);
        $variableId = $egg->variables()->where('env_variable', 'SERVER_JARFILE')->value('id');
        ServerVariable::query()->create(['server_id' => $server->id, 'variable_id' => $variableId, 'variable_value' => 'secret.jar']);

        $this->deleteJson("/api/application/eggs/{$egg->id}/variables/SERVER_JARFILE", ['expected_fingerprint' => str_repeat('0', 64)])
            ->assertStatus(409);

        $response = $this->deleteJson("/api/application/eggs/{$egg->id}/variables/SERVER_JARFILE", ['expected_fingerprint' => $this->fingerprint($egg)])
            ->assertOk()
            ->assertJsonPath('dropped_values.0.server_id', $server->id)
            ->assertJsonPath('dropped_values.0.server_uuid', $server->uuid)
            ->assertJsonPath('dropped_values.0.value', 'secret.jar');

        $this->assertSame($this->fingerprint($egg), $response->json('fingerprint'));
        $this->assertFalse(EggVariable::query()->whereKey($variableId)->exists());
        $this->assertSame(0, ServerVariable::query()->where('variable_id', $variableId)->count());
        $this->assertStringNotContainsString('secret.jar', json_encode(
            ActivityLog::query()->where('event', 'admin:egg.variable.delete')->latest('id')->firstOrFail()->properties
        ));

        $this->deleteJson("/api/application/eggs/{$egg->id}/variables/SERVER_JARFILE", ['expected_fingerprint' => $this->fingerprint($egg)])
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'EggVariableNotFound');
    }

    public function testUpsertVariableAcceptsAnOptionalFingerprint(): void
    {
        $egg = $this->egg();
        $body = [
            'name' => 'Timezone', 'description' => '', 'default_value' => '',
            'user_viewable' => true, 'user_editable' => true, 'rules' => 'nullable|string',
        ];

        $this->putJson("/api/application/eggs/{$egg->id}/variables/TZ", $body + ['expected_fingerprint' => str_repeat('0', 64)])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'EggChanged');
        $this->assertFalse($egg->variables()->where('env_variable', 'TZ')->exists());

        $response = $this->putJson("/api/application/eggs/{$egg->id}/variables/TZ", $body + ['expected_fingerprint' => $this->fingerprint($egg)])
            ->assertCreated();
        $this->assertSame($this->fingerprint($egg), $response->json('meta.fingerprint'));

        // without a fingerprint it keeps working like 2.3.0, and the gate does not apply
        config()->set('pterodactyl-client-api.egg_write.enabled', false);
        $this->putJson("/api/application/eggs/{$egg->id}/variables/TZ", $body)->assertOk();
    }
}
