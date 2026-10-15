<?php

namespace Byzic\PterodactylClientApi\Http\Controllers;

use Pterodactyl\Models\Egg;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Services\Eggs\Variables\VariableUpdateService;
use Pterodactyl\Services\Eggs\Variables\VariableCreationService;
use Pterodactyl\Exceptions\Service\Egg\Variable\BadValidationRuleException;
use Pterodactyl\Transformers\Api\Application\EggVariableTransformer;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;
use Byzic\PterodactylClientApi\Support\FieldLimits;
use Byzic\PterodactylClientApi\Support\ExactVariable;
use Byzic\PterodactylClientApi\Support\EggFingerprint;
use Byzic\PterodactylClientApi\Http\Requests\UpsertEggVariableRequest;

/**
 * Creates or updates one variable of an egg, keyed by its environment variable name.
 * Same services as the admin egg "Variables" tab, so reserved names and rule syntax are
 * validated exactly like the panel does. Existing servers pick the variable up through its
 * default value; a server-level value only exists once someone sets it.
 *
 * The egg row is locked for the write so it serializes with the egg import endpoints. An optional
 * `expected_fingerprint` turns a concurrent change into 409 EggChanged.
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

        FieldLimits::checkVariable($data);

        try {
            $expected = $request->input('expected_fingerprint');
            [$variable, $created, $fingerprint] = DB::transaction(fn () => EggFingerprint::locked($egg->id, $expected, function () use ($egg, $env, $data) {
                $existing = ExactVariable::find($egg->id, $env);

                if ($existing) {
                    $this->updateService->handle($existing, $data);

                    return [$existing->refresh(), false, EggFingerprint::of($egg->id)];
                }

                $variable = $this->creationService->handle($egg->id, $data);

                return [$variable, true, EggFingerprint::of($egg->id)];
            }));
        } catch (\BadMethodCallException) {
            // ValidatesValidationRules runs the rules against a dummy value; an unknown rule name
            // surfaces as Validator::__call(), which the panel would render as a 500.
            throw new BadValidationRuleException('The "rules" field contains an unknown validation rule.');
        }

        $response = $this->fractal->item($variable)
            ->transformWith($this->getTransformer(EggVariableTransformer::class))
            ->toArray();
        $response['meta']['fingerprint'] = $fingerprint;

        return new JsonResponse(
            $response,
            $created ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK
        );
    }
}
