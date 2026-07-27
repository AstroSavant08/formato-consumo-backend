<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkStagingHomologacionRequest;
use App\Http\Requests\PromoteSelectedStagingRequest;
use App\Http\Requests\ValidateSelectedStagingRequest;
use App\Http\Requests\StoreStagingHomologacionRequest;
use App\Http\Resources\ExcelImportHomologacionResource;
use App\Http\Resources\StagingRevisionResource;
use App\Models\Area;
use App\Models\ExcelImportStaging;
use App\Models\ProductoAlias;
use App\Exceptions\StagingHomologacionException;
use App\Services\ExcelImportService;
use App\Services\StagingHomologacionService;
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

    public function revision(Request $request): JsonResponse
    {
        $query = ExcelImportStaging::query()
            ->with(['producto', 'area', 'homologacion.productoDestino'])
            ->orderBy('fila_excel');

        if ($request->filled('estado')) {
            $query->where('estado', $request->string('estado'));
        }

        if ($request->filled('producto')) {
            $term = $request->string('producto');
            $query->where('producto_raw', 'like', '%'.$term.'%');
        }

        if ($request->filled('area_id')) {
            $area = Area::query()
                ->where('activo', true)
                ->find($request->integer('area_id'));

            if ($area) {
                $query->where(function ($builder) use ($area) {
                    $builder->where('area_id', $area->id)
                        ->orWhere('area_raw', $area->codigo)
                        ->orWhere('area_raw', $area->nombre);
                });
            }
        }

        if ($request->filled('homologacion')) {
            $homologacion = $request->string('homologacion');

            if ($homologacion === 'con') {
                $query->whereHas('homologacion');
            } elseif ($homologacion === 'sin') {
                $query->whereDoesntHave('homologacion');
            }
        }

        $records = $query->paginate(50);

        return response()->json([
            'data' => StagingRevisionResource::collection($records->items()),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'total' => $records->total(),
                'per_page' => $records->perPage(),
            ],
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

    public function showHomologacion(
        ExcelImportStaging $staging,
        StagingHomologacionService $service,
    ): JsonResponse {
        $homologacion = $service->findForStaging($staging->id);

        if (! $homologacion) {
            return response()->json([
                'message' => 'No existe homologación para este registro de staging.',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'data' => new ExcelImportHomologacionResource($homologacion),
        ]);
    }

    public function storeHomologacion(
        StoreStagingHomologacionRequest $request,
        ExcelImportStaging $staging,
        StagingHomologacionService $service,
    ): JsonResponse {
        try {
            $homologacion = $service->homologar(
                $staging->id,
                $request->integer('producto_id_destino'),
                $request->input('notas'),
                $request->user()?->name ?? $request->user()?->email,
                $request->boolean('confirmar_reemplazo'),
            );
        } catch (StagingHomologacionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'data' => null,
            ], $exception->status);
        }

        return response()->json([
            'message' => 'Homologación registrada correctamente.',
            'data' => new ExcelImportHomologacionResource($homologacion),
        ], 201);
    }

    public function bulkHomologacion(
        BulkStagingHomologacionRequest $request,
        StagingHomologacionService $service,
    ): JsonResponse {
        try {
            $report = $service->homologarBulk(
                $request->input('staging_ids'),
                $request->integer('producto_id_destino'),
                $request->input('notas'),
                $request->user()?->name ?? $request->user()?->email,
                $request->boolean('confirmar_reemplazo'),
            );
        } catch (StagingHomologacionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'data' => null,
            ], $exception->status);
        }

        $homologados = count($report['homologados']);
        $omitidos = count($report['omitidos']);
        $errores = count($report['errores']);

        return response()->json([
            'message' => "Homologación masiva completada: {$homologados} homologados, {$omitidos} omitidos, {$errores} errores.",
            'data' => $report,
            'meta' => [
                'homologados' => $homologados,
                'omitidos' => $omitidos,
                'errores' => $errores,
            ],
        ]);
    }

    public function validateSelected(
        ValidateSelectedStagingRequest $request,
        ExcelImportService $service,
    ): JsonResponse {
        $report = $service->validateSelectedStaging($request->input('staging_ids'));

        $validados = count($report['validados']);
        $requierenRevision = count($report['requieren_revision']);
        $omitidos = count($report['omitidos']);
        $errores = count($report['errores']);

        return response()->json([
            'message' => "Validación controlada completada: {$validados} validados, {$requierenRevision} requieren revisión, {$omitidos} omitidos, {$errores} errores.",
            'data' => $report,
            'meta' => [
                'validados' => $validados,
                'requieren_revision' => $requierenRevision,
                'omitidos' => $omitidos,
                'errores' => $errores,
            ],
        ]);
    }

    public function promoteSelected(
        PromoteSelectedStagingRequest $request,
        ExcelImportService $service,
    ): JsonResponse {
        try {
            $report = $service->promoteSelectedStaging(
                $request->input('staging_ids'),
                $request->boolean('confirmar_promocion'),
            );
        } catch (StagingHomologacionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'data' => null,
            ], $exception->status);
        }

        $promovidos = count($report['promovidos']);
        $omitidos = count($report['omitidos']);
        $errores = count($report['errores']);

        return response()->json([
            'message' => "Promoción controlada completada: {$promovidos} promovidos, {$omitidos} omitidos, {$errores} errores.",
            'data' => $report,
            'meta' => [
                'promovidos' => $promovidos,
                'omitidos' => $omitidos,
                'errores' => $errores,
            ],
        ]);
    }
}
