<?php

namespace Pterodactyl\Tests\Integration\Api\Application\Users;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Models\ServerVariable;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use Byzic\PterodactylClientApi\Support\EggFingerprint;
use Pterodactyl\Services\Eggs\Sharing\EggExporterService;
use Pterodactyl\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

/**
 * Key type, field sizes, withheld server values, typed 404s, child eggs.
 *
 * Runs inside a Pterodactyl panel checkout with this package installed;
 * see scripts/test-in-panel.sh. The app (and the API key it resolved) is reused for every request
 * of a test, so a test that switches keys does it before its first request.
 */
class EggHardeningTest extends ApplicationApiIntegrationTestCase
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

    private function exportOf(Egg $egg): array
    {
        return json_decode(app(EggExporterService::class)->handle($egg->id), true);
    }

    public function testAccountKeysCannotWriteEvenForRootAdmins(): void
    {
        $egg = $this->egg();
        $this->createNewDefaultApiKey($this->getApiUser(), ['key_type' => ApiKey::TYPE_ACCOUNT]);

        $this->patchJson("/api/application/eggs/{$egg->id}", ['name' => 'x', 'expected_fingerprint' => EggFingerprint::of($egg->id)])
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'EggWriteNeedsApplicationKey');
        $this->postJson('/api/application/nests', ['name' => 'Account key nest'])
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'EggWriteNeedsApplicationKey');
        $this->getJson('/api/application/addon/info')
            ->assertOk()
            ->assertJsonPath('egg_write_key_allowed', false);

        $this->assertNotSame('x', $egg->fresh()->name);
    }

    public function testOversizedFieldsAreRefused(): void
    {
        $egg = $this->egg();
        $long = str_repeat('a', 65536);
        $file = $this->exportOf($egg);

        $file['scripts']['installation']['script'] = $long;
        $this->postJson("/api/application/eggs/{$egg->id}/update-import/preview", ['egg' => $file])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'EggFieldTooLong')
            ->assertJsonPath('errors.0.meta.field', 'scripts.installation.script');

        $file = $this->exportOf($egg);
        $file['variables'][0]['default_value'] = $long;
        $this->postJson("/api/application/eggs/{$egg->id}/update-import/preview", ['egg' => $file])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.meta.field', 'variables.0.default_value');

        $file = $this->exportOf($egg);
        $file['scripts']['installation']['container'] = str_repeat('a', 192);
        $this->postJson("/api/application/eggs/{$egg->id}/update-import/preview", ['egg' => $file])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'EggFieldTooLong');

        $this->putJson("/api/application/eggs/{$egg->id}/variables/TZ", [
            'name' => 'Timezone', 'default_value' => $long, 'user_viewable' => true, 'user_editable' => true,
        ])->assertStatus(422)->assertJsonPath('errors.0.code', 'EggFieldTooLong');
        $this->assertFalse($egg->variables()->where('env_variable', 'TZ')->exists());

        $server = $this->createServerModel(['egg_id' => $egg->id]);
        $this->postJson("/api/application/eggs/{$egg->id}/server-variables/restore", [
            'values' => [['server_id' => $server->id, 'env' => 'SERVER_JARFILE', 'value' => $long]],
        ])->assertStatus(422)->assertJsonPath('errors.0.code', 'EggFieldTooLong');
    }

    public function testWrongTypesAreRefused(): void
    {
        $egg = $this->egg();
        $url = "/api/application/eggs/{$egg->id}/update-import/preview";

        foreach ([['startup', ['a']], ['meta.update_url', 5], ['scripts.installation.script', ['x' => 1]]] as [$path, $value]) {
            $file = $this->exportOf($egg);
            data_set($file, $path, $value);
            $this->postJson($url, ['egg' => $file])->assertStatus(422)->assertJsonPath('errors.0.code', 'InvalidEgg');
        }
    }

    public function testTooManyRestoreValuesAreRefusedUpFront(): void
    {
        $egg = $this->egg();
        $values = array_fill(0, 5001, ['server_id' => 1, 'env' => 'X', 'value' => 'y']);

        $this->postJson("/api/application/eggs/{$egg->id}/server-variables/restore", ['values' => $values])
            ->assertStatus(422);
    }

    public function testServerValuesAreWithheldWithoutServersRead(): void
    {
        $egg = $this->egg();
        $server = $this->createServerModel(['egg_id' => $egg->id]);
        ServerVariable::query()->create([
            'server_id' => $server->id,
            'variable_id' => $egg->variables()->where('env_variable', 'SERVER_JARFILE')->value('id'),
            'variable_value' => 'secret.jar',
        ]);
        $this->createNewDefaultApiKey($this->getApiUser(), ['r_servers' => AdminAcl::NONE]);

        $response = $this->deleteJson("/api/application/eggs/{$egg->id}/variables/SERVER_JARFILE", [
            'expected_fingerprint' => EggFingerprint::of($egg->id),
        ])->assertOk()
            ->assertJsonPath('values_withheld', true)
            ->assertJsonPath('dropped_count', 1)
            ->assertJsonPath('dropped_values.0.server_id', $server->id)
            ->assertJsonPath('dropped_values.0.env', 'SERVER_JARFILE');

        $this->assertArrayNotHasKey('value', $response->json('dropped_values.0'));
        $this->assertStringNotContainsString('secret.jar', $response->getContent());
    }

    public function testMissingModelsHaveTheirOwnCodes(): void
    {
        $this->postJson('/api/application/eggs/999999/update-import/preview', ['egg' => []])
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'EggNotFound');
        $this->getJson('/api/application/eggs/999999/export')
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'EggNotFound');
    }

    public function testVariableRoutesMatchEnvNamesExactly(): void
    {
        $egg = $this->egg();
        $body = ['name' => 'Timezone', 'default_value' => 'UTC', 'user_viewable' => true, 'user_editable' => true, 'rules' => 'nullable|string'];
        $this->putJson("/api/application/eggs/{$egg->id}/variables/TZ", $body)->assertCreated();
        $tz = $egg->variables()->where('env_variable', 'TZ')->sole();

        $this->putJson("/api/application/eggs/{$egg->id}/variables/tz", array_merge($body, ['default_value' => 'Asia/Ho_Chi_Minh', 'user_editable' => false]))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'EggVariableCaseMismatch')
            ->assertJsonPath('errors.0.meta.existing', 'TZ');
        $this->deleteJson("/api/application/eggs/{$egg->id}/variables/tz", ['expected_fingerprint' => EggFingerprint::of($egg->id)])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'EggVariableCaseMismatch');

        $after = $egg->variables()->where('env_variable', 'TZ')->sole();
        $this->assertSame([$tz->id, 'TZ', 'UTC', true], [$after->id, $after->env_variable, $after->default_value, $after->user_editable]);
        $this->assertSame(3, $egg->variables()->count());

        $server = $this->createServerModel(['egg_id' => $egg->id]);
        $this->postJson("/api/application/eggs/{$egg->id}/server-variables/restore", [
            'values' => [['server_id' => $server->id, 'env' => 'tz', 'value' => 'UTC']],
        ])->assertOk()->assertJsonPath('skipped.0.reason', 'variable_missing');
    }

    public function testImportPayloadsMatchEnvNamesExactly(): void
    {
        $egg = $this->egg();
        $fingerprint = EggFingerprint::of($egg->id);
        $file = $this->exportOf($egg);

        $renamed = $file;
        $renamed['variables'][1]['env_variable'] = 'server_jarfile';
        $this->postJson("/api/application/eggs/{$egg->id}/update-import/preview", ['egg' => $renamed])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'EggVariableCaseMismatch')
            ->assertJsonPath('errors.0.meta.env', 'server_jarfile');
        $this->postJson("/api/application/eggs/{$egg->id}/update-import", [
            'egg' => $renamed, 'expected_fingerprint' => $fingerprint, 'expected_removed' => [],
        ])->assertStatus(422)->assertJsonPath('errors.0.code', 'EggVariableCaseMismatch');
        $this->assertSame($fingerprint, EggFingerprint::of($egg->id));

        $twice = $file;
        $twice['variables'][] = array_merge($file['variables'][0], ['env_variable' => 'TZ']);
        $twice['variables'][] = array_merge($file['variables'][0], ['env_variable' => 'tz']);
        $this->postJson("/api/application/nests/{$egg->nest_id}/eggs/import", ['egg' => $twice])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'InvalidEgg')
            ->assertJsonPath('errors.0.meta.env', 'tz');
    }

    public function testChildEggRoundTripKeepsInheritance(): void
    {
        $parent = $this->egg();
        $child = $this->egg();
        $child->forceFill([
            'config_from' => $parent->id,
            'copy_script_from' => $parent->id,
            'config_files' => null,
            'config_startup' => null,
            'config_logs' => null,
            'config_stop' => null,
            'features' => null,
            'script_install' => null,
        ])->save();
        $url = "/api/application/eggs/{$child->id}/update-import/preview";

        $export = $this->getJson("/api/application/eggs/{$child->id}/export")->assertOk()->json();
        $this->assertSame($parent->config_files, $export['egg']['config']['files']);

        $preview = $this->postJson($url, ['egg' => $export['egg']])
            ->assertOk()
            ->assertJsonPath('diff.fields', [])
            ->assertJsonPath('diff.detaches_from_parent', [])
            ->assertJsonPath('diff.warnings', [])
            ->assertJsonPath('normalized_egg.config.files', null)
            ->assertJsonPath('normalized_egg.scripts.installation.script', null)
            ->assertJsonPath('normalized_egg.features', null)
            ->json();

        $this->postJson("/api/application/eggs/{$child->id}/update-import", [
            'egg' => $preview['normalized_egg'],
            'expected_fingerprint' => $preview['fingerprint'],
            'expected_removed' => $preview['expected_removed'],
        ])->assertOk();

        $child->refresh();
        $this->assertNull($child->config_files);
        $this->assertNull($child->config_startup);
        $this->assertNull($child->script_install);
        $this->assertNull($child->features);
        $this->assertSame($parent->id, $child->config_from);

        $changed = $export['egg'];
        $changed['config']['startup'] = '{"done": "Ready"}';
        $changed['file_denylist'] = ['secret.txt'];
        $fields = $this->postJson($url, ['egg' => $changed])
            ->assertOk()
            ->assertJsonPath('diff.detaches_from_parent', ['config.startup'])
            ->assertJsonPath('diff.warnings.0.field', 'file_denylist')
            ->assertJsonPath('normalized_egg.file_denylist', [])
            ->json('diff.fields');
        $this->assertSame(['config.startup'], array_keys($fields));
        $this->assertSame([$parent->config_startup, '{"done": "Ready"}'], $fields['config.startup']);
    }
}
