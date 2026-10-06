<?php

namespace Pterodactyl\Tests\Integration\Api\Application\Users;

use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Lcobucci\JWT\Configuration;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ActivityLog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Pterodactyl\Models\ServerTransfer;
use Pterodactyl\Services\Servers\ServerDeletionService;
use Byzic\PterodactylClientApi\Http\Controllers\ServerTransferController;
use Pterodactyl\Http\Controllers\Api\Application\Servers\ServerController as PanelServerController;
use Lcobucci\JWT\Signer\Key\InMemory;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use PHPUnit\Framework\Attributes\DataProvider;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Illuminate\Http\Client\ConnectionException;
use Pterodactyl\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

/**
 * Runs inside a Pterodactyl panel checkout with this package installed;
 * see scripts/test-in-panel.sh. Wings is faked with Http::fake().
 */
class ServerTransferControllerTest extends ApplicationApiIntegrationTestCase
{
    /**
     * The panel's exception handler rolls back every open transaction when it renders
     * an error, which would wipe the test's own wrapping transaction (and its fixtures)
     * after the first 4xx. Run without it, like the panel's client API tests.
     */
    protected array $connectionsToTransact = [];

    private const TICKET = '0123456789abcdef0123456789abcdef';

    public function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * @return array{Server, Node, Allocation}
     */
    private function fixture(): array
    {
        $server = $this->createServerModel();
        $target = Node::factory()->create(['location_id' => $server->location->id]);
        $allocation = Allocation::factory()->create(['node_id' => $target->id]);

        return [$server, $target, $allocation];
    }

    private function url(Server $server): string
    {
        return "/api/application/servers/{$server->id}/transfer";
    }

    private function cancelUrl(Server $server): string
    {
        return "/api/application/servers/{$server->id}/transfer/cancel";
    }

    private function relayUrl(int $nodeId, int $port = 781, string $ticket = self::TICKET): string
    {
        return "http://127.0.0.1:{$port}/relay/{$nodeId}/{$ticket}/api/transfers";
    }

    private function wings(Node $node, string $path): string
    {
        return $node->getConnectionAddress() . $path;
    }

