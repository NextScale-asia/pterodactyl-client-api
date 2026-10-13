<?php

namespace Byzic\PterodactylClientApi\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\DatabaseHost;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\Databases\Hosts\HostUpdateService;
use Pterodactyl\Services\Databases\Hosts\HostCreationService;
use Pterodactyl\Services\Databases\Hosts\HostDeletionService;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;
use Byzic\PterodactylClientApi\Transformers\DatabaseHostTransformer;
use Byzic\PterodactylClientApi\Http\Requests\GetDatabaseHostsRequest;
use Byzic\PterodactylClientApi\Http\Requests\StoreDatabaseHostRequest;
use Byzic\PterodactylClientApi\Http\Requests\DeleteDatabaseHostRequest;
use Byzic\PterodactylClientApi\Http\Requests\UpdateDatabaseHostRequest;

/**
 * The admin "Databases" page (database hosts) as Application API endpoints. Create, update and
 * delete go through the panel's own Host*Service classes, so the credentials are encrypted and
 * checked with a live connection exactly like the admin form does. The password is write-only:
 * no endpoint here ever returns it.
 */
class DatabaseHostController extends ApplicationApiController
{
    public function __construct(
        private HostCreationService $creationService,
        private HostUpdateService $updateService,
        private HostDeletionService $deletionService,
    ) {
        parent::__construct();
    }

    public function index(GetDatabaseHostsRequest $request): array
    {
        $hosts = DatabaseHost::query()
            ->withCount('databases')
            ->orderBy('id')
            ->paginate((int) $request->query('per_page', 50));

        return $this->fractal->collection($hosts)
            ->transformWith($this->getTransformer(DatabaseHostTransformer::class))
            ->toArray();
    }

    public function view(GetDatabaseHostsRequest $request, DatabaseHost $databaseHost): array
    {
        return $this->item($databaseHost);
    }

    /**
     * @throws \Throwable
     */
    public function store(StoreDatabaseHostRequest $request): JsonResponse
    {
        $host = $this->connecting(fn () => $this->creationService->handle($request->validated()));

        return new JsonResponse($this->item($host), JsonResponse::HTTP_CREATED);
    }

    /**
     * Only the fields present in the body change. An empty or missing password keeps the stored one.
     *
     * @throws \Throwable
     */
    public function update(UpdateDatabaseHostRequest $request, DatabaseHost $databaseHost): array
    {
        $host = $this->connecting(fn () => $this->updateService->handle($databaseHost->id, $request->validated()));

        return $this->item($host);
    }

    /**
     * Refused with 400 while the host still has databases on it.
     *
     * @throws \Pterodactyl\Exceptions\Service\HasActiveServersException
     */
    public function delete(DeleteDatabaseHostRequest $request, DatabaseHost $databaseHost): Response
    {
        $this->deletionService->handle($databaseHost->id);

        return $this->returnNoContent();
    }

    private function item(DatabaseHost $host): array
    {
        return $this->fractal->item($host->loadCount('databases'))
            ->transformWith($this->getTransformer(DatabaseHostTransformer::class))
            ->toArray();
    }

    /**
     * The Host*Service classes save the row and then run a query on the host inside one
     * transaction. A host that cannot be reached, or that refuses the credentials, surfaces
     * as a PDOException the panel would render as a 500; report it as a 400 with the driver's
     * message instead, like the admin page does. Nothing is saved in that case.
     *
     * @template T
     *
     * @param \Closure(): T $callback
     *
     * @return T
     *
     * @throws \Throwable
     */
    private function connecting(\Closure $callback)
    {
        // The services reuse the `dynamic` connection name. Drop any connection this process already
        // opened under it, otherwise the check below would run on the previous host's credentials.
        DB::purge('dynamic');

        try {
            return $callback();
        } catch (\Exception $exception) {
            if ($exception instanceof \PDOException || $exception->getPrevious() instanceof \PDOException) {
                throw new DisplayException(sprintf('Could not connect to the database host or run a query on it: "%s"', $exception->getMessage()));
            }

            throw $exception;
        }
    }
}
