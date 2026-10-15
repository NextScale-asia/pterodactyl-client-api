<?php

namespace Byzic\PterodactylClientApi\Http\Controllers;

use Pterodactyl\Models\Egg;
use Pterodactyl\Facades\Activity;
use Byzic\PterodactylClientApi\Support\AuditLog;
use Byzic\PterodactylClientApi\Support\DroppedValues;
use Byzic\PterodactylClientApi\Support\ExactVariable;
use Byzic\PterodactylClientApi\Support\EggFingerprint;
use Byzic\PterodactylClientApi\Exceptions\EggApiException;
use Byzic\PterodactylClientApi\Http\Requests\FingerprintedEggRequest;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;

/**
 * Deletes one egg variable. The foreign key deletes every server's value for it too, so those
 * values are returned (`dropped_values`) for the caller to keep and restore later.
 */
class EggVariableDeleteController extends ApplicationApiController
{
    /**
     * @throws \Throwable
     */
    public function __invoke(FingerprintedEggRequest $request, Egg $egg, string $env): array
    {
        $result = EggFingerprint::transaction($egg->id, $request->input('expected_fingerprint'), function (Egg $locked) use ($env) {
            $variable = ExactVariable::find($locked->id, $env);
            if (!$variable) {
                throw EggApiException::notFound('EggVariableNotFound', 'The egg has no variable with that env_variable.');
            }

            $dropped = DroppedValues::of($locked->id, [$env]);
            $variable->delete();

            return ['egg' => $locked, 'dropped' => $dropped, 'fingerprint' => EggFingerprint::of($locked->id)];
        });

        AuditLog::write(fn () => Activity::event('admin:egg.variable.delete')
            ->subject($result['egg'])
            ->property(['egg_id' => $egg->id, 'env' => $env, 'dropped_values' => count($result['dropped'])])
            ->log());

        return ['fingerprint' => $result['fingerprint']] + DroppedValues::present($result['dropped'], $request);
    }
}
