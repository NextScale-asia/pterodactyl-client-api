<?php

namespace Pterodactyl\Tests\Integration\Api\Application\Users;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Services\Eggs\Sharing\EggExporterService;
use Pterodactyl\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

/**
 * Runs inside a Pterodactyl panel checkout with this package installed;
 * see scripts/test-in-panel.sh.
 */
class EggWriteGateTest extends ApplicationApiIntegrationTestCase
{
    /** See UserApiKeyControllerTest: the panel's handler rolls back open transactions on 4xx. */
    protected array $connectionsToTransact = [];

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

    public function testAddonInfoReportsVersionAndSwitch(): void
    {
        config()->set('pterodactyl-client-api.egg_write.enabled', false);
        $this->createNewDefaultApiKey($this->getApiUser(), ['r_eggs' => AdminAcl::NONE, 'r_nests' => AdminAcl::NONE, 'r_servers' => AdminAcl::NONE]);

        $this->getJson('/api/application/addon/info')
            ->assertOk()
            ->assertJsonPath('version', '2.3.2')
            ->assertJsonPath('egg_write_enabled', false)
            ->assertJsonPath('egg_write_key_allowed', false)
            ->assertJsonFragment(['egg-update-import'])
            ->assertJsonFragment(['nest-write']);

        config()->set('pterodactyl-client-api.egg_write.enabled', true);
        $this->getJson('/api/application/addon/info')
            ->assertOk()
            ->assertJsonPath('egg_write_enabled', true)
            ->assertJsonPath('egg_write_key_allowed', true);
    }

    public function testEveryWriteRouteIsClosedWhileDisabled(): void
    {
        config()->set('pterodactyl-client-api.egg_write.enabled', false);
        $egg = $this->egg();
        $nest = Nest::query()->findOrFail($egg->nest_id);
        $fingerprint = $this->fingerprint($egg);
        $export = $this->getJson("/api/application/eggs/{$egg->id}/export")->json('egg');

        $calls = [
            ['postJson', "/api/application/eggs/{$egg->id}/update-import/preview", ['egg' => $export]],
            ['postJson', "/api/application/eggs/{$egg->id}/update-import", ['egg' => $export, 'expected_fingerprint' => $fingerprint, 'expected_removed' => []]],
            ['patchJson', "/api/application/eggs/{$egg->id}", ['name' => 'x', 'expected_fingerprint' => $fingerprint]],
            ['deleteJson', "/api/application/eggs/{$egg->id}", ['expected_fingerprint' => $fingerprint]],
            ['deleteJson', "/api/application/eggs/{$egg->id}/variables/SERVER_JARFILE", ['expected_fingerprint' => $fingerprint]],
            ['postJson', "/api/application/eggs/{$egg->id}/server-variables/restore", ['values' => [['server_id' => 1, 'env' => 'X', 'value' => 'y']]]],
            ['postJson', "/api/application/nests/{$nest->id}/eggs/import/preview", ['egg' => $export]],
            ['postJson', "/api/application/nests/{$nest->id}/eggs/import", ['egg' => $export]],
            ['postJson', '/api/application/nests', ['name' => 'Gate test']],
            ['patchJson', "/api/application/nests/{$nest->id}", ['name' => 'Gate test']],
            ['deleteJson', "/api/application/nests/{$nest->id}", []],
        ];

        foreach ($calls as [$method, $url, $body]) {
            $this->{$method}($url, $body)
                ->assertForbidden()
                ->assertJsonPath('errors.0.code', 'EggWriteDisabled');
        }

        $this->assertSame($fingerprint, $this->fingerprint($egg));
        $this->assertTrue(Egg::query()->whereKey($egg->id)->exists());
        $this->assertTrue(Nest::query()->whereKey($nest->id)->exists());
    }

