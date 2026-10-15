<?php

use Illuminate\Support\Facades\Route;
use Byzic\PterodactylClientApi\Http\Controllers\EggController;
use Byzic\PterodactylClientApi\Http\Controllers\NestController;
use Byzic\PterodactylClientApi\Http\Controllers\ApiKeyController;
use Byzic\PterodactylClientApi\Http\Controllers\EggShareController;
use Byzic\PterodactylClientApi\Http\Controllers\AddonInfoController;
use Byzic\PterodactylClientApi\Http\Controllers\EggVariableController;
use Byzic\PterodactylClientApi\Http\Controllers\DatabaseHostController;
use Byzic\PterodactylClientApi\Http\Controllers\FreeAllocationController;
use Byzic\PterodactylClientApi\Http\Controllers\ServerTransferController;
use Byzic\PterodactylClientApi\Http\Controllers\EggVariableDeleteController;
use Byzic\PterodactylClientApi\Http\Controllers\ServerVariableRestoreController;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;
use Byzic\PterodactylClientApi\Exceptions\EggApiException;

/*
| Same middleware stack as the panel's own /api/application routes
| (see RouteServiceProvider): Sanctum auth, IP allow-list, 2FA, then
| `application-api` for route-model binding and the root_admin check.
*/
Route::middleware(['api', RequireTwoFactorAuthentication::class, 'application-api', 'throttle:api.application'])
    ->prefix('/api/application')
    ->group(function () {
        Route::group(['prefix' => '/users/{user:id}/api-keys'], function () {
            Route::get('/', [ApiKeyController::class, 'index'])->name('api.application.users.api-keys');
            Route::post('/', [ApiKeyController::class, 'store']);
            Route::delete('/{identifier}', [ApiKeyController::class, 'delete'])
                ->where('identifier', '[A-Za-z0-9_]{16}');
        });

        Route::get('/nodes/{node:id}/allocations/free', FreeAllocationController::class)
            ->name('api.application.allocations.free');

        Route::get('/addon/info', AddonInfoController::class)->name('api.application.addon.info');

        /*
        | The panel only registers GET routes for nests and eggs, all under /nests. Egg writes live
        | under /eggs/{egg} (a prefix the panel does not use) and nest writes use other methods than
        | the panel's GETs. Writes also need egg_write.enabled (see EggWriteRequest).
        */
        $eggMissing = static fn () => throw EggApiException::notFound('EggNotFound', 'The requested egg does not exist.');
        $nestMissing = static fn () => throw EggApiException::notFound('NestNotFound', 'The requested nest does not exist.');

        Route::group(['prefix' => '/eggs/{egg:id}'], function () use ($eggMissing) {
            Route::get('/export', [EggShareController::class, 'export'])->name('api.application.eggs.export')
                ->missing($eggMissing);
            Route::post('/update-import/preview', [EggShareController::class, 'previewUpdate'])
                ->name('api.application.eggs.update-import.preview')
                ->missing($eggMissing);
            Route::post('/update-import', [EggShareController::class, 'applyUpdate'])->name('api.application.eggs.update-import')
                ->missing($eggMissing);
            Route::patch('/', [EggController::class, 'update'])->name('api.application.eggs.update')
                ->missing($eggMissing);
            Route::delete('/', [EggController::class, 'delete'])->name('api.application.eggs.delete')
                ->missing($eggMissing);

            Route::put('/variables/{env}', EggVariableController::class)
                ->where('env', '[A-Za-z_][A-Za-z0-9_]{0,190}')
                ->name('api.application.eggs.variables.upsert')
                ->missing($eggMissing);
            Route::delete('/variables/{env}', EggVariableDeleteController::class)
                ->where('env', '[A-Za-z_][A-Za-z0-9_]{0,190}')
                ->name('api.application.eggs.variables.delete')
                ->missing($eggMissing);

            Route::post('/server-variables/restore', ServerVariableRestoreController::class)
                ->name('api.application.eggs.server-variables.restore')
                ->missing($eggMissing);
        });

        Route::post('/nests', [NestController::class, 'store'])->name('api.application.nests.store');
        Route::patch('/nests/{nest:id}', [NestController::class, 'update'])->name('api.application.nests.update')
            ->missing($nestMissing);
        Route::delete('/nests/{nest:id}', [NestController::class, 'delete'])->name('api.application.nests.delete')
            ->missing($nestMissing);
        Route::post('/nests/{nest:id}/eggs/import/preview', [EggShareController::class, 'previewImport'])
            ->name('api.application.nests.eggs.import.preview')
            ->missing($nestMissing);
        Route::post('/nests/{nest:id}/eggs/import', [EggShareController::class, 'import'])
            ->name('api.application.nests.eggs.import')
            ->missing($nestMissing);

        Route::group(['prefix' => '/database-hosts'], function () {
            Route::get('/', [DatabaseHostController::class, 'index'])->name('api.application.database-hosts');
            Route::get('/{databaseHost:id}', [DatabaseHostController::class, 'view'])->name('api.application.database-hosts.view');
            Route::post('/', [DatabaseHostController::class, 'store']);
            Route::patch('/{databaseHost:id}', [DatabaseHostController::class, 'update']);
            Route::delete('/{databaseHost:id}', [DatabaseHostController::class, 'delete']);
        });

        /*
        | There is deliberately NO `DELETE /servers/{server}/transfer`: the panel registers
        | `DELETE /api/application/servers/{server:id}/{force?}`, so that path is the panel's
        | SERVER DELETE (with force = "transfer"). Cancelling is a POST to a sub-path instead.
        */
        Route::group(['prefix' => '/servers/{server:id}/transfer'], function () {
            Route::get('/', [ServerTransferController::class, 'show'])->name('api.application.servers.transfer');
            Route::post('/', [ServerTransferController::class, 'store']);
            Route::post('/cancel', [ServerTransferController::class, 'cancel'])->name('api.application.servers.transfer.cancel');
        });
    });
