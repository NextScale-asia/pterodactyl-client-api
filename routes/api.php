<?php

use Illuminate\Support\Facades\Route;
use Byzic\PterodactylClientApi\Http\Controllers\ApiKeyController;
use Byzic\PterodactylClientApi\Http\Controllers\FreeAllocationController;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;

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
    });
