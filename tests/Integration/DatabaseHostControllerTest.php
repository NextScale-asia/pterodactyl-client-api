<?php

namespace Pterodactyl\Tests\Integration\Api\Application\Users;

use Pterodactyl\Models\Node;
use Illuminate\Http\Response;
use Pterodactyl\Models\Database;
use Pterodactyl\Models\DatabaseHost;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

/**
 * Runs inside a Pterodactyl panel checkout with this package installed;
 * see scripts/test-in-panel.sh. The "database host" is the test database server itself,
 * reached with a throwaway account so the panel's live connection check really runs.
 */
class DatabaseHostControllerTest extends ApplicationApiIntegrationTestCase
{
    /** See UserApiKeyControllerTest: the panel's handler rolls back open transactions on 4xx. */
    protected array $connectionsToTransact = [];

    private const USER = 'pca_dbhost';
    private const PASSWORD = 'pca-dbhost-secret';

    public function setUp(): void
    {
        parent::setUp();

        // The panel opens its connection on the host's `mysql` schema, like a real host account would be granted.
        DB::statement(sprintf("CREATE USER IF NOT EXISTS '%s'@'%%' IDENTIFIED BY '%s'", self::USER, self::PASSWORD));
        DB::statement(sprintf("GRANT ALL PRIVILEGES ON *.* TO '%s'@'%%' WITH GRANT OPTION", self::USER));
        // No wrapping transaction (see above), so rows of earlier tests are still there.
        Database::query()->delete();
        DatabaseHost::query()->delete();
    }

    private function body(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Shared MariaDB',
            'host' => config('database.connections.mysql.host'),
            'port' => (int) config('database.connections.mysql.port'),
            'username' => self::USER,
            'password' => self::PASSWORD,
        ], $overrides);
    }

    private function host(array $attributes = []): DatabaseHost
    {
        return DatabaseHost::factory()->create(array_merge([
            'host' => config('database.connections.mysql.host'),
            'port' => (int) config('database.connections.mysql.port'),
            'username' => self::USER,
            'password' => encrypt(self::PASSWORD),
        ], $attributes));
    }

    public function testHostIsCreatedWithEncryptedPasswordThatIsNeverReturned(): void
    {
        $node = Node::factory()->for(\Pterodactyl\Models\Location::factory())->create();

        $response = $this->postJson('/api/application/database-hosts', $this->body(['node_id' => $node->id]))
            ->assertCreated()
            ->assertJsonPath('object', 'database_host')
            ->assertJsonPath('attributes.name', 'Shared MariaDB')
            ->assertJsonPath('attributes.username', self::USER)
            ->assertJsonPath('attributes.node', $node->id)
            ->assertJsonPath('attributes.databases_count', 0);

        $this->assertStringNotContainsString(self::PASSWORD, $response->getContent());
        $this->assertArrayNotHasKey('password', $response->json('attributes'));

        $host = DatabaseHost::query()->sole();
        $this->assertNotSame(self::PASSWORD, $host->password);
        $this->assertSame(self::PASSWORD, decrypt($host->password));
    }

    public function testUnreachableOrRefusedHostIsA400AndNothingIsSaved(): void
    {
        $this->postJson('/api/application/database-hosts', $this->body(['password' => 'wrong-password']))
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('errors.0.code', 'DisplayException');

        $this->assertSame(0, DatabaseHost::query()->count());
    }

    public function testListAndViewReportDatabaseCounts(): void
    {
        $server = $this->createServerModel();
        $used = $this->host(['name' => 'used']);
        $this->host(['name' => 'empty']);
        Database::factory()->times(2)->create(['server_id' => $server->id, 'database_host_id' => $used->id]);

        $response = $this->getJson('/api/application/database-hosts')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', 2);

        $this->assertSame(
            ['used' => 2, 'empty' => 0],
            collect($response->json('data'))->pluck('attributes.databases_count', 'attributes.name')->all()
        );
        $this->assertStringNotContainsString('password', $response->getContent());

        $this->getJson("/api/application/database-hosts/{$used->id}")
            ->assertOk()
            ->assertJsonPath('attributes.id', $used->id)
            ->assertJsonPath('attributes.databases_count', 2);
        $this->getJson('/api/application/database-hosts/999999')->assertNotFound();
        $this->getJson('/api/application/database-hosts?per_page=101')->assertUnprocessable();
    }

    public function testUpdateChangesOnlySentFieldsAndKeepsThePasswordWhenEmpty(): void
    {
        $node = Node::factory()->for(\Pterodactyl\Models\Location::factory())->create();
        $host = $this->host(['name' => 'before', 'node_id' => $node->id]);

        $this->patchJson("/api/application/database-hosts/{$host->id}", ['name' => 'after', 'password' => ''])
            ->assertOk()
            ->assertJsonPath('attributes.name', 'after')
            ->assertJsonPath('attributes.node', $node->id);
        $this->assertSame(self::PASSWORD, decrypt($host->refresh()->password));

        $this->patchJson("/api/application/database-hosts/{$host->id}", ['node_id' => null])
            ->assertOk()
            ->assertJsonPath('attributes.node', null);

        // A password the host refuses rolls the whole update back.
        $this->patchJson("/api/application/database-hosts/{$host->id}", ['name' => 'broken', 'password' => 'wrong-password'])
            ->assertStatus(Response::HTTP_BAD_REQUEST);
        $host->refresh();
        $this->assertSame('after', $host->name);
        $this->assertSame(self::PASSWORD, decrypt($host->password));
    }

    public function testHostWithDatabasesCannotBeDeleted(): void
    {
        $server = $this->createServerModel();
        $used = $this->host();
        $empty = $this->host();
        Database::factory()->create(['server_id' => $server->id, 'database_host_id' => $used->id]);

        $this->deleteJson("/api/application/database-hosts/{$used->id}")->assertStatus(Response::HTTP_BAD_REQUEST);
        $this->assertTrue(DatabaseHost::query()->whereKey($used->id)->exists());

        $this->deleteJson("/api/application/database-hosts/{$empty->id}")->assertNoContent();
        $this->assertFalse(DatabaseHost::query()->whereKey($empty->id)->exists());
    }

    public function testBodyIsValidated(): void
    {
        $this->postJson('/api/application/database-hosts', $this->body(['host' => 'db.example.com; DROP']))
            ->assertUnprocessable();
        $this->postJson('/api/application/database-hosts', $this->body(['port' => 70000]))->assertUnprocessable();
        $this->postJson('/api/application/database-hosts', $this->body(['password' => '']))->assertUnprocessable();
        $this->postJson('/api/application/database-hosts', $this->body(['node_id' => 999999]))->assertUnprocessable();
        $this->assertSame(0, DatabaseHost::query()->count());
    }

    public static function aclProvider(): array
    {
        return [
            'none' => [AdminAcl::NONE, Response::HTTP_FORBIDDEN, Response::HTTP_FORBIDDEN],
            'read' => [AdminAcl::READ, Response::HTTP_OK, Response::HTTP_FORBIDDEN],
            'read+write' => [AdminAcl::READ | AdminAcl::WRITE, Response::HTTP_OK, Response::HTTP_CREATED],
        ];
    }

    #[DataProvider('aclProvider')]
    public function testDatabaseHostsAclIsEnforced(int $permission, int $read, int $write): void
    {
        $this->createNewDefaultApiKey($this->getApiUser(), ['r_database_hosts' => $permission]);

        $this->getJson('/api/application/database-hosts')->assertStatus($read);
        $this->postJson('/api/application/database-hosts', $this->body())->assertStatus($write);
    }
}