    public function testTransferIsStartedThroughTheRelay(): void
    {
        [$server, $target, $allocation] = $this->fixture();
        $extra = Allocation::factory()->create(['node_id' => $target->id]);
        $notifyUrl = $this->wings($server->node, "/api/servers/{$server->uuid}/transfer");
        Http::fake([$notifyUrl => Http::response('', 202)]);

        $response = $this->postJson($this->url($server), [
            'node_id' => $target->id,
            'allocation_id' => $allocation->id,
            'allocation_additional' => [$extra->id],
            'relay_url' => $this->relayUrl($target->id),
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('object', ServerTransfer::RESOURCE_NAME)
            ->assertJsonPath('attributes.old_node', $server->node_id)
            ->assertJsonPath('attributes.new_node', $target->id)
            ->assertJsonPath('attributes.new_allocation', $allocation->id)
            ->assertJsonPath('attributes.new_additional_allocations', [$extra->id])
            ->assertJsonPath('attributes.successful', null)
            ->assertJsonPath('meta.uncertain', false);

        $transfer = ServerTransfer::query()->findOrFail($response->json('attributes.id'));
        $this->assertSame($server->allocation_id, $transfer->old_allocation);
        $this->assertSame($server->id, $allocation->refresh()->server_id);
        $this->assertSame($server->id, $extra->refresh()->server_id);
        $this->assertNotNull($server->refresh()->transfer);

        // The same request DaemonTransferRepository::notify() sends, with only `url` changed.
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($server, $target, $notifyUrl) {
            $this->assertSame($notifyUrl, $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertSame(['Bearer ' . $server->node->getDecryptedKey()], $request->header('Authorization'));
            $this->assertSame(['server_id', 'url', 'token', 'server'], array_keys($request->data()));
            $this->assertSame($server->uuid, $request['server_id']);
            $this->assertSame($this->relayUrl($target->id), $request['url']);
            $this->assertSame(['uuid' => $server->uuid, 'start_on_completion' => false], $request['server']);
            $this->assertStringStartsWith('Bearer ', $request['token']);

            // JWT signed with the TARGET node's key, subject = server uuid, transfer scope, 15 min.
            $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($target->getDecryptedKey()));
            $token = $config->parser()->parse(substr($request['token'], 7));
            $this->assertTrue($config->validator()->validate($token, new SignedWith($config->signer(), $config->verificationKey())));
            $this->assertSame($server->uuid, $token->claims()->get('sub'));
            $this->assertSame('transfer', $token->claims()->get('scope'));
            $ttl = $token->claims()->get('exp')->getTimestamp() - time();
            $this->assertTrue($ttl > 14 * 60 && $ttl <= 15 * 60, "unexpected JWT ttl {$ttl}");

            return true;
        });

        $this->assertActivityFor('server:transfer.start', $this->getApiUser(), $server);
        $activity = ActivityLog::query()->where('event', 'server:transfer.start')->latest('id')->firstOrFail();
        // Server activity is visible to the server's users: no node/allocation ids.
        $this->assertSame(['transfer_id' => $transfer->id], $activity->properties->toArray());
        $log = json_encode($activity->properties);
        $this->assertStringNotContainsString(self::TICKET, $log);
        $this->assertStringNotContainsString('Bearer', $log);
        $this->assertStringNotContainsString('eyJ', $log);
    }

    public function testRelayIsOptionalWhenNotRequired(): void
    {
        config()->set('pterodactyl-client-api.transfer.require_relay', false);
        [$server, $target, $allocation] = $this->fixture();
        Http::fake(['*' => Http::response('', 202)]);

        $this->postJson($this->url($server), ['node_id' => $target->id, 'allocation_id' => $allocation->id])
            ->assertStatus(202);

        Http::assertSent(fn (Request $request) => $request['url'] === $target->getConnectionAddress() . '/api/transfers');
    }

    public function testAllocationOfAnotherNodeOrInUseIsRejected(): void
    {
        [$server, $target, $allocation] = $this->fixture();
        $foreign = Allocation::factory()->create(['node_id' => $server->node_id]);
        $other = $this->createServerModel(['node_id' => $target->id]);
        $data = ['node_id' => $target->id, 'relay_url' => $this->relayUrl($target->id)];

        $this->postJson($this->url($server), $data + ['allocation_id' => $foreign->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.meta.source_field', 'allocation_id');

        $this->postJson($this->url($server), $data + ['allocation_id' => $other->allocation_id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.meta.source_field', 'allocation_id');

        $this->postJson($this->url($server), $data + ['allocation_id' => $allocation->id, 'allocation_additional' => [$foreign->id]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.meta.source_field', 'allocation_additional.0');

        $this->postJson($this->url($server), $data + ['allocation_id' => 999999])->assertUnprocessable();

        $this->assertSame(0, ServerTransfer::query()->where('server_id', $server->id)->count());
        $this->assertNull($allocation->refresh()->server_id);
        Http::assertNothingSent();
    }

    public function testTargetMustDifferAndBeViable(): void
    {
        [$server, $target, $allocation] = $this->fixture();
        $local = Allocation::factory()->create(['node_id' => $server->node_id]);

        $this->postJson($this->url($server), [
            'node_id' => $server->node_id,
            'allocation_id' => $local->id,
            'relay_url' => $this->relayUrl($server->node_id),
        ])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'node_id');

        $target->update(['memory' => 100]);
        $this->postJson($this->url($server), [
            'node_id' => $target->id,
            'allocation_id' => $allocation->id,
            'relay_url' => $this->relayUrl($target->id),
        ])->assertUnprocessable()->assertJsonPath('errors.0.code', 'node_not_viable');

        Http::assertNothingSent();
    }

    public static function badRelayUrls(): array
    {
        return [
            'missing' => [fn (int $node) => null],
            'empty' => [fn (int $node) => ''],
            'wrong port' => [fn (int $node) => "http://127.0.0.1:7811/relay/{$node}/" . self::TICKET . '/api/transfers'],
            'no port' => [fn (int $node) => "http://127.0.0.1/relay/{$node}/" . self::TICKET . '/api/transfers'],
            'wrong node' => [fn (int $node) => 'http://127.0.0.1:781/relay/' . ($node + 1) . '/' . self::TICKET . '/api/transfers'],
            'not loopback' => [fn (int $node) => "http://10.0.0.5:781/relay/{$node}/" . self::TICKET . '/api/transfers'],
            'localhost name' => [fn (int $node) => "http://localhost:781/relay/{$node}/" . self::TICKET . '/api/transfers'],
            'https' => [fn (int $node) => "https://127.0.0.1:781/relay/{$node}/" . self::TICKET . '/api/transfers'],
            'short ticket' => [fn (int $node) => "http://127.0.0.1:781/relay/{$node}/abcdef/api/transfers"],
            'uppercase ticket' => [fn (int $node) => "http://127.0.0.1:781/relay/{$node}/" . strtoupper(self::TICKET) . '/api/transfers'],
            'wrong path' => [fn (int $node) => "http://127.0.0.1:781/relay/{$node}/" . self::TICKET . '/api/transfer'],
            'trailing' => [fn (int $node) => "http://127.0.0.1:781/relay/{$node}/" . self::TICKET . '/api/transfers?x=1'],
            'credentials' => [fn (int $node) => "http://evil@127.0.0.1:781/relay/{$node}/" . self::TICKET . '/api/transfers'],
            // TrimStrings strips a trailing newline, so put a line break before more text.
            'newline' => [fn (int $node) => "http://127.0.0.1:781/relay/{$node}/" . self::TICKET . "/api/transfers\nhttp://10.0.0.5/"],
        ];
    }

    #[DataProvider('badRelayUrls')]
    public function testRelayUrlIsValidated(\Closure $url): void
    {
        [$server, $target, $allocation] = $this->fixture();

        $this->postJson($this->url($server), array_filter([
            'node_id' => $target->id,
            'allocation_id' => $allocation->id,
            'relay_url' => $url($target->id),
        ], fn ($value) => !is_null($value)))
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.meta.source_field', 'relay_url');

        $this->assertSame(0, ServerTransfer::query()->where('server_id', $server->id)->count());
        Http::assertNothingSent();
    }

    public function testRelayPortComesFromConfig(): void
    {
        config()->set('pterodactyl-client-api.transfer.relay_port', 782);
        [$server, $target, $allocation] = $this->fixture();
        Http::fake(['*' => Http::response('', 202)]);
        $data = ['node_id' => $target->id, 'allocation_id' => $allocation->id];

        $this->postJson($this->url($server), $data + ['relay_url' => $this->relayUrl($target->id)])->assertUnprocessable();
        $this->postJson($this->url($server), $data + ['relay_url' => $this->relayUrl($target->id, 782)])->assertStatus(202);
    }

    public function testPendingTransferReturns409(): void
    {
        [$server, $target, $allocation] = $this->fixture();
        ServerTransfer::factory()->create([
            'server_id' => $server->id,
            'old_node' => $server->node_id,
            'new_node' => $target->id,
            'old_allocation' => $server->allocation_id,
            'new_allocation' => Allocation::factory()->create(['node_id' => $target->id, 'server_id' => $server->id])->id,
        ]);

        $this->postJson($this->url($server), [
            'node_id' => $target->id,
            'allocation_id' => $allocation->id,
            'relay_url' => $this->relayUrl($target->id),
        ])->assertStatus(409);

        $this->assertSame(1, ServerTransfer::query()->where('server_id', $server->id)->count());
        $this->assertNull($allocation->refresh()->server_id);
        Http::assertNothingSent();
    }

    public static function uncertainOutcomes(): array
    {
        return [
            'timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
            'gateway timeout from a proxy' => [fn () => Http::response('', 504)],
            'cloudflare 524' => [fn () => Http::response('', 524)],
            'redirect' => [fn () => Http::response('', 302, ['Location' => 'https://elsewhere.example/api/transfers'])],
        ];
    }

    #[DataProvider('uncertainOutcomes')]
    public function testUncertainNotifyKeepsTheTransfer(\Closure $wings): void
    {
        [$server, $target, $allocation] = $this->fixture();
        Http::fake(['*' => $wings]);

        $response = $this->postJson($this->url($server), [
            'node_id' => $target->id,
            'allocation_id' => $allocation->id,
            'relay_url' => $this->relayUrl($target->id),
        ]);

        $response->assertStatus(202)->assertJsonPath('meta.uncertain', true);

        $transfer = ServerTransfer::query()->findOrFail($response->json('attributes.id'));
        $this->assertNull($transfer->successful);
        $this->assertSame($server->id, $allocation->refresh()->server_id);
    }

    public static function rejectedOutcomes(): array
    {
        return [
            'already transferring' => [409],
            'unauthorized' => [401],
            'failed to stop' => [500],
        ];
    }

    #[DataProvider('rejectedOutcomes')]
    public function testExplicitWingsRejectionRollsBack(int $status): void
    {
        [$server, $target, $allocation] = $this->fixture();
        Http::fake(['*' => Http::response(['error' => 'nope'], $status)]);

        $this->postJson($this->url($server), [
            'node_id' => $target->id,
            'allocation_id' => $allocation->id,
            'relay_url' => $this->relayUrl($target->id),
        ])
            ->assertStatus(502)
            ->assertJsonPath('errors.0.code', 'wings_rejected')
            ->assertJsonPath('errors.0.meta.wings_status', $status);

        $this->assertSame(0, ServerTransfer::query()->where('server_id', $server->id)->count());
        $this->assertNull($allocation->refresh()->server_id);
        $this->assertNull($server->refresh()->transfer);
    }

    public function testShowReturnsTheLatestTransferIncludingFinished(): void
    {
        [$server, $target, $allocation] = $this->fixture();

        $this->getJson($this->url($server))->assertNotFound()->assertJsonPath('errors.0.code', 'no_transfer');

        $first = $this->makeTransfer($server, $target, $allocation, ['successful' => false]);
        $second = $this->makeTransfer($server, $target, $allocation, ['successful' => true]);

        $this->getJson($this->url($server))
            ->assertOk()
            ->assertJsonPath('attributes.id', $second->id)
            ->assertJsonPath('attributes.successful', true)
            ->assertJsonMissingPath('meta.integrity');

        $this->assertNotSame($first->id, $second->id);
    }

    public function testIntegrityAfterSuccess(): void
    {
        [$server, $target, $allocation] = $this->fixture();
        $oldAllocation = $server->allocation_id;
        $transfer = $this->makeTransfer($server, $target, $allocation, ['successful' => true]);

        // What the panel's success() callback does.
        Allocation::query()->whereKey($oldAllocation)->update(['server_id' => null]);
        $allocation->update(['server_id' => $server->id]);
        $server->update(['node_id' => $target->id, 'allocation_id' => $allocation->id]);

        $this->getJson($this->url($server) . '?verify=1')
            ->assertOk()
            ->assertJsonPath('attributes.id', $transfer->id)
            ->assertJsonPath('meta.integrity.ok', true)
            ->assertJsonPath('meta.integrity.transfer_state', 'successful')
            ->assertJsonPath('meta.integrity.node_ok', true)
            ->assertJsonPath('meta.integrity.primary_allocation_ok', true);

        // Primary allocation lost its owner (cancel raced with success): must be reported.
        $allocation->update(['server_id' => null]);

        $response = $this->getJson($this->url($server) . '?verify=1')
            ->assertOk()
            ->assertJsonPath('meta.integrity.ok', false)
            ->assertJsonPath('meta.integrity.node_ok', true)
            ->assertJsonPath('meta.integrity.primary_allocation_ok', false);

        $check = collect($response->json('meta.integrity.allocations'))->firstWhere('allocation_id', $allocation->id);
        $this->assertSame(['allocation_id' => $allocation->id, 'expected' => 'assigned', 'owned_by_this_server' => false, 'ok' => false], $check);
    }

    public function testIntegrityDetectsOldAllocationNotReleasedAfterFailure(): void
    {
        [$server, $target, $allocation] = $this->fixture();
        $this->makeTransfer($server, $target, $allocation, ['successful' => false]);
        $allocation->update(['server_id' => $server->id]); // should have been released

        $this->getJson($this->url($server) . '?verify=1')
            ->assertOk()
            ->assertJsonPath('meta.integrity.ok', false)
            ->assertJsonPath('meta.integrity.transfer_state', 'failed')
            ->assertJsonPath('meta.integrity.node_ok', true)
            ->assertJsonPath('meta.integrity.primary_allocation_ok', true);
    }

    public function testCancelRequiresAgentConfirmation(): void
    {
        [$server, $target, $allocation] = $this->fixture();
        $this->makeTransfer($server, $target, $allocation);

        $this->postJson($this->cancelUrl($server))->assertUnprocessable()
            ->assertJsonPath('errors.0.meta.source_field', 'confirm_agents_idle');
        $this->postJson($this->cancelUrl($server), ['confirm_agents_idle' => false])->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function testCancelWithoutPendingTransferReturns409(): void
    {
        [$server, $target, $allocation] = $this->fixture();
        $this->makeTransfer($server, $target, $allocation, ['successful' => false]);

        $this->postJson($this->cancelUrl($server), ['confirm_agents_idle' => true])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'no_pending_transfer');

        Http::assertNothingSent();
    }

    public function testCancelIsRefusedWhileTargetStillHasTheServer(): void
    {
        [$server, $target, $allocation] = $this->fixture();
        $transfer = $this->makeTransfer($server, $target, $allocation);
        Http::fake([
            $this->wings($target, "/api/servers/{$server->uuid}") => Http::response(['state' => 'offline'], 200),
            $this->wings($server->node, "/api/servers/{$server->uuid}") => Http::response(['state' => 'offline'], 200),
        ]);

        $this->postJson($this->cancelUrl($server), ['confirm_agents_idle' => true])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'transfer_active');

        $this->assertNull($transfer->refresh()->successful);
        $this->assertSame($server->id, $allocation->refresh()->server_id);
    }

    public static function unverifiable(): array
    {
        return [
            'target unreachable' => ['target', fn () => throw new ConnectionException('cURL error 7: Failed to connect')],
            'target 404 from a proxy' => ['target', fn () => Http::response('<html>Not Found</html>', 404)],
            'target 502' => ['target', fn () => Http::response('', 502)],
            'target rejects the node token' => ['target', fn () => Http::response(['error' => 'Unauthorized'], 401)],
            'source unreachable' => ['source', fn () => throw new ConnectionException('cURL error 28: timed out')],
            'source 503' => ['source', fn () => Http::response('', 503)],
            'target redirect' => ['target', fn () => Http::response('', 302, ['Location' => 'https://elsewhere.example/api/servers'])],
            'source redirect' => ['source', fn () => Http::response('', 301, ['Location' => 'https://elsewhere.example/api/servers'])],
        ];
    }

    #[DataProvider('unverifiable')]
    public function testCancelIsRefusedWhenAWingsCannotBeVerified(string $broken, \Closure $failure): void
    {
        [$server, $target, $allocation] = $this->fixture();
        $transfer = $this->makeTransfer($server, $target, $allocation);
        $gone = fn () => Http::response(['error' => 'The requested resource does not exist on this instance.'], 404);
        Http::fake([
            $this->wings($target, "/api/servers/{$server->uuid}") => $broken === 'target' ? $failure : $gone,
            $this->wings($server->node, "/api/servers/{$server->uuid}") => $broken === 'source' ? $failure : Http::response([], 200),
        ]);

        $this->postJson($this->cancelUrl($server), ['confirm_agents_idle' => true])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'cannot_verify')
            ->assertJsonPath('errors.0.meta.node', $broken);

        $this->assertNull($transfer->refresh()->successful);
        $this->assertSame($server->id, $allocation->refresh()->server_id);
    }

    public function testCancelMarksADeadTransferFailedAndReleasesAllocations(): void
    {
        [$server, $target, $allocation] = $this->fixture();
        $extra = Allocation::factory()->create(['node_id' => $target->id, 'server_id' => $server->id]);
        $transfer = $this->makeTransfer($server, $target, $allocation, ['new_additional_allocations' => [$extra->id]]);
        Http::fake([
            $this->wings($target, "/api/servers/{$server->uuid}") => Http::response(['error' => 'The requested resource does not exist on this instance.'], 404),
            $this->wings($server->node, "/api/servers/{$server->uuid}") => Http::response(['state' => 'offline'], 200),
        ]);

        $this->postJson($this->cancelUrl($server), ['confirm_agents_idle' => true])->assertNoContent();

        $this->assertFalse($transfer->refresh()->successful);
        $this->assertNull($allocation->refresh()->server_id);
        $this->assertNull($extra->refresh()->server_id);
        $this->assertSame($server->id, Allocation::query()->findOrFail($server->allocation_id)->server_id);
        $this->assertNull($server->refresh()->transfer);
        Http::assertSentCount(2);
        $this->assertActivityFor('server:transfer.fail', $this->getApiUser(), $server);

        $this->getJson($this->url($server) . '?verify=1')
            ->assertOk()
            ->assertJsonPath('attributes.successful', false)
            ->assertJsonPath('meta.integrity.ok', true);

        // Can be started again afterwards.
        Http::fake(['*' => Http::response('', 202)]);
        $this->postJson($this->url($server), [
            'node_id' => $target->id,
            'allocation_id' => $allocation->id,
            'relay_url' => $this->relayUrl($target->id),
        ])->assertStatus(202);
    }

    public function testCancelIsTooEarlyWhileTheTransferTokenMayBeValid(): void
    {
        [$server, $target, $allocation] = $this->fixture();
        $transfer = $this->makeTransfer($server, $target, $allocation, ['created_at' => now()->subMinutes(16)]);

        // 16 min old, minimum age 17 min (15 min JWT + 2 min skew): about 60 s to go.
        $response = $this->postJson($this->cancelUrl($server), ['confirm_agents_idle' => true])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'too_early');

        $retryAfter = $response->json('errors.0.meta.retry_after');
        $this->assertIsInt($retryAfter);
        $this->assertTrue($retryAfter > 50 && $retryAfter <= 60, "unexpected retry_after {$retryAfter}");
        $this->assertSame((string) $retryAfter, $response->headers->get('Retry-After'));

        // A brand-new transfer must wait the full 1020 s.
        ServerTransfer::query()->whereKey($transfer->id)->update(['created_at' => now()]);
        $retryAfter = $this->postJson($this->cancelUrl($server), ['confirm_agents_idle' => true])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'too_early')
            ->json('errors.0.meta.retry_after');
        $this->assertTrue($retryAfter > 1010 && $retryAfter <= 1020, "unexpected retry_after {$retryAfter}");

        $this->assertNull($transfer->refresh()->successful);
        $this->assertSame($server->id, $allocation->refresh()->server_id);
        Http::assertNothingSent();
    }

    public function testCancelMinimumAgeComesFromConfig(): void
    {
        config()->set('pterodactyl-client-api.transfer.delete_min_age_seconds', 60);
        [$server, $target, $allocation] = $this->fixture();
        $transfer = $this->makeTransfer($server, $target, $allocation, ['created_at' => now()->subMinutes(2)]);
        Http::fake([
            $this->wings($target, "/api/servers/{$server->uuid}") => Http::response(['error' => 'The requested resource does not exist on this instance.'], 404),
            $this->wings($server->node, "/api/servers/{$server->uuid}") => Http::response(['state' => 'offline'], 200),
        ]);

        $this->postJson($this->cancelUrl($server), ['confirm_agents_idle' => true])->assertNoContent();
        $this->assertFalse($transfer->refresh()->successful);
    }

    /**
     * The panel's success() callback moves the server before marking the transfer: if the
     * server row already points at another node, cancel must not release anything.
     */
    public function testCancelRefusesWhenTheServerAlreadyMovedUnderTheLock(): void
    {
        [$server, $target, $allocation] = $this->fixture();
        $transfer = $this->makeTransfer($server, $target, $allocation);
        Http::fake([
            $this->wings($target, "/api/servers/{$server->uuid}") => Http::response(['error' => 'The requested resource does not exist on this instance.'], 404),
            $this->wings($server->node, "/api/servers/{$server->uuid}") => Http::response(['state' => 'offline'], 200),
        ]);
        $server->update(['node_id' => $target->id, 'allocation_id' => $allocation->id]);
        $failEvents = ActivityLog::query()->where('event', 'server:transfer.fail')->count();

        $this->postJson($this->cancelUrl($server), ['confirm_agents_idle' => true])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'no_pending_transfer');

        $this->assertNull($transfer->refresh()->successful);
        $this->assertSame($server->id, $allocation->refresh()->server_id);
        $this->assertSame($failEvents, ActivityLog::query()->where('event', 'server:transfer.fail')->count());
    }

    /**
     * There is no DELETE …/transfer in this package: the panel's
     * DELETE /api/application/servers/{server:id}/{force?} owns that path and would DELETE
     * THE SERVER. Make sure our controller never answers it, and show what it really does.
     */
    public function testDeleteOnTheTransferPathIsThePanelsServerDelete(): void
    {
        [$server, $target, $allocation] = $this->fixture();
        $transfer = $this->makeTransfer($server, $target, $allocation);

        $route = $this->app['router']->getRoutes()->match(\Illuminate\Http\Request::create($this->url($server), 'DELETE'));
        $this->assertStringNotContainsString(ServerTransferController::class, $route->getActionName());
        $this->assertSame(PanelServerController::class . '@delete', $route->getActionName());

        foreach ($this->app['router']->getRoutes() as $candidate) {
            if (str_contains($candidate->getActionName(), ServerTransferController::class)) {
                $this->assertNotContains('DELETE', $candidate->methods(), $candidate->uri());
            }
        }

        // Dispatch it with the deletion service mocked: the panel's server delete runs
        // (force = "transfer", i.e. not forced) and the transfer is left alone.
        $this->mock(ServerDeletionService::class, function ($mock) use ($server) {
            $mock->expects('withForce')->with(false)->andReturnSelf();
            $mock->expects('handle')->withArgs(fn (Server $s) => $s->id === $server->id);
        });

        $this->deleteJson($this->url($server), ['confirm_agents_idle' => true])->assertNoContent();

        $this->assertNull($transfer->refresh()->successful);
        $this->assertSame($server->id, $allocation->refresh()->server_id);
        Http::assertNothingSent();
    }

    public function testWingsRedirectsAreNotFollowed(): void
    {
        [$server, $target, $allocation] = $this->fixture();
        $options = [];
        Http::fake(function (Request $request, array $sent) use (&$options) {
            $options[] = $sent['allow_redirects'] ?? null;

            return Http::response('', 302, ['Location' => 'https://elsewhere.example/steal']);
        });

        // Notify: a 3xx is neither a refusal nor a success.
        $this->postJson($this->url($server), [
            'node_id' => $target->id,
            'allocation_id' => $allocation->id,
            'relay_url' => $this->relayUrl($target->id),
        ])->assertStatus(202)->assertJsonPath('meta.uncertain', true);

        // Probe: a 3xx from the target proves nothing.
        ServerTransfer::query()->where('server_id', $server->id)->update(['created_at' => now()->subMinutes(30)]);
        $this->postJson($this->cancelUrl($server), ['confirm_agents_idle' => true])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'cannot_verify')
            ->assertJsonPath('errors.0.meta.node', 'target');

        Http::assertSentCount(2);
        $this->assertSame([false, false], $options);
    }

