<?php

namespace Byzic\PterodactylClientApi\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Pterodactyl\Models\ServerVariable;
use Pterodactyl\Services\Acl\Api\AdminAcl;

/**
 * Server-level values of egg variables that are about to be deleted (the foreign key cascades
 * them away). Returned to the caller so they can be stored and put back with
 * POST /eggs/{egg}/server-variables/restore. They may hold secrets: never log them.
 */
class DroppedValues
{
    /**
     * @return array<int, array{server_id: int, server_uuid: string, env: string, value: string}>
     */
    public static function of(int $eggId, array $envs): array
    {
        if (empty($envs)) {
            return [];
        }

        return ServerVariable::query()
            ->join('egg_variables', 'egg_variables.id', '=', 'server_variables.variable_id')
            ->join('servers', 'servers.id', '=', 'server_variables.server_id')
            ->where('egg_variables.egg_id', $eggId)
            ->whereIn('egg_variables.env_variable', $envs)
            ->orderBy('server_variables.server_id')
            ->orderBy('egg_variables.env_variable')
            ->lockForUpdate()
            ->get([
                'server_variables.server_id',
                'servers.uuid as server_uuid',
                'egg_variables.env_variable as env',
                'server_variables.variable_value as value',
            ])
            // the collation is case-insensitive: keep only the exact names asked for
            ->filter(fn ($row) => in_array($row->env, $envs, true))
            ->values()
            ->map(fn ($row) => [
                'server_id' => (int) $row->server_id,
                'server_uuid' => (string) $row->server_uuid,
                'env' => (string) $row->env,
                'value' => (string) $row->value,
            ])
            ->all();
    }

    /**
     * Response fields for $rows. The values themselves (RCON passwords and the like) are only
     * returned to a key that may also read servers; other keys get the rows without `value`.
     */
    public static function present(array $rows, Request $request): array
    {
        $key = EggWriteGate::applicationKey($request);
        $withheld = !$key || !AdminAcl::check($key, AdminAcl::RESOURCE_SERVERS, AdminAcl::READ);

        return [
            'dropped_values' => $withheld ? array_map(fn ($row) => Arr::except($row, 'value'), $rows) : $rows,
            'dropped_count' => count($rows),
            'values_withheld' => $withheld,
        ];
    }
}
