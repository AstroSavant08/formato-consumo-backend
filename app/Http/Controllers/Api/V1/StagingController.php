<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ExcelImportStaging;
use App\Models\ProductoAlias;
use App\Services\ExcelImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StagingController extends Controller
{
    public function summary(ExcelImportService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->getStagingSummary(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ExcelImportStaging::query()->orderBy('fila_excel');

        if ($request->filled('estado')) {
            $query->where('estado', $request->string('estado'));
        }

        $records = $query->paginate(50);

        return response()->json([
            'data' => $records->items(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    public function import(Request $request, ExcelImportService $service): JsonResponse
    {
        $path = $request->input('path')
            ?? base_path('../formato-consumo-frontend/docs/Consumo_DESARROLLO.xlsx');

        $result = $service->importToStaging($path);

        return response()->json([
            'message' => 'Importación a staging completada',
            'data' => $result,
        ]);
    }

    public function validateStaging(ExcelImportService $service): JsonResponse
    {
        $result = $service->validateStaging();

        return response()->json([
            'message' => 'Validación de staging completada',
            'data' => $result,
        ]);
    }

    public function promote(ExcelImportService $service): JsonResponse
    {
        $result = $service->promoteValidated();

        return response()->json([
            'message' => 'Promoción de registros validados completada',
            'data' => $result,
        ]);
    }

    public function aliasesPendientes(): JsonResponse
    {
        $aliases = ProductoAlias::query()
            ->where('requiere_revision', true)
            ->orderBy('alias')
            ->get();

        return response()->json([
            'data' => $aliases,
            'meta' => ['total' => $aliases->count()],
        ]);
    }
}