    public function testIntegrityDoesNotRevealOtherServers(): void
    {
        [$server, $target, $allocation] = $this->fixture();
        $oldAllocation = $server->allocation_id;
        $this->makeTransfer($server, $target, $allocation, ['successful' => true]);
        $allocation->update(['server_id' => $server->id]);
        $server->update(['node_id' => $target->id, 'allocation_id' => $allocation->id]);

        // The released old allocation now belongs to someone else.
        $other = $this->createServerModel();
        Allocation::query()->whereKey($oldAllocation)->update(['server_id' => $other->id]);

        $response = $this->getJson($this->url($server) . '?verify=1')
            ->assertOk()
            ->assertJsonPath('meta.integrity.ok', true);

        $check = collect($response->json('meta.integrity.allocations'))->firstWhere('allocation_id', $oldAllocation);
        $this->assertSame(['allocation_id' => $oldAllocation, 'expected' => 'released', 'owned_by_this_server' => false, 'ok' => true], $check);
        foreach ($response->json('meta.integrity.allocations') as $row) {
            $this->assertArrayNotHasKey('server_id', $row);
        }
        $this->assertStringNotContainsString('"server_id":' . $other->id, $response->getContent());
    }

    public function testServersAclIsEnforced(): void
    {
        [$server, $target, $allocation] = $this->fixture();
        $this->makeTransfer($server, $target, $allocation);

        $this->createNewDefaultApiKey($this->getApiUser(), ['r_servers' => AdminAcl::READ]);
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url($server))->assertOk();
        $this->postJson($this->url($server), [
            'node_id' => $target->id,
            'allocation_id' => $allocation->id,
            'relay_url' => $this->relayUrl($target->id),
        ])->assertForbidden();
        $this->postJson($this->cancelUrl($server), ['confirm_agents_idle' => true])->assertForbidden();

        $this->createNewDefaultApiKey($this->getApiUser(), ['r_servers' => AdminAcl::NONE]);
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url($server))->assertForbidden();

        Http::assertNothingSent();
    }

    public function testUnknownServerReturns404(): void
    {
        $this->getJson('/api/application/servers/999999/transfer')->assertNotFound();
    }

    /**
     * A transfer row as the panel would leave it, with the new allocation reserved while
     * the transfer is pending.
     */
    private function makeTransfer(Server $server, Node $target, Allocation $allocation, array $attributes = []): ServerTransfer
    {
        if (!array_key_exists('successful', $attributes)) {
            $allocation->update(['server_id' => $server->id]);
        }

        // Old enough by default for transfer/cancel (past the JWT lifetime + skew).
        return ServerTransfer::factory()->create(array_merge([
            'created_at' => now()->subMinutes(30),
            'server_id' => $server->id,
            'old_node' => $server->node_id,
            'new_node' => $target->id,
            'old_allocation' => $server->allocation_id,
            'new_allocation' => $allocation->id,
        ], $attributes));
    }
}
