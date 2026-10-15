<?php

namespace Byzic\PterodactylClientApi\Http\Controllers;

use Illuminate\Support\Arr;
use Pterodactyl\Models\Egg;
use Illuminate\Http\Response;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Services\Eggs\EggDeletionService;
use Byzic\PterodactylClientApi\Support\AuditLog;
use Byzic\PterodactylClientApi\Support\FieldLimits;
use Byzic\PterodactylClientApi\Support\EggFingerprint;
use Byzic\PterodactylClientApi\Exceptions\EggApiException;
use Pterodactyl\Transformers\Api\Application\EggTransformer;
use Byzic\PterodactylClientApi\Http\Requests\UpdateEggRequest;
use Byzic\PterodactylClientApi\Http\Requests\FingerprintedEggRequest;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;

class EggController extends ApplicationApiController
{
    public function __construct(private EggDeletionService $deletionService)
    {
        parent::__construct();
    }

    /**
     * Name, description and the two flags only. `file_denylist` and everything that changes what
     * runs on a node go through update-import.
     *
     * @throws \Throwable
     */
    public function update(UpdateEggRequest $request, Egg $egg): array
    {
        $data = Arr::only($request->validated(), UpdateEggRequest::FIELDS);
        FieldLimits::check('description', $data['description'] ?? null);

        [$updated, $fingerprint] = EggFingerprint::transaction($egg->id, $request->input('expected_fingerprint'), function (Egg $locked) use ($data) {
            $locked->forceFill($data)->save();

            return [$locked->refresh(), EggFingerprint::of($locked->id)];
        });

        AuditLog::write(fn () => Activity::event('admin:egg.update')
            ->subject($updated)
            ->property(['egg_id' => $updated->id, 'name' => $updated->name, 'fields' => array_keys($data)])
            ->log());

        $response = $this->fractal->item($updated)
            ->transformWith($this->getTransformer(EggTransformer::class))
            ->toArray();
        $response['meta']['fingerprint'] = $fingerprint;

        return $response;
    }

    /**
     * Refused with 409 while servers use the egg, or while another egg copies its configuration
     * or install script (the panel itself only checks configuration; a script child would
     * silently lose its install script).
     *
     * @throws \Throwable
     */
    public function delete(FingerprintedEggRequest $request, Egg $egg): Response
    {
        $deleted = EggFingerprint::transaction($egg->id, $request->input('expected_fingerprint'), function (Egg $locked) {
            $children = Egg::query()
                ->where('id', '!=', $locked->id)
                ->where(fn ($query) => $query->where('config_from', $locked->id)->orWhere('copy_script_from', $locked->id))
                ->orderBy('id')
                ->pluck('id')
                ->all();
            if ($children) {
                throw EggApiException::hasChildren($children);
            }

            $this->deletionService->handle($locked->id);

            return $locked;
        });

        AuditLog::write(fn () => Activity::event('admin:egg.delete')
            ->property([
                'egg_id' => $deleted->id,
                'uuid' => $deleted->uuid,
                'name' => $deleted->name,
                'nest_id' => $deleted->nest_id,
            ])
            ->log());

        return $this->returnNoContent();
    }
}
