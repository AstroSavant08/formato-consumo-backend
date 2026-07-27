<?php

use App\Http\Controllers\Api\V1\AlertaController;
use App\Http\Controllers\Api\V1\AreaController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoriaController;
use App\Http\Controllers\Api\V1\ConsumoAnioController;
use App\Http\Controllers\Api\V1\EntregaController;
use App\Http\Controllers\Api\V1\FormatoPedidoController;
use App\Http\Controllers\Api\V1\InventarioController;
use App\Http\Controllers\Api\V1\MovimientoInventarioController;
use App\Http\Controllers\Api\V1\ProductoController;
use App\Http\Controllers\Api\V1\SolicitudController;
use App\Http\Controllers\Api\V1\StagingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'time' => now()->toIso8601String(),
    ]));

    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login']);
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });
    });

    Route::get('/areas', [AreaController::class, 'index']);
    Route::get('/categorias', [CategoriaController::class, 'index']);
    Route::get('/productos', [ProductoController::class, 'index']);
    Route::get('/entregas', [EntregaController::class, 'index']);
    Route::post('/entregas', [EntregaController::class, 'store']);

    Route::get('/movimientos-inventario', [MovimientoInventarioController::class, 'index']);
    Route::get('/alertas', [AlertaController::class, 'index']);
    Route::get('/inventarios', [InventarioController::class, 'index']);
    Route::post('/inventarios/{producto}/inicial', [InventarioController::class, 'storeInicial'])
        ->whereNumber('producto');
    Route::post('/inventarios/{producto}/entrada', [InventarioController::class, 'registrarEntrada'])
        ->whereNumber('producto');
    Route::post('/inventarios/{producto}/ajuste', [InventarioController::class, 'registrarAjuste'])
        ->whereNumber('producto');
    Route::get('/inventarios/{producto}', [InventarioController::class, 'show'])
        ->whereNumber('producto');

    Route::get('/solicitudes', [SolicitudController::class, 'index']);
    Route::get('/solicitudes/{solicitud}', [SolicitudController::class, 'show'])
        ->whereNumber('solicitud');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/solicitudes', [SolicitudController::class, 'store']);
        Route::patch('/solicitudes/{solicitud}', [SolicitudController::class, 'update'])
            ->whereNumber('solicitud');
        Route::post('/solicitudes/{solicitud}/aprobar', [SolicitudController::class, 'aprobar'])
            ->whereNumber('solicitud');
        Route::post('/solicitudes/{solicitud}/rechazar', [SolicitudController::class, 'rechazar'])
            ->whereNumber('solicitud');
        Route::post('/solicitudes/{solicitud}/cancelar', [SolicitudController::class, 'cancelar'])
            ->whereNumber('solicitud');
    });

    Route::get('/consumo-anio/{anio}', [ConsumoAnioController::class, 'show'])
        ->whereNumber('anio');
    Route::put('/consumo-anio/{anio}', [ConsumoAnioController::class, 'update'])
        ->whereNumber('anio');

    Route::get('/formato-pedido/{anio}', [FormatoPedidoController::class, 'show'])
        ->whereNumber('anio');
    Route::put('/formato-pedido/{anio}', [FormatoPedidoController::class, 'update'])
        ->whereNumber('anio');

    // Staging: lectura y operaciones administrativas del histórico Excel.
    // RIESGO ACTUAL: estas rutas no tienen auth:sanctum ni login funcional en frontend;
    // POST /{staging}/homologacion es público igual que import/validate/promote.
    Route::prefix('staging')->group(function () {
        Route::get('/summary', [StagingController::class, 'summary']);
        Route::get('/revision', [StagingController::class, 'revision']);
        Route::post('/homologaciones/bulk', [StagingController::class, 'bulkHomologacion']);
        Route::post('/validate-selected', [StagingController::class, 'validateSelected']);
        Route::post('/promote-selected', [StagingController::class, 'promoteSelected']);
        Route::get('/aliases-pendientes', [StagingController::class, 'aliasesPendientes']);
        Route::get('/{staging}/homologacion', [StagingController::class, 'showHomologacion']);
        Route::post('/{staging}/homologacion', [StagingController::class, 'storeHomologacion']);
        Route::get('/', [StagingController::class, 'index']);
        Route::post('/import', [StagingController::class, 'import']);
        Route::post('/validate', [StagingController::class, 'validateStaging']);
        Route::post('/promote', [StagingController::class, 'promote']);
    });
});
