<?php

namespace Byzic\PterodactylClientApi\Transformers;

use Pterodactyl\Models\ServerTransfer;
use Pterodactyl\Transformers\Api\Application\BaseTransformer;

class ServerTransferTransformer extends BaseTransformer
{
    public function getResourceName(): string
    {
        return ServerTransfer::RESOURCE_NAME;
    }

    public function transform(ServerTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'server_id' => $transfer->server_id,
            'old_node' => $transfer->old_node,
            'new_node' => $transfer->new_node,
            'old_allocation' => $transfer->old_allocation,
            'new_allocation' => $transfer->new_allocation,
            'old_additional_allocations' => array_values($transfer->old_additional_allocations ?? []),
            'new_additional_allocations' => array_values($transfer->new_additional_allocations ?? []),
            'successful' => $transfer->successful,
            'created_at' => $transfer->created_at?->toAtomString(),
            'updated_at' => $transfer->updated_at?->toAtomString(),
        ];
    }
}
