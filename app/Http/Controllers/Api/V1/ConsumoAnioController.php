<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertConsumoAnioRequest;
use App\Http\Resources\ConsumoPlanResource;
use App\Services\ConsumoAnioService;
use Illuminate\Http\JsonResponse;

class ConsumoAnioController extends Controller
{
    public function __construct(
        private readonly ConsumoAnioService $consumoAnioService,
    ) {}

    public function show(int $anio): JsonResponse
    {
        $plan = $this->consumoAnioService->findPlan($anio);

        if ($plan === null) {
            return response()->json([
                'message' => "No existe plan de consumo anual para el año {$anio}.",
                'data' => null,
            ], 404);
        }

        return response()->json([
            'data' => new ConsumoPlanResource($plan),
        ]);
    }

    public function update(UpsertConsumoAnioRequest $request, int $anio): JsonResponse
    {
        $plan = $this->consumoAnioService->upsert($anio, $request->validated());

        return response()->json([
            'data' => new ConsumoPlanResource($plan),
            'message' => 'Plan de consumo anual guardado correctamente.',
        ]);
    }
}
