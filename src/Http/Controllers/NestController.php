<?php

namespace Byzic\PterodactylClientApi\Http\Controllers;

use Illuminate\Support\Arr;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Facades\Activity;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Services\Nests\NestUpdateService;
use Pterodactyl\Services\Nests\NestCreationService;
use Pterodactyl\Services\Nests\NestDeletionService;
use Byzic\PterodactylClientApi\Support\AuditLog;
use Byzic\PterodactylClientApi\Exceptions\EggApiException;
use Pterodactyl\Transformers\Api\Application\NestTransformer;
use Byzic\PterodactylClientApi\Http\Requests\NestWriteRequest;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;

/**
 * Create, rename and delete nests. `eggs.nest_id` cascades on delete, so a nest that still has
 * eggs is refused here (409 NestHasEggs) instead of taking its eggs with it.
 */
class NestController extends ApplicationApiController
{
    public function __construct(
        private NestCreationService $creationService,
        private NestUpdateService $updateService,
        private NestDeletionService $deletionService,
    ) {
        parent::__construct();
    }

    /**
     * @throws \Throwable
     */
    public function store(NestWriteRequest $request): JsonResponse
    {
        $nest = EggApiException::translate(fn () => $this->creationService->handle(Arr::only($request->validated(), ['name', 'description'])));

        AuditLog::write(fn () => Activity::event('admin:nest.create')
            ->property(['nest_id' => $nest->id, 'name' => $nest->name])
            ->log());

        return new JsonResponse($this->item($nest), JsonResponse::HTTP_CREATED);
    }

    /**
     * @throws \Throwable
     */
    public function update(NestWriteRequest $request, Nest $nest): array
    {
        $data = Arr::only($request->validated(), ['name', 'description']);
        EggApiException::translate(fn () => $this->updateService->handle($nest->id, $data));

        AuditLog::write(fn () => Activity::event('admin:nest.update')
            ->property(['nest_id' => $nest->id, 'fields' => array_keys($data)])
            ->log());

        return $this->item($nest->refresh());
    }

    /**
     * @throws \Throwable
     */
    public function delete(NestWriteRequest $request, Nest $nest): Response
    {
        EggApiException::translate(fn () => DB::transaction(function () use ($nest) {
            Nest::query()->lockForUpdate()->findOrFail($nest->id);

            $eggs = Egg::query()->where('nest_id', $nest->id)->lockForUpdate()->count();
            if ($eggs > 0) {
                throw EggApiException::nestHasEggs($eggs);
            }

            $this->deletionService->handle($nest->id);
        }));

        AuditLog::write(fn () => Activity::event('admin:nest.delete')
            ->property(['nest_id' => $nest->id, 'uuid' => $nest->uuid, 'name' => $nest->name])
            ->log());

        return $this->returnNoContent();
    }

    private function item(Nest $nest): array
    {
        return $this->fractal->item($nest)
            ->transformWith($this->getTransformer(NestTransformer::class))
            ->toArray();
    }
}
