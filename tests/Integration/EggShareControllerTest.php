<?php

namespace Pterodactyl\Tests\Integration\Api\Application\Users;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\EggVariable;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\ServerVariable;
use Pterodactyl\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

/**
 * Runs inside a Pterodactyl panel checkout with this package installed;
 * see scripts/test-in-panel.sh.
 */
class EggShareControllerTest extends ApplicationApiIntegrationTestCase
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

    private function export(Egg $egg): array
    {
        return $this->getJson("/api/application/eggs/{$egg->id}/export")->assertOk()->json();
    }

    private function serverWithValue(Egg $egg, string $env, string $value, array $attributes = []): Server
    {
        $server = $this->createServerModel(array_merge(['egg_id' => $egg->id], $attributes));
        ServerVariable::query()->create([
            'server_id' => $server->id,
            'variable_id' => $egg->variables()->where('env_variable', $env)->value('id'),
            'variable_value' => $value,
        ]);

        return $server;
    }

    /**
     * Counts INSERT / UPDATE / DELETE statements touching egg data while $callback runs.
     */
    private function eggWrites(\Closure $callback): int
    {
        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)
                && preg_match('/\b(eggs|egg_variables|server_variables|nests|servers)\b/', $query->sql)) {
                ++$writes;
            }
        });

        $callback();

        return $writes;
    }

    public function testExportReturnsEggFingerprintAndExtra(): void
    {
        $egg = $this->egg();

        $response = $this->getJson("/api/application/eggs/{$egg->id}/export")
            ->assertOk()
            ->assertJsonPath('egg.meta.version', 'PTDL_v2')
            ->assertJsonPath('egg.name', $egg->name)
            ->assertJsonPath('extra.uuid', $egg->uuid)
            ->assertJsonPath('extra.nest_id', $egg->nest_id)
            ->assertJsonPath('extra.author', $egg->author)
            ->assertJsonPath('extra.copy_script_from', $egg->copy_script_from)
            ->assertJsonPath('extra.script_is_privileged', $egg->script_is_privileged);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $response->json('fingerprint'));
        $this->assertCount(2, $response->json('egg.variables'));
    }

    public function testFingerprintChangesWhenOnlyAVariableChanges(): void
    {
        $egg = $this->egg();
        $before = $this->export($egg)['fingerprint'];
        $updatedAt = $egg->fresh()->updated_at;

        EggVariable::query()->where('egg_id', $egg->id)->where('env_variable', 'BUNGEE_VERSION')->update(['default_value' => '1234']);

        $this->assertEquals($updatedAt, $egg->fresh()->updated_at);
        $this->assertNotSame($before, $this->export($egg)['fingerprint']);
    }

    public function testPreviewWritesNothingAndKeepsMissingVariables(): void
    {
        $egg = $this->egg();
        $this->serverWithValue($egg, 'SERVER_JARFILE', 'proxy.jar');
        ['egg' => $file, 'fingerprint' => $fingerprint] = $this->export($egg);

        $file['startup'] = 'java -jar {{SERVER_JARFILE}} --nogui';
        $file['variables'] = [
            $file['variables'][0],
            ['name' => 'Timezone', 'description' => '', 'env_variable' => 'TZ', 'default_value' => '', 'user_viewable' => true, 'user_editable' => true, 'rules' => 'nullable|string'],
        ];

        $response = null;
        $writes = $this->eggWrites(function () use (&$response, $egg, $file) {
            $response = $this->postJson("/api/application/eggs/{$egg->id}/update-import/preview", ['egg' => $file]);
        });

        $response->assertOk()
            ->assertJsonPath('fingerprint', $fingerprint)
            ->assertJsonPath('expected_removed', [])
            ->assertJsonPath('diff.variables.kept', ['SERVER_JARFILE'])
            ->assertJsonPath('diff.variables.removed', [])
            ->assertJsonPath('diff.variables.added.0.env_variable', 'TZ')
            ->assertJsonPath('diff.variables.added.0.default_value', '')
            ->assertJsonPath('diff.fields.startup.1', 'java -jar {{SERVER_JARFILE}} --nogui')
            ->assertJsonPath('diff.servers_using_egg', 1);
        $this->assertSame(0, $writes);
        $this->assertSame(
            ['BUNGEE_VERSION', 'TZ', 'SERVER_JARFILE'],
            array_column($response->json('normalized_egg.variables'), 'env_variable')
        );
        $this->assertSame($fingerprint, $this->export($egg)['fingerprint']);
    }

    public function testPreviewWithDropListsRemovedVariablesAndImages(): void
    {
        $egg = $this->egg();
        $image = array_values($egg->docker_images)[0];
        $this->serverWithValue($egg, 'SERVER_JARFILE', 'proxy.jar', ['image' => $image]);
        $file = $this->export($egg)['egg'];

        $file['variables'] = [$file['variables'][0]];
        $file['docker_images'] = array_slice($file['docker_images'], 1, null, true);

        $this->postJson("/api/application/eggs/{$egg->id}/update-import/preview", ['egg' => $file, 'drop_variables' => ['SERVER_JARFILE']])
            ->assertOk()
            ->assertJsonPath('expected_removed', ['SERVER_JARFILE'])
            ->assertJsonPath('diff.variables.removed.0.env', 'SERVER_JARFILE')
            ->assertJsonPath('diff.variables.removed.0.server_values', 1)
            ->assertJsonPath('diff.servers_on_removed_images.0.image', $image)
            ->assertJsonPath('diff.servers_on_removed_images.0.count', 1)
            ->assertJsonPath('diff.has_parent_config', false)
            ->assertJsonPath('diff.children_copying_script', 0);
    }

    public function testApplyUpdatesInPlaceAndReturnsDroppedValues(): void
    {
        $egg = $this->egg();
        $server = $this->serverWithValue($egg, 'SERVER_JARFILE', 'proxy.jar');
        $bungeeId = $egg->variables()->where('env_variable', 'BUNGEE_VERSION')->value('id');
        $file = $this->export($egg)['egg'];

        $file['file_denylist'] = ['*.jar', 'secret/'];
        $file['scripts']['installation']['script'] = "#!/bin/ash\necho hi\n\n";
        $file['variables'][0]['default_value'] = '';

        $preview = $this->postJson("/api/application/eggs/{$egg->id}/update-import/preview", [
            'egg' => array_merge($file, ['variables' => [$file['variables'][0]]]),
            'drop_variables' => ['SERVER_JARFILE'],
        ])->assertOk()->json();

        $response = $this->postJson("/api/application/eggs/{$egg->id}/update-import", [
            'egg' => $preview['normalized_egg'],
            'expected_fingerprint' => $preview['fingerprint'],
            'expected_removed' => $preview['expected_removed'],
        ])->assertOk();

        $response->assertJsonPath('removed', ['SERVER_JARFILE'])
            ->assertJsonPath('dropped_values', [[
                'server_id' => $server->id,
                'server_uuid' => $server->uuid,
                'env' => 'SERVER_JARFILE',
                'value' => 'proxy.jar',
            ]])
            ->assertJsonPath('before.variables.1.env_variable', 'SERVER_JARFILE')
            ->assertJsonPath('after.scripts.installation.script', "#!/bin/ash\necho hi\n\n")
            ->assertJsonPath('after.file_denylist', ['*.jar', 'secret/']);
        $this->assertSame($this->export($egg)['fingerprint'], $response->json('fingerprint'));
        $this->assertNotSame($preview['fingerprint'], $response->json('fingerprint'));

        $egg->refresh();
        $this->assertSame(['*.jar', 'secret/'], $egg->file_denylist);
        $this->assertSame("#!/bin/ash\necho hi\n\n", $egg->script_install);
        $this->assertSame([$bungeeId], $egg->variables()->pluck('id')->all());
        $this->assertSame('', $egg->variables()->value('default_value'));
        $this->assertSame(0, ServerVariable::query()->where('server_id', $server->id)->count());

        $after = $response->json('after');
        foreach (['name', 'startup', 'docker_images', 'features'] as $key) {
            $this->assertSame($preview['normalized_egg'][$key], $after[$key], $key);
        }
        $this->assertSame($preview['normalized_egg']['config'], $after['config']);

        $log = ActivityLog::query()->where('event', 'admin:egg.update-import')->latest('id')->firstOrFail();
        $this->assertSame(hash('sha256', "#!/bin/ash\necho hi\n\n"), $log->properties['script_sha256_new']);
        $this->assertSame(['SERVER_JARFILE'], $log->properties['removed']);
        $this->assertStringNotContainsString('echo hi', json_encode($log->properties));
        $this->assertStringNotContainsString('proxy.jar', json_encode($log->properties));
    }

    public function testDroppedValuesCanBeRestored(): void
    {
        $egg = $this->egg();
        $server = $this->serverWithValue($egg, 'SERVER_JARFILE', 'proxy.jar');
        $other = $this->createServerModel();
        $fingerprint = $this->export($egg)['fingerprint'];

        $dropped = $this->deleteJson("/api/application/eggs/{$egg->id}/variables/SERVER_JARFILE", ['expected_fingerprint' => $fingerprint])
            ->assertOk()
            ->json('dropped_values');

        // definition comes back (e.g. a rollback), then the values
        $this->putJson("/api/application/eggs/{$egg->id}/variables/SERVER_JARFILE", [
            'name' => 'Jar', 'description' => '', 'default_value' => 'server.jar',
            'user_viewable' => true, 'user_editable' => true, 'rules' => 'required|string|ends_with:.jar',
        ])->assertCreated();

        $values = array_map(fn ($item) => ['server_id' => $item['server_id'], 'env' => $item['env'], 'value' => $item['value']], $dropped);
        $values[] = ['server_id' => $other->id, 'env' => 'SERVER_JARFILE', 'value' => 'x.jar'];
        $values[] = ['server_id' => $server->id, 'env' => 'NOPE', 'value' => 'x'];
        $values[] = ['server_id' => $server->id, 'env' => 'BUNGEE_VERSION', 'value' => 'not alpha num!'];

        $this->postJson("/api/application/eggs/{$egg->id}/server-variables/restore", ['values' => $values])
            ->assertOk()
            ->assertJsonPath('restored', [['server_id' => $server->id, 'env' => 'SERVER_JARFILE']])
            ->assertJsonPath('skipped.0.reason', 'server_not_on_egg')
            ->assertJsonPath('skipped.1.reason', 'variable_missing')
            ->assertJsonPath('skipped.2.reason', 'invalid_value');

        $this->assertSame('proxy.jar', ServerVariable::query()
            ->where('server_id', $server->id)
            ->where('variable_id', $egg->variables()->where('env_variable', 'SERVER_JARFILE')->value('id'))
            ->value('variable_value'));
        $this->assertSame(0, ServerVariable::query()->where('server_id', $other->id)->whereIn('variable_id', $egg->variables()->pluck('id'))->count());
    }

    public function testStaleFingerprintIsRefused(): void
    {
        $egg = $this->egg();
        ['egg' => $file, 'fingerprint' => $fingerprint] = $this->export($egg);
        EggVariable::query()->where('egg_id', $egg->id)->where('env_variable', 'BUNGEE_VERSION')->update(['description' => 'changed elsewhere']);
        $file['startup'] = 'changed';

        $this->postJson("/api/application/eggs/{$egg->id}/update-import", [
            'egg' => $file, 'expected_fingerprint' => $fingerprint, 'expected_removed' => [],
        ])->assertStatus(409)->assertJsonPath('errors.0.code', 'EggChanged');

        $this->assertNotSame('changed', $egg->fresh()->startup);
    }

    public function testExpectedRemovedMustMatch(): void
    {
        $egg = $this->egg();
        ['egg' => $file, 'fingerprint' => $fingerprint] = $this->export($egg);
        $file['variables'] = [$file['variables'][0]];

        $this->postJson("/api/application/eggs/{$egg->id}/update-import", [
            'egg' => $file, 'expected_fingerprint' => $fingerprint, 'expected_removed' => [],
        ])->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'RemovedVariablesMismatch')
            ->assertJsonPath('errors.0.meta.removed', ['SERVER_JARFILE']);

        $this->assertSame(2, $egg->variables()->count());
    }

    public function testInvalidEggsAreRejectedWith422(): void
    {
        $egg = $this->egg();
        $file = $this->export($egg)['egg'];
        $url = "/api/application/eggs/{$egg->id}/update-import/preview";

        $this->postJson($url, ['egg' => array_merge($file, ['meta' => ['version' => 'PTDL_v9']])])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'InvalidFileUploadException');

        $reserved = $file;
        $reserved['variables'][] = array_merge($file['variables'][0], ['env_variable' => 'SERVER_MEMORY']);
        $this->postJson($url, ['egg' => $reserved])->assertStatus(422);

        $twice = $file;
        $twice['variables'][] = $file['variables'][0];
        $this->postJson($url, ['egg' => $twice])->assertStatus(422)->assertJsonPath('errors.0.code', 'InvalidEgg');

        $this->postJson($url, ['egg' => array_merge($file, ['docker_images' => []])])->assertStatus(422);

        $huge = $file;
        $huge['scripts']['installation']['script'] = str_repeat('a', 1001 * 1024);
        $this->postJson($url, ['egg' => $huge])->assertStatus(422)->assertJsonPath('errors.0.code', 'EggTooLarge');

        $this->postJson($url, ['egg' => 'not an object'])->assertStatus(422);
    }

    public function testImportPreviewThenImportCreatesANewEgg(): void
    {
        $source = $this->egg();
        $nest = Nest::query()->findOrFail($source->nest_id);
        $file = $this->export($source)['egg'];
        $file['name'] = 'Imported ' . uniqid();
        $eggs = Egg::query()->count();

        $writes = $this->eggWrites(function () use ($nest, $file) {
            $this->postJson("/api/application/nests/{$nest->id}/eggs/import/preview", ['egg' => $file])
                ->assertOk()
                ->assertJsonPath('nest_id', $nest->id)
                ->assertJsonPath('same_name_eggs', [])
                ->assertJsonCount(2, 'diff.variables.added');
        });
        $this->assertSame(0, $writes);
        $this->assertSame($eggs, Egg::query()->count());

        $response = $this->postJson("/api/application/nests/{$nest->id}/eggs/import", ['egg' => $file])
            ->assertCreated()
            ->assertJsonPath('object', 'egg')
            ->assertJsonPath('attributes.name', $file['name'])
            ->assertJsonPath('attributes.nest', $nest->id);

        $created = Egg::query()->findOrFail($response->json('attributes.id'));
        $this->assertNotSame($source->uuid, $created->uuid);
        $this->assertSame(2, $created->variables()->count());
        $this->assertSame($this->export($created)['fingerprint'], $response->json('meta.fingerprint'));

        $this->postJson("/api/application/nests/{$nest->id}/eggs/import/preview", ['egg' => $file])
            ->assertOk()
            ->assertJsonPath('same_name_eggs', [$created->id]);
    }

    public function testImportIntoUnknownNestIs404(): void
    {
        $file = $this->export($this->egg())['egg'];

        $this->postJson('/api/application/nests/999999/eggs/import', ['egg' => $file])->assertNotFound()->assertJsonPath('errors.0.code', 'NestNotFound');
    }
}
