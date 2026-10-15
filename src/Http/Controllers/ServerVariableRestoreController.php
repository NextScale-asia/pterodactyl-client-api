<?php

namespace Byzic\PterodactylClientApi\Http\Controllers;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\ServerVariable;
use Illuminate\Support\Facades\Validator;
use Byzic\PterodactylClientApi\Support\AuditLog;
use Byzic\PterodactylClientApi\Support\FieldLimits;
use Byzic\PterodactylClientApi\Support\EggFingerprint;
use Byzic\PterodactylClientApi\Http\Requests\RestoreServerVariablesRequest;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;

/**
 * Puts back server-level values (the `dropped_values` of an update-import or variable delete).
 * Only servers that use this egg and variables that exist on it are written; each value must pass
 * the variable's rules. Everything else is skipped and reported, nothing else is changed.
 */
class ServerVariableRestoreController extends ApplicationApiController
{
    /**
     * @throws \Throwable
     */
    public function __invoke(RestoreServerVariablesRequest $request, Egg $egg): array
    {
        $values = $request->validated()['values'];
        foreach ($values as $index => $item) {
            FieldLimits::check("values.{$index}.value", $item['value'] ?? null);
        }

        // An unknown rule name in a variable surfaces as BadMethodCallException: translated to 422.
        [$restored, $skipped] = EggFingerprint::transaction($egg->id, null, function (Egg $locked) use ($values) {
            $variables = EggVariable::query()->where('egg_id', $locked->id)->get()->keyBy('env_variable');
            $servers = Server::query()
                ->where('egg_id', $locked->id)
                ->whereIn('id', array_unique(array_column($values, 'server_id')))
                ->pluck('id')
                ->flip();

            $restored = [];
            $skipped = [];
            foreach ($values as $item) {
                $serverId = (int) $item['server_id'];
                $value = (string) ($item['value'] ?? '');
                /** @var EggVariable|null $variable */
                $variable = $variables->get($item['env']);

                $reason = match (true) {
                    !$servers->has($serverId) => 'server_not_on_egg',
                    is_null($variable) => 'variable_missing',
                    !empty($variable->rules) && Validator::make(['value' => $value], ['value' => $variable->rules])->fails() => 'invalid_value',
                    default => null,
                };

                if ($reason) {
                    $skipped[] = ['server_id' => $serverId, 'env' => $item['env'], 'reason' => $reason];
                    continue;
                }

                ServerVariable::query()->updateOrCreate(
                    ['server_id' => $serverId, 'variable_id' => $variable->id],
                    ['variable_value' => $value]
                );
                $restored[] = ['server_id' => $serverId, 'env' => $item['env']];
            }

            return [$restored, $skipped];
        });

        AuditLog::write(fn () => Activity::event('admin:egg.server-variables.restore')
            ->subject($egg)
            ->property([
                'egg_id' => $egg->id,
                'restored' => count($restored),
                'skipped' => count($skipped),
                'envs' => array_values(array_unique(array_column($restored, 'env'))),
            ])
            ->log());

        return ['restored' => $restored, 'skipped' => $skipped];
    }
}
