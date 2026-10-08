<?php

namespace Pterodactyl\Tests\Integration\Api\Application\Users;

use Pterodactyl\Models\Egg;
use Illuminate\Http\Response;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

/**
 * Runs inside a Pterodactyl panel checkout with this package installed;
 * see scripts/test-in-panel.sh.
 */
class EggVariableControllerTest extends ApplicationApiIntegrationTestCase
{
    /** See UserApiKeyControllerTest: the panel's handler rolls back open transactions on 4xx. */
    protected array $connectionsToTransact = [];

    private function egg(): Egg
    {
        return $this->cloneEggAndVariables(
            Egg::query()->where('author', 'support@pterodactyl.io')->where('name', 'Bungeecord')->firstOrFail()
        );
    }

    private function body(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Timezone',
            'description' => 'IANA timezone, empty = node timezone',
            'default_value' => '',
            'user_viewable' => true,
            'user_editable' => true,
            'rules' => 'nullable|string|max:64',
        ], $overrides);
    }

    public function testVariableIsCreatedThenUpdatedInPlace(): void
    {
        $egg = $this->egg();
        $before = $egg->variables()->count();

        $this->putJson("/api/application/eggs/{$egg->id}/variables/TZ", $this->body())
            ->assertCreated()
            // the panel's EggVariableTransformer reports its resource name as "egg"
            ->assertJsonPath('object', 'egg')
            ->assertJsonPath('attributes.env_variable', 'TZ')
            ->assertJsonPath('attributes.user_editable', true);

        $this->putJson("/api/application/eggs/{$egg->id}/variables/TZ", $this->body(['user_editable' => false, 'default_value' => 'UTC']))
            ->assertOk()
            ->assertJsonPath('attributes.user_editable', false)
            ->assertJsonPath('attributes.default_value', 'UTC');

        $this->assertSame($before + 1, $egg->variables()->count());
        $variable = EggVariable::query()->where('egg_id', $egg->id)->where('env_variable', 'TZ')->sole();
        $this->assertSame('Timezone', $variable->name);
        $this->assertSame('nullable|string|max:64', $variable->rules);
        $this->assertTrue($variable->user_viewable);
    }

    public function testOtherVariablesOfTheEggAreUntouched(): void
    {
        $egg = $this->egg();
        $snapshot = $egg->variables()->orderBy('id')->get(['id', 'env_variable', 'default_value', 'rules'])->toArray();

        $this->putJson("/api/application/eggs/{$egg->id}/variables/TZ", $this->body())->assertCreated();

        $this->assertSame(
            $snapshot,
            $egg->variables()->where('env_variable', '!=', 'TZ')->orderBy('id')->get(['id', 'env_variable', 'default_value', 'rules'])->toArray()
        );
    }

    public function testReservedNameIsRefused(): void
    {
        $egg = $this->egg();

        $this->putJson("/api/application/eggs/{$egg->id}/variables/SERVER_MEMORY", $this->body())
            ->assertStatus(Response::HTTP_BAD_REQUEST);
        $this->assertFalse($egg->variables()->where('env_variable', 'SERVER_MEMORY')->exists());
    }

    public function testInvalidRuleIsRefused(): void
    {
        $egg = $this->egg();

        $this->putJson("/api/application/eggs/{$egg->id}/variables/TZ", $this->body(['rules' => 'nullable|not_a_rule']))
            ->assertStatus(Response::HTTP_BAD_REQUEST);
        $this->assertFalse($egg->variables()->where('env_variable', 'TZ')->exists());
    }

    public function testBodyIsValidated(): void
    {
        $egg = $this->egg();

        $this->putJson("/api/application/eggs/{$egg->id}/variables/TZ", ['name' => ''])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testMalformedNameOrUnknownEggReturns404(): void
    {
        $egg = $this->egg();

        $this->putJson("/api/application/eggs/{$egg->id}/variables/1TZ", $this->body())->assertNotFound();
        $this->putJson("/api/application/eggs/{$egg->id}/variables/T-Z", $this->body())->assertNotFound();
        $this->putJson('/api/application/eggs/999999/variables/TZ', $this->body())->assertNotFound();
    }

    public static function aclProvider(): array
    {
        return [
            'none' => [AdminAcl::NONE, Response::HTTP_FORBIDDEN],
            'read' => [AdminAcl::READ, Response::HTTP_FORBIDDEN],
            'write' => [AdminAcl::WRITE, Response::HTTP_CREATED],
            'read+write' => [AdminAcl::READ | AdminAcl::WRITE, Response::HTTP_CREATED],
        ];
    }

    #[DataProvider('aclProvider')]
    public function testEggsAclIsEnforced(int $permission, int $status): void
    {
        $this->createNewDefaultApiKey($this->getApiUser(), ['r_eggs' => $permission]);
        $egg = $this->egg();

        $this->putJson("/api/application/eggs/{$egg->id}/variables/TZ", $this->body())->assertStatus($status);
    }
}
