<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShowDashboardResumenRequest;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function resumen(
        ShowDashboardResumenRequest $request,
        DashboardService $dashboardService,
    ): JsonResponse {
        $anio = $request->integer('anio', (int) now()->format('Y'));
        $mes = $request->integer('mes', (int) now()->format('n'));

        return response()->json([
            'data' => $dashboardService->obtenerResumen($anio, $mes),
        ]);
    }
}
