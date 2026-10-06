<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\Allocation;
use Illuminate\Validation\Validator;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use Pterodactyl\Http\Requests\Api\Application\ApplicationApiRequest;

class StoreServerTransferRequest extends ApplicationApiRequest
{
    protected ?string $resource = AdminAcl::RESOURCE_SERVERS;

    protected int $permission = AdminAcl::WRITE;

    public function rules(): array
    {
        $requireRelay = (bool) config('pterodactyl-client-api.transfer.require_relay', true);

        return [
            'node_id' => 'required|integer|exists:nodes,id',
            'allocation_id' => 'required|integer',
            'allocation_additional' => 'sometimes|nullable|array|max:100',
            'allocation_additional.*' => 'integer|distinct',
            'relay_url' => [$requireRelay ? 'required' : 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The exact relay URL accepted for a given target node: loopback, the configured relay
     * port, the target node id and a 32-character lowercase hex ticket.
     */
    public static function relayUrlPattern(int $nodeId): string
    {
        $port = (int) config('pterodactyl-client-api.transfer.relay_port', 781);

        return sprintf('#\Ahttp://127\.0\.0\.1:%d/relay/%d/[0-9a-f]{32}/api/transfers\z#', $port, $nodeId);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Server $server */
            $server = $this->parameter('server', Server::class);
            $nodeId = (int) $this->input('node_id');

            if ($nodeId === (int) $server->node_id) {
                $validator->errors()->add('node_id', 'The target node must differ from the node the server is on.');

                return;
            }

            $relayUrl = $this->input('relay_url');
            if (is_string($relayUrl) && $relayUrl !== '' && !preg_match(self::relayUrlPattern($nodeId), $relayUrl)) {
                $validator->errors()->add('relay_url', 'The relay_url must be http://127.0.0.1:{relay_port}/relay/{node_id}/{ticket}/api/transfers for the target node.');
            }

            $primary = (int) $this->input('allocation_id');
            $additional = array_map('intval', $this->input('allocation_additional') ?? []);

            if (in_array($primary, $additional, true)) {
                $validator->errors()->add('allocation_additional', 'The primary allocation cannot also be an additional allocation.');

                return;
            }

            $free = Allocation::query()
                ->whereIn('id', [$primary, ...$additional])
                ->where('node_id', $nodeId)
                ->whereNull('server_id')
                ->pluck('id')
                ->all();

            if (!in_array($primary, $free)) {
                $validator->errors()->add('allocation_id', 'The allocation must be an unassigned allocation of the target node.');
            }

            foreach ($additional as $index => $id) {
                if (!in_array($id, $free)) {
                    $validator->errors()->add("allocation_additional.{$index}", 'Each additional allocation must be an unassigned allocation of the target node.');
                }
            }
        });
    }
}