    public function testKeyAllowList(): void
    {
        config()->set('pterodactyl-client-api.egg_write.enabled', true);
        config()->set('pterodactyl-client-api.egg_write.key_identifiers', ['ptla000000000000']);
        $egg = $this->egg();
        $body = ['name' => 'Renamed', 'expected_fingerprint' => $this->fingerprint($egg)];

        $this->patchJson("/api/application/eggs/{$egg->id}", $body)
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'EggWriteKeyNotAllowed');

        config()->set('pterodactyl-client-api.egg_write.key_identifiers', [$this->getApiKey()->identifier]);
        $this->patchJson("/api/application/eggs/{$egg->id}", $body)->assertOk();
    }

    public function testNoRouteSharesMethodAndUriWithAnother(): void
    {
        $seen = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $key = $method . ' ' . preg_replace('/\{(\w+)(:\w+)?\??\}/', '{}', $route->uri());
                $this->assertArrayNotHasKey($key, $seen, "{$key} is registered twice");
                $seen[$key] = true;
            }
        }

        $this->assertArrayHasKey('PATCH api/application/eggs/{}', $seen);
        $this->assertArrayHasKey('DELETE api/application/nests/{}', $seen);
    }

    public function testExportStaysReadableWhileDisabled(): void
    {
        config()->set('pterodactyl-client-api.egg_write.enabled', false);
        $egg = $this->egg();

        $this->getJson("/api/application/eggs/{$egg->id}/export")->assertOk();
    }

    public static function eggAclProvider(): array
    {
        return [
            'none' => [AdminAcl::NONE, Response::HTTP_FORBIDDEN],
            'read' => [AdminAcl::READ, Response::HTTP_FORBIDDEN],
            'write' => [AdminAcl::WRITE, Response::HTTP_OK],
            'read+write' => [AdminAcl::READ | AdminAcl::WRITE, Response::HTTP_OK],
        ];
    }

    #[DataProvider('eggAclProvider')]
    public function testEggsAclIsEnforced(int $permission, int $status): void
    {
        config()->set('pterodactyl-client-api.egg_write.enabled', true);
        $egg = $this->egg();
        // built without a request: the app (and its resolved API key) is reused for the whole test
        $export = json_decode(app(EggExporterService::class)->handle($egg->id), true);

        $this->createNewDefaultApiKey($this->getApiUser(), ['r_eggs' => $permission]);
        $response = $this->postJson("/api/application/eggs/{$egg->id}/update-import/preview", ['egg' => $export])->assertStatus($status);
        if ($status === Response::HTTP_FORBIDDEN) {
            // the panel's own ACL answer, before the addon gate is consulted
            $this->assertNotSame('EggWriteDisabled', $response->json('errors.0.code'));
        }
    }

    public static function exportAclProvider(): array
    {
        return [
            'none' => [AdminAcl::NONE, Response::HTTP_FORBIDDEN],
            'read' => [AdminAcl::READ, Response::HTTP_OK],
            'write' => [AdminAcl::WRITE, Response::HTTP_FORBIDDEN],
        ];
    }

    #[DataProvider('exportAclProvider')]
    public function testExportNeedsEggsRead(int $permission, int $status): void
    {
        $egg = $this->egg();
        $this->createNewDefaultApiKey($this->getApiUser(), ['r_eggs' => $permission]);

        $this->getJson("/api/application/eggs/{$egg->id}/export")->assertStatus($status);
    }

    public static function nestAclProvider(): array
    {
        return [
            'none' => [AdminAcl::NONE, Response::HTTP_FORBIDDEN],
            'read' => [AdminAcl::READ, Response::HTTP_FORBIDDEN],
            'write' => [AdminAcl::WRITE, Response::HTTP_CREATED],
        ];
    }

    #[DataProvider('nestAclProvider')]
    public function testNestsAclIsEnforced(int $permission, int $status): void
    {
        config()->set('pterodactyl-client-api.egg_write.enabled', true);
        $this->createNewDefaultApiKey($this->getApiUser(), ['r_nests' => $permission, 'r_eggs' => AdminAcl::NONE]);

        $this->postJson('/api/application/nests', ['name' => 'ACL nest'])->assertStatus($status);
    }
}
