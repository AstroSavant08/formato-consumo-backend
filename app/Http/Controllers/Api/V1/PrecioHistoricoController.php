<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexPrecioHistoricoRequest;
use App\Http\Requests\ResolverPreciosVigentesRequest;
use App\Http\Requests\ShowPrecioVigenteRequest;
use App\Http\Requests\StorePrecioHistoricoRequest;
use App\Http\Resources\PrecioHistoricoResource;
use App\Models\Producto;
use App\Services\PrecioHistoricoService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class PrecioHistoricoController extends Controller
{
    public function index(
        IndexPrecioHistoricoRequest $request,
        PrecioHistoricoService $precioHistoricoService,
    ): JsonResponse {
        $precios = $precioHistoricoService->listarPorProducto($request->integer('producto_id'));

        return response()->json([
            'data' => PrecioHistoricoResource::collection($precios),
            'meta' => [
                'total' => $precios->count(),
                'producto_id' => $request->integer('producto_id'),
            ],
        ]);
    }

    public function showVigente(
        ShowPrecioVigenteRequest $request,
        int $producto,
        PrecioHistoricoService $precioHistoricoService,
    ): JsonResponse {
        $productoModel = Producto::query()->findOrFail($producto);
        $fecha = $request->filled('fecha')
            ? Carbon::parse($request->string('fecha'))
            : Carbon::today();

        $registro = $precioHistoricoService->resolverPrecioVigente($productoModel, $fecha);

        if ($registro === null) {
            return response()->json([
                'data' => [
                    'producto_id' => $productoModel->id,
                    'fecha' => $fecha->toDateString(),
                    'precio' => null,
                    'precio_historico_id' => null,
                    'vigente_desde' => null,
                    'vigente_hasta' => null,
                ],
                'message' => 'No hay precio histórico vigente para la fecha indicada.',
            ]);
        }

        return response()->json([
            'data' => [
                'producto_id' => $productoModel->id,
                'fecha' => $fecha->toDateString(),
                'precio' => (float) $registro->precio,
                'precio_historico_id' => $registro->id,
                'vigente_desde' => $registro->vigente_desde?->toDateString(),
                'vigente_hasta' => $registro->vigente_hasta?->toDateString(),
            ],
        ]);
    }

    public function resolverVigentes(
        ResolverPreciosVigentesRequest $request,
        PrecioHistoricoService $precioHistoricoService,
    ): JsonResponse {
        $fecha = $request->filled('fecha')
            ? Carbon::parse($request->string('fecha'))
            : Carbon::today();

        $mapa = $precioHistoricoService->resolverPreciosVigentes(
            $request->input('producto_ids', []),
            $fecha,
        );

        return response()->json([
            'data' => array_values($mapa),
            'meta' => [
                'fecha' => $fecha->toDateString(),
                'total' => count($mapa),
            ],
        ]);
    }

    public function store(
        StorePrecioHistoricoRequest $request,
        PrecioHistoricoService $precioHistoricoService,
    ): JsonResponse {
        $producto = Producto::query()->findOrFail($request->integer('producto_id'));

        try {
            $registro = $precioHistoricoService->registrarPrecio(
                $producto,
                (float) $request->input('precio'),
                Carbon::parse($request->string('vigente_desde')),
                $request->filled('vigente_hasta')
                    ? Carbon::parse($request->string('vigente_hasta'))
                    : null,
                $request->user()?->id,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => new PrecioHistoricoResource($registro),
            'message' => 'Precio histórico registrado correctamente.',
        ], 201);
    }
}
