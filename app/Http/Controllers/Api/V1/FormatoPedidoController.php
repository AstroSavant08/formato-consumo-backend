<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertFormatoPedidoRequest;
use App\Http\Resources\FormatoPedidoResource;
use App\Services\FormatoPedidoService;
use Illuminate\Http\JsonResponse;

class FormatoPedidoController extends Controller
{
    public function __construct(
        private readonly FormatoPedidoService $formatoPedidoService,
    ) {}

    public function show(int $anio): JsonResponse
    {
        $plan = $this->formatoPedidoService->findPlan($anio);

        if ($plan === null) {
            return response()->json([
                'message' => "No existe formato de pedido para el año {$anio}.",
                'data' => null,
            ], 404);
        }

        return response()->json([
            'data' => new FormatoPedidoResource($plan),
        ]);
    }

    public function update(UpsertFormatoPedidoRequest $request, int $anio): JsonResponse
    {
        $plan = $this->formatoPedidoService->upsert($anio, $request->validated());

        return response()->json([
            'data' => new FormatoPedidoResource($plan),
            'message' => 'Formato de pedido guardado correctamente.',
        ]);
    }
}
