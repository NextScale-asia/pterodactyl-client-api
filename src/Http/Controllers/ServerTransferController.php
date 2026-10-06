<?php

namespace Byzic\PterodactylClientApi\Http\Controllers;

use Carbon\CarbonImmutable;
use Pterodactyl\Enum\JwtScope;
use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ServerTransfer;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Services\Nodes\NodeJWTService;
use Pterodactyl\Repositories\Eloquent\NodeRepository;
use Byzic\PterodactylClientApi\Services\TransferNotifier;
use Byzic\PterodactylClientApi\Services\WingsServerProbe;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;
use Byzic\PterodactylClientApi\Transformers\ServerTransferTransformer;
use Byzic\PterodactylClientApi\Http\Requests\GetServerTransferRequest;
use Byzic\PterodactylClientApi\Http\Requests\StoreServerTransferRequest;
use Byzic\PterodactylClientApi\Exceptions\WingsRejectedTransferException;
use Byzic\PterodactylClientApi\Http\Requests\CancelServerTransferRequest;

/**
 * Application API for the panel's server transfer, mirroring the admin
 * Admin\Servers\ServerTransferController, with one difference: the URL the source Wings
 * streams to is the local agent relay (relay_url) instead of the target node's address.
 */
class ServerTransferController extends ApplicationApiController
{
    public function __construct(
        private ConnectionInterface $connection,
        private NodeJWTService $nodeJWTService,
        private NodeRepository $nodeRepository,
        private TransferNotifier $notifier,
        private WingsServerProbe $probe,
    ) {
        parent::__construct();
    }

    /**
     * The latest transfer of the server, finished or not. ?verify=1 adds an integrity
     * check of the server's node and allocations against that transfer's outcome.
     */
    public function show(GetServerTransferRequest $request, Server $server): JsonResponse
    {
        $transfer = ServerTransfer::query()
            ->where('server_id', $server->id)
            ->orderByDesc('id')
            ->first();

        if (is_null($transfer)) {
            return $this->error(404, 'no_transfer', 'This server has never been transferred.');
        }

        $response = $this->transform($transfer);

        if ($request->boolean('verify')) {
            $response['meta']['integrity'] = $this->integrity($server, $transfer);
        }

        return new JsonResponse($response);
    }

    /**
     * Start a transfer of the server to another node.
     *
     * @throws \Throwable
     */
    public function store(StoreServerTransferRequest $request, Server $server): JsonResponse
    {
        $nodeId = (int) $request->input('node_id');
        $allocationId = (int) $request->input('allocation_id');
        $additional = array_values(array_map('intval', $request->input('allocation_additional') ?? []));

        $node = $this->nodeRepository->getNodeWithResourceUsage($nodeId);
        if (!$node->isViable($server->memory, $server->disk)) {
            return $this->error(422, 'node_not_viable', 'The target node does not have enough memory or disk for this server.');
        }

        // Cheap early answer; repeated on the locked row below, which is the check that counts.
        $server->validateTransferState();

        $outcome = TransferNotifier::STARTED;

        try {
            /*
             * Design trade-off (accepted): the server row and the target allocations stay
             * locked for the whole notify call below (up to transfer.notifier_timeout, 60 s),
             * because whether to commit or roll back depends on Wings' answer. Other writes to
             * this server (and the panel's callbacks for it) wait meanwhile; they are rare and
             * the alternative (commit first, compensate on refusal) leaves a window in which
             * Wings could report on a transfer the panel would then delete.
             *
             * @var ServerTransfer $transfer
             */
            $transfer = $this->connection->transaction(function () use ($request, $server, $nodeId, $allocationId, $additional, &$outcome) {
                // Serialize concurrent starts for this server and re-check its state on the
                // locked, fresh row (installed, not restoring a backup, no pending transfer).
                /** @var Server $server */
                $server = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
                $server->validateTransferState();

                $ids = [$allocationId, ...$additional];
                $free = Allocation::query()
                    ->whereIn('id', $ids)
                    ->where('node_id', $nodeId)
                    ->whereNull('server_id')
                    ->lockForUpdate()
                    ->count();

                if ($free !== count($ids)) {
                    throw ValidationException::withMessages([
                        'allocation_id' => 'One or more allocations are no longer free on the target node.',
                    ]);
                }

                $transfer = new ServerTransfer();
                $transfer->server_id = $server->id;
                $transfer->old_node = $server->node_id;
                $transfer->new_node = $nodeId;
                $transfer->old_allocation = $server->allocation_id;
                $transfer->new_allocation = $allocationId;
                $transfer->old_additional_allocations = $server->allocations->where('id', '!=', $server->allocation_id)->pluck('id')->values()->toArray();
                $transfer->new_additional_allocations = $additional;
                $transfer->save();

                // Reserve the allocations so they cannot be assigned elsewhere during the transfer.
                Allocation::query()->whereIn('id', $ids)->update(['server_id' => $server->id]);

                // Token for the target node that the source node uses to authenticate with it.
                $token = $this->nodeJWTService
                    ->setExpiresAt(CarbonImmutable::now()->addMinutes(15))
                    ->setSubject($server->uuid)
                    ->setScopes(JwtScope::ServerTransfer)
                    ->handle($transfer->newNode, $server->uuid);

                $url = $request->input('relay_url') ?: $transfer->newNode->getConnectionAddress() . '/api/transfers';

                // Throws on an explicit refusal, rolling the transaction back. A timeout or
                // network error commits: Wings may already be streaming.
                $outcome = $this->notifier->notify($server, $server->node, $url, $token);

                return $transfer;
            });
        } catch (WingsRejectedTransferException $exception) {
            return $this->error(502, 'wings_rejected', $exception->getMessage(), ['wings_status' => $exception->wingsStatus]);
        }

        $uncertain = $outcome === TransferNotifier::UNCERTAIN;

        // Server activity is shown to the server's owner and subusers by the client API, so
        // only the transfer id is logged: no node or allocation ids, no relay/outcome details
        // (admins get those from GET …/transfer).
        Activity::event('server:transfer.start')
            ->subject($server)
            ->property(['transfer_id' => $transfer->id])
            ->log();

        $response = $this->transform($transfer->fresh());
        $response['meta']['uncertain'] = $uncertain;

        return new JsonResponse($response, JsonResponse::HTTP_ACCEPTED);
    }

