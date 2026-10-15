<?php

namespace Pterodactyl\Tests\Integration\Api\Application\Users;

use Pterodactyl\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

/**
 * Only meaningful after `php artisan optimize` (ROUTE_CACHE=1 scripts/test-in-panel.sh), which
 * caches routes and config like a production addon update does. The script caches the config with
 * PTERO_CLIENT_API_EGG_WRITE=true and runs phpunit without it, so the switch must come from the
 * cached config file, not from env() at runtime.
 */
class RouteCacheTest extends ApplicationApiIntegrationTestCase
{
    /** See UserApiKeyControllerTest: the panel's handler rolls back open transactions on 4xx. */
    protected array $connectionsToTransact = [];

    public function setUp(): void
    {
        parent::setUp();

        if (!$this->app->routesAreCached() || !$this->app->configurationIsCached()) {
            $this->markTestSkipped('Routes and config are not cached; run with ROUTE_CACHE=1.');
        }
    }

    public function testCachedConfigCarriesTheSwitch(): void
    {
        $this->assertFalse(getenv('PTERO_CLIENT_API_EGG_WRITE'));
        $this->assertTrue(config('pterodactyl-client-api.egg_write.enabled'));

        $this->getJson('/api/application/addon/info')
            ->assertOk()
            ->assertJsonPath('egg_write_enabled', true);
    }

    public function testMissingModelHandlersSurviveRouteCache(): void
    {
        $this->getJson('/api/application/eggs/999999/export')
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'EggNotFound');
        $this->postJson('/api/application/eggs/999999/update-import/preview', ['egg' => []])
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'EggNotFound');
        $this->deleteJson('/api/application/nests/999999')
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'NestNotFound');
    }
}
