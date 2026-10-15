<?php

namespace Byzic\PterodactylClientApi\Support;

use Pterodactyl\Models\EggVariable;
use Byzic\PterodactylClientApi\Exceptions\EggApiException;

/**
 * The panel's tables use a case-insensitive collation, so `where('env_variable', 'tz')` also finds
 * `TZ`. Fetch the case-insensitive candidates (locked) and keep the exact match in PHP, which
 * works the same on MySQL and MariaDB whatever the collation.
 */
class ExactVariable
{
    /**
     * The variable named exactly $env, or null when the egg has none under any case.
     *
     * @throws EggApiException when only a variable differing in case exists
     */
    public static function find(int $eggId, string $env): ?EggVariable
    {
        $candidates = EggVariable::query()
            ->where('egg_id', $eggId)
            ->where('env_variable', $env)
            ->lockForUpdate()
            ->get();

        $exact = $candidates->first(fn (EggVariable $variable) => $variable->env_variable === $env);
        if (!$exact && $candidates->isNotEmpty()) {
            throw EggApiException::caseMismatch($env, $candidates->first()->env_variable);
        }

        return $exact;
    }
}
