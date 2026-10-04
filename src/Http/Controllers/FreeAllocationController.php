<?php

namespace Byzic\PterodactylClientApi\Http\Controllers;

use Pterodactyl\Models\Node;
use Pterodactyl\Transformers\Api\Application\AllocationTransformer;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;
use Byzic\PterodactylClientApi\Http\Requests\GetFreeAllocationsRequest;

class FreeAllocationController extends ApplicationApiController
{
    /**
     * Return the allocations of a node that are not assigned to any server.
     */
    public function __invoke(GetFreeAllocationsRequest $request, Node $node): array
    {
        $allocations = $node->allocations()
            ->whereNull('server_id')
            ->paginate((int) $request->query('per_page', 50));

        return $this->fractal->collection($allocations)
            ->transformWith($this->getTransformer(AllocationTransformer::class))
            ->toArray();
    }
}