    /**
     * POST /servers/{server}/transfer/cancel: mark a dead pending transfer as failed and
     * release its reserved allocations, as the panel's processFailedTransfer() does. Files are
     * not touched anywhere.
     *
     * Refused (409) while the transfer JWT could still open a stream (too_early), while the
     * target Wings still knows the server (transfer_active), while either Wings cannot be
     * reached (cannot_verify), or when the panel's own callback got there first
     * (no_pending_transfer). `confirm_agents_idle` is the caller's attestation that neither
     * node's agent is still relaying the server; the panel cannot verify that.
     *
     * Never exposed as DELETE …/transfer: that path is the panel's server delete.
     *
     * @throws \Throwable
     */
    public function cancel(CancelServerTransferRequest $request, Server $server): JsonResponse
    {
        $pending = ServerTransfer::query()
            ->where('server_id', $server->id)
            ->whereNull('successful')
            ->orderByDesc('id')
            ->first();

        if (is_null($pending)) {
            return $this->error(409, 'no_pending_transfer', 'This server has no pending transfer.');
        }

        // The JWT handed to the source Wings is valid for 15 minutes. Until it has expired
        // (plus clock skew), a stream could still start after we release the allocations.
        $minAge = max(0, (int) config('pterodactyl-client-api.transfer.delete_min_age_seconds', 1020));
        $retryAfter = (int) ceil(CarbonImmutable::now()->diffInSeconds($pending->created_at->toImmutable()->addSeconds($minAge), false));
        if ($retryAfter > 0) {
            return $this->error(
                409,
                'too_early',
                'The transfer token may still be valid; this transfer cannot be cancelled yet.',
                ['retry_after' => $retryAfter],
            )->header('Retry-After', (string) $retryAfter);
        }

        $target = $this->probe->check($pending->newNode, $server);
        if ($target === WingsServerProbe::UNREACHABLE) {
            return $this->error(409, 'cannot_verify', 'The target node could not be reached to verify the transfer state.', ['node' => 'target']);
        }

        if ($target === WingsServerProbe::PRESENT) {
            return $this->error(409, 'transfer_active', 'The target node still has this server; the transfer may be running or may have completed.', ['node' => 'target']);
        }

        if ($this->probe->check($pending->oldNode, $server) === WingsServerProbe::UNREACHABLE) {
            return $this->error(409, 'cannot_verify', 'The source node could not be reached to verify the transfer state.', ['node' => 'source']);
        }

        $marked = $this->connection->transaction(function () use ($server, $pending) {
            // Lock the SERVER row first. The panel's success() callback reads the transfer
            // without a lock, then (in its transaction) frees the old allocations, updates the
            // server row and marks the transfer successful via $server->fresh()->transfer:
            //  - if success() commits first, we see the moved server / finished transfer
            //    below and refuse;
            //  - if we commit first, its server update waits for this lock, then its
            //    ->transfer is null (no longer pending) and its transaction fails and rolls
            //    back, so the server never ends up on the new node with released allocations.
            /** @var Server|null $locked */
            $locked = Server::query()->whereKey($server->id)->lockForUpdate()->first();

            /** @var ServerTransfer|null $transfer */
            $transfer = ServerTransfer::query()
                ->whereKey($pending->id)
                ->lockForUpdate()
                ->first();

            if (
                is_null($locked)
                || is_null($transfer)
                || !is_null($transfer->successful)
                || (int) $locked->node_id !== (int) $transfer->old_node
            ) {
                return false;
            }

            $transfer->forceFill(['successful' => false])->saveOrFail();

            $allocations = array_merge([$transfer->new_allocation], $transfer->new_additional_allocations ?? []);
            Allocation::query()
                ->whereIn('id', $allocations)
                ->where('server_id', $server->id)
                ->update(['server_id' => null]);

            return true;
        });

        if (!$marked) {
            return $this->error(409, 'no_pending_transfer', 'The transfer finished while it was being verified.');
        }

        Activity::event('server:transfer.fail')
            ->subject($server)
            ->property(['transfer_id' => $pending->id, 'reason' => 'marked-dead'])
            ->log();

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * Compare the server's node and allocations with what the transfer's outcome implies.
     */
    private function integrity(Server $server, ServerTransfer $transfer): array
    {
        $server->refresh();

        $old = array_merge([$transfer->old_allocation], $transfer->old_additional_allocations ?? []);
        $new = array_merge([$transfer->new_allocation], $transfer->new_additional_allocations ?? []);

        [$expectedNode, $expectedPrimary, $assigned, $released] = match ($transfer->successful) {
            true => [$transfer->new_node, $transfer->new_allocation, $new, $old],
            false => [$transfer->old_node, $transfer->old_allocation, $old, $new],
            // Pending: the server is still on the old node and holds the new allocations too.
            default => [$transfer->old_node, $transfer->old_allocation, array_merge($old, $new), []],
        };

        $rows = Allocation::query()
            ->whereIn('id', array_unique([...$assigned, ...$released, $server->allocation_id]))
            ->get(['id', 'node_id', 'server_id'])
            ->keyBy('id');

        // Only "does THIS server own it" is ever reported: a released allocation may since have
        // been given to another server, whose id must not leak through this endpoint.
        $ours = fn (int $id) => ($row = $rows->get($id)) && (int) $row->server_id === (int) $server->id;

        $checks = [];
        foreach ($assigned as $id) {
            $checks[] = ['allocation_id' => $id, 'expected' => 'assigned', 'owned_by_this_server' => $ours($id), 'ok' => $ours($id)];
        }
        foreach ($released as $id) {
            $checks[] = ['allocation_id' => $id, 'expected' => 'released', 'owned_by_this_server' => $ours($id), 'ok' => !$ours($id)];
        }

        $primary = $rows->get($server->allocation_id);
        $primaryOk = $server->allocation_id === $expectedPrimary
            && !is_null($primary)
            && (int) $primary->node_id === (int) $server->node_id
            && $ours($server->allocation_id);
        $nodeOk = (int) $server->node_id === (int) $expectedNode;

        return [
            'ok' => $nodeOk && $primaryOk && collect($checks)->every('ok'),
            'transfer_state' => match ($transfer->successful) {
                true => 'successful',
                false => 'failed',
                default => 'pending',
            },
            'server_node_id' => (int) $server->node_id,
            'expected_node_id' => (int) $expectedNode,
            'node_ok' => $nodeOk,
            'primary_allocation_id' => $server->allocation_id,
            'expected_primary_allocation_id' => $expectedPrimary,
            'primary_allocation_ok' => $primaryOk,
            'allocations' => $checks,
        ];
    }

    /**
     * Meta is added to the returned array rather than with Fractal::addMeta(): the Fractal
     * instance can be shared and addMeta() never overwrites an existing key.
     */
    private function transform(ServerTransfer $transfer): array
    {
        return $this->fractal->item($transfer)
            ->transformWith($this->getTransformer(ServerTransferTransformer::class))
            ->toArray();
    }

    private function error(int $status, string $code, string $detail, array $meta = []): JsonResponse
    {
        $error = ['code' => $code, 'status' => (string) $status, 'detail' => $detail];
        if (!empty($meta)) {
            $error['meta'] = $meta;
        }

        return new JsonResponse(['errors' => [$error]], $status);
    }
}
