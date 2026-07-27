<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShowSemaforoConsumoRequest;
use App\Http\Resources\AlertaResource;
use App\Http\Resources\SemaforoConsumoResource;
use App\Models\Producto;
use App\Services\AlertaConsumoService;
use App\Services\SemaforoConsumoService;
use Illuminate\Http\JsonResponse;

class SemaforoConsumoController extends Controller
{
    public function show(
        ShowSemaforoConsumoRequest $request,
        SemaforoConsumoService $semaforoConsumoService,
    ): JsonResponse {
        $producto = Producto::query()->findOrFail($request->integer('producto_id'));

        $evaluacion = $semaforoConsumoService->evaluar(
            $producto,
            $request->integer('mes'),
            $request->integer('anio'),
            $request->filled('area_id') ? $request->integer('area_id') : null,
        );

        return response()->json([
            'data' => new SemaforoConsumoResource($evaluacion),
        ]);
    }

    public function evaluarAlerta(
        ShowSemaforoConsumoRequest $request,
        AlertaConsumoService $alertaConsumoService,
    ): JsonResponse {
        $producto = Producto::query()->findOrFail($request->integer('producto_id'));

        $resultado = $alertaConsumoService->evaluarYCrearAlerta(
            $producto,
            $request->integer('mes'),
            $request->integer('anio'),
            $request->filled('area_id') ? $request->integer('area_id') : null,
        );

        return response()->json([
            'data' => [
                'evaluacion' => new SemaforoConsumoResource($resultado['evaluacion']),
                'alerta' => $resultado['alerta'] ? new AlertaResource($resultado['alerta']) : null,
                'alerta_creada' => $resultado['alerta_creada'],
            ],
        ]);
    }
}
