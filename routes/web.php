<?php

use Illuminate\Support\Facades\Route;
use Xepare\PterodactylApiAddon\Http\Controllers\ApiKeyController;
use Xepare\PterodactylApiAddon\Http\Controllers\FreeAllocationController;
use Xepare\PterodactylApiAddon\Http\Middleware\ValidateUserOwnership;

Route::prefix('/api/application')->middleware(['api', 'throttle:api.application'])->group(function () {

    Route::group(['prefix' => '/users'], function () {
        /** User API Keys Management */
        Route::get('{user}/api-keys', [ApiKeyController::class, 'index'])
            ->middleware(ValidateUserOwnership::class);
        Route::post('{user}/api-keys', [ApiKeyController::class, 'store'])
            ->middleware(ValidateUserOwnership::class);
        Route::delete('{user}/api-keys/{identifier}', [ApiKeyController::class, 'delete'])
            ->middleware(ValidateUserOwnership::class);
    });

    Route::group(['prefix' => '/nodes/{node}/allocations'], function () {
        Route::get('/free', FreeAllocationController::class)->name('api.application.allocations.free');
    });

});
