<?php

use App\Http\Controllers\Api\V1\AlertaController;
use App\Http\Controllers\Api\V1\AreaController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoriaController;
use App\Http\Controllers\Api\V1\ConsumoAnioController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EntregaController;
use App\Http\Controllers\Api\V1\FormatoPedidoController;
use App\Http\Controllers\Api\V1\InventarioController;
use App\Http\Controllers\Api\V1\MovimientoInventarioController;
use App\Http\Controllers\Api\V1\PersonaController;
use App\Http\Controllers\Api\V1\PrecioHistoricoController;
use App\Http\Controllers\Api\V1\ProductoController;
use App\Http\Controllers\Api\V1\SemaforoConsumoController;
use App\Http\Controllers\Api\V1\SolicitudController;
use App\Http\Controllers\Api\V1\StagingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'time' => now()->toIso8601String(),
    ]));

    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::prefix('auth')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });

        Route::middleware('role:admin,supervisor,almacen')->group(function () {
            Route::get('/areas', [AreaController::class, 'index']);
            Route::get('/categorias', [CategoriaController::class, 'index']);
            Route::get('/productos', [ProductoController::class, 'index']);
            Route::get('/entregas', [EntregaController::class, 'index']);
            Route::post('/entregas', [EntregaController::class, 'store']);
            Route::get('/inventarios', [InventarioController::class, 'index']);
            Route::get('/inventarios/{producto}', [InventarioController::class, 'show'])
                ->whereNumber('producto');
            Route::get('/personas', [PersonaController::class, 'index']);
            Route::get('/personas/cedula/{cedula}', [PersonaController::class, 'showByCedula']);
        });

        Route::middleware('role:admin,supervisor,solicitante')->group(function () {
            Route::post('/solicitudes', [SolicitudController::class, 'store']);
            Route::patch('/solicitudes/{solicitud}', [SolicitudController::class, 'update'])
                ->whereNumber('solicitud');
        });

        Route::middleware('role:admin,supervisor')->group(function () {
            Route::get('/dashboard/resumen', [DashboardController::class, 'resumen']);

            Route::get('/productos/{producto}/precio-vigente', [PrecioHistoricoController::class, 'showVigente'])
                ->whereNumber('producto');
            Route::get('/precios-historicos', [PrecioHistoricoController::class, 'index']);
            Route::post('/precios-historicos/vigentes', [PrecioHistoricoController::class, 'resolverVigentes']);

            Route::get('/movimientos-inventario', [MovimientoInventarioController::class, 'index']);
            Route::get('/alertas', [AlertaController::class, 'index']);
            Route::get('/semaforo/consumo', [SemaforoConsumoController::class, 'show']);

            Route::get('/solicitudes', [SolicitudController::class, 'index']);
            Route::get('/solicitudes/{solicitud}', [SolicitudController::class, 'show'])
                ->whereNumber('solicitud');
            Route::post('/solicitudes/{solicitud}/aprobar', [SolicitudController::class, 'aprobar'])
                ->whereNumber('solicitud');
            Route::post('/solicitudes/{solicitud}/rechazar', [SolicitudController::class, 'rechazar'])
                ->whereNumber('solicitud');
            Route::post('/solicitudes/{solicitud}/cancelar', [SolicitudController::class, 'cancelar'])
                ->whereNumber('solicitud');

            Route::post('/inventarios/{producto}/inicial', [InventarioController::class, 'storeInicial'])
                ->whereNumber('producto');
            Route::post('/inventarios/{producto}/entrada', [InventarioController::class, 'registrarEntrada'])
                ->whereNumber('producto');
            Route::post('/inventarios/{producto}/ajuste', [InventarioController::class, 'registrarAjuste'])
                ->whereNumber('producto');

            Route::post('/semaforo/consumo/evaluar', [SemaforoConsumoController::class, 'evaluarAlerta']);
            Route::post('/precios-historicos', [PrecioHistoricoController::class, 'store']);
            Route::patch('/alertas/{alerta}', [AlertaController::class, 'update'])
                ->whereNumber('alerta');
            Route::put('/consumo-anio/{anio}', [ConsumoAnioController::class, 'update'])
                ->whereNumber('anio');
            Route::put('/formato-pedido/{anio}', [FormatoPedidoController::class, 'update'])
                ->whereNumber('anio');

            Route::get('/consumo-anio/{anio}', [ConsumoAnioController::class, 'show'])
                ->whereNumber('anio');
            Route::get('/formato-pedido/{anio}', [FormatoPedidoController::class, 'show'])
                ->whereNumber('anio');

            Route::prefix('staging')->group(function () {
                Route::get('/summary', [StagingController::class, 'summary']);
                Route::get('/revision', [StagingController::class, 'revision']);
                Route::get('/aliases-pendientes', [StagingController::class, 'aliasesPendientes']);
                Route::get('/{staging}/homologacion', [StagingController::class, 'showHomologacion']);
                Route::get('/', [StagingController::class, 'index']);

                Route::post('/homologaciones/bulk', [StagingController::class, 'bulkHomologacion']);
                Route::post('/validate-selected', [StagingController::class, 'validateSelected']);
                Route::post('/promote-selected', [StagingController::class, 'promoteSelected']);
                Route::post('/{staging}/homologacion', [StagingController::class, 'storeHomologacion']);
                Route::post('/import', [StagingController::class, 'import']);
                Route::post('/validate', [StagingController::class, 'validateStaging']);
                Route::post('/promote', [StagingController::class, 'promote']);
            });
        });
    });
});
