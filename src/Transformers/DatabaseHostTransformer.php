<?php

namespace Byzic\PterodactylClientApi\Transformers;

use Pterodactyl\Models\DatabaseHost;
use Pterodactyl\Transformers\Api\Application\BaseTransformer;

/**
 * The panel's own DatabaseHostTransformer plus what the admin page shows next to each host:
 * the database limit and how many databases live on it. The password is never part of it.
 */
class DatabaseHostTransformer extends BaseTransformer
{
    public function getResourceName(): string
    {
        return DatabaseHost::RESOURCE_NAME;
    }

    public function transform(DatabaseHost $host): array
    {
        return [
            'id' => $host->id,
            'name' => $host->name,
            'host' => $host->host,
            'port' => (int) $host->port,
            'username' => $host->username,
            'node' => $host->node_id,
            'max_databases' => $host->max_databases,
            'databases_count' => (int) ($host->databases_count ?? $host->databases()->count()),
            'created_at' => $host->created_at?->toAtomString(),
            'updated_at' => $host->updated_at?->toAtomString(),
        ];
    }
}
