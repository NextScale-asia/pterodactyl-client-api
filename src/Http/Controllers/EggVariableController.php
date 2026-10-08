<?php

namespace Byzic\PterodactylClientApi\Http\Controllers;

use Pterodactyl\Models\Egg;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\EggVariable;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Services\Eggs\Variables\VariableUpdateService;
use Pterodactyl\Services\Eggs\Variables\VariableCreationService;
use Pterodactyl\Exceptions\Service\Egg\Variable\BadValidationRuleException;
use Pterodactyl\Transformers\Api\Application\EggVariableTransformer;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;
use Byzic\PterodactylClientApi\Http\Requests\UpsertEggVariableRequest;

/**
 * Creates or updates one variable of an egg, keyed by its environment variable name.
 * Same services as the admin egg "Variables" tab, so reserved names and rule syntax are
 * validated exactly like the panel does. Existing servers pick the variable up through its
 * default value; a server-level value only exists once someone sets it.
 */
class EggVariableController extends ApplicationApiController
{
    public function __construct(
        private VariableCreationService $creationService,
        private VariableUpdateService $updateService,
    ) {
        parent::__construct();
    }

    /**
     * Returns 201 when the variable was created, 200 when an existing one was updated.
     *
     * @throws \Throwable
     */
    public function __invoke(UpsertEggVariableRequest $request, Egg $egg, string $env): JsonResponse
    {
        $data = [
            'name' => $request->input('name'),
            'description' => $request->input('description') ?? '',
            'env_variable' => $env,
            'default_value' => $request->input('default_value') ?? '',
            'rules' => $request->input('rules') ?? '',
            'options' => array_keys(array_filter([
                'user_viewable' => $request->boolean('user_viewable'),
                'user_editable' => $request->boolean('user_editable'),
            ])),
        ];

        try {
            [$variable, $created] = DB::transaction(function () use ($egg, $env, $data) {
                $existing = EggVariable::query()
                    ->where('egg_id', $egg->id)
                    ->where('env_variable', $env)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $this->updateService->handle($existing, $data);

                    return [$existing->refresh(), false];
                }

                return [$this->creationService->handle($egg->id, $data), true];
            });
        } catch (\BadMethodCallException) {
            // ValidatesValidationRules runs the rules against a dummy value; an unknown rule name
            // surfaces as Validator::__call(), which the panel would render as a 500.
            throw new BadValidationRuleException('The "rules" field contains an unknown validation rule.');
        }

        return new JsonResponse(
            $this->fractal->item($variable)
                ->transformWith($this->getTransformer(EggVariableTransformer::class))
                ->toArray(),
            $created ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK
        );
    }
}
