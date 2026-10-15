<?php

namespace Byzic\PterodactylClientApi\Http\Controllers;

use Illuminate\Http\Request;
use Byzic\PterodactylClientApi\Support\EggWriteGate;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;
use Byzic\PterodactylClientApi\PterodactylApiAddonServiceProvider;

/**
 * Lets a client find out which addon version is installed and whether egg writes are switched
 * on, so it can tell "route missing" apart from "egg not found" before calling anything else.
 * Any key that passes the application API middleware may read it.
 */
class AddonInfoController extends ApplicationApiController
{
    public const CAPABILITIES = [
        'api-keys',
        'free-allocations',
        'server-transfer',
        'egg-variables',
        'database-hosts',
        'egg-fingerprint',
        'egg-export',
        'egg-update-import',
        'egg-import',
        'egg-update',
        'egg-delete',
        'egg-variable-delete',
        'server-variables-restore',
        'nest-write',
    ];

    public function __invoke(Request $request): array
    {
        return [
            'version' => PterodactylApiAddonServiceProvider::VERSION,
            'capabilities' => self::CAPABILITIES,
            'egg_write_enabled' => EggWriteGate::enabled(),
            'egg_write_key_allowed' => EggWriteGate::enabled() && EggWriteGate::allows($request),
        ];
    }
}
