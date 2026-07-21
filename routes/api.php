<?php

use App\Http\Controllers\Api\V1\AreaController;
use App\Http\Controllers\Api\V1\CategoriaController;
use App\Http\Controllers\Api\V1\EntregaController;
use App\Http\Controllers\Api\V1\ProductoController;
use App\Http\Controllers\Api\V1\StagingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'time' => now()->toIso8601String(),
    ]));

    Route::get('/areas', [AreaController::class, 'index']);
    Route::get('/categorias', [CategoriaController::class, 'index']);
    Route::get('/productos', [ProductoController::class, 'index']);
    Route::get('/entregas', [EntregaController::class, 'index']);
    Route::post('/entregas', [EntregaController::class, 'store']);

    Route::prefix('staging')->group(function () {
        Route::get('/summary', [StagingController::class, 'summary']);
        Route::get('/', [StagingController::class, 'index']);
        Route::post('/import', [StagingController::class, 'import']);
        Route::post('/validate', [StagingController::class, 'validateStaging']);
        Route::post('/promote', [StagingController::class, 'promote']);
        Route::get('/aliases-pendientes', [StagingController::class, 'aliasesPendientes']);
    });
});
