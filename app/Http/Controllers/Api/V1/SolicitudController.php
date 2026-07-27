<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InventarioException;
use App\Exceptions\SolicitudException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SolicitudTransicionRequest;
use App\Http\Requests\StoreSolicitudRequest;
use App\Http\Requests\UpdateSolicitudRequest;
use App\Http\Resources\SolicitudResource;
use App\Models\Solicitud;
use App\Services\SolicitudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SolicitudController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Solicitud::query()->with(['area', 'usuario', 'detalles.producto']);

        if ($request->filled('estado')) {
            $query->where('estado', $request->string('estado'));
        }

        if ($request->filled('area_id')) {
            $query->where('area_id', $request->integer('area_id'));
        }

        $perPage = 50;
        $solicitudes = $query->orderByDesc('fecha')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => SolicitudResource::collection($solicitudes),
            'meta' => [
                'current_page' => $solicitudes->currentPage(),
                'last_page' => $solicitudes->lastPage(),
                'total' => $solicitudes->total(),
                'per_page' => $solicitudes->perPage(),
            ],
        ]);
    }

    public function show(int $solicitud): JsonResponse
    {
        $model = Solicitud::query()
            ->with(['area', 'usuario', 'aprobadoPor', 'detalles.producto'])
            ->find($solicitud);

        if (! $model) {
            return response()->json([
                'message' => 'Solicitud no encontrada.',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'data' => new SolicitudResource($model),
        ]);
    }

    public function store(StoreSolicitudRequest $request, SolicitudService $service): JsonResponse
    {
        try {
            $solicitud = $service->crear([
                'area_id' => $request->integer('area_id'),
                'fecha' => $request->validated('fecha'),
                'justificacion' => $request->input('justificacion'),
                'observaciones' => $request->input('observaciones'),
                'detalles' => $request->input('detalles'),
            ], (int) Auth::id());
        } catch (SolicitudException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'data' => $exception->data,
            ], $exception->status);
        } catch (InventarioException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'data' => $exception->data,
            ], $exception->status);
        }

        return response()->json([
            'message' => 'Solicitud creada correctamente.',
            'data' => new SolicitudResource($solicitud),
        ], 201);
    }

    public function update(UpdateSolicitudRequest $request, int $solicitud, SolicitudService $service): JsonResponse
    {
        $model = Solicitud::query()->find($solicitud);

        if (! $model) {
            return response()->json([
                'message' => 'Solicitud no encontrada.',
                'data' => null,
            ], 404);
        }

        try {
            $actualizada = $service->actualizar($model, $request->validated());
        } catch (SolicitudException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'data' => $exception->data,
            ], $exception->status);
        } catch (InventarioException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'data' => $exception->data,
            ], $exception->status);
        }

        return response()->json([
            'message' => 'Solicitud actualizada correctamente.',
            'data' => new SolicitudResource($actualizada),
        ]);
    }

    public function aprobar(
        SolicitudTransicionRequest $request,
        int $solicitud,
        SolicitudService $service,
    ): JsonResponse {
        $model = Solicitud::query()->find($solicitud);

        if (! $model) {
            return response()->json([
                'message' => 'Solicitud no encontrada.',
                'data' => null,
            ], 404);
        }

        try {
            $aprobada = $service->aprobar($model, (int) Auth::id());
        } catch (SolicitudException $exception) {
            return $this->mapException($exception);
        } catch (InventarioException $exception) {
            return $this->mapInventarioException($exception);
        }

        return response()->json([
            'message' => 'Solicitud aprobada correctamente.',
            'data' => new SolicitudResource($aprobada),
        ]);
    }

    public function rechazar(
        SolicitudTransicionRequest $request,
        int $solicitud,
        SolicitudService $service,
    ): JsonResponse {
        $model = Solicitud::query()->find($solicitud);

        if (! $model) {
            return response()->json([
                'message' => 'Solicitud no encontrada.',
                'data' => null,
            ], 404);
        }

        try {
            $rechazada = $service->rechazar($model, (int) Auth::id());
        } catch (SolicitudException $exception) {
            return $this->mapException($exception);
        }

        return response()->json([
            'message' => 'Solicitud rechazada correctamente.',
            'data' => new SolicitudResource($rechazada),
        ]);
    }

    public function cancelar(int $solicitud, SolicitudService $service): JsonResponse
    {
        $model = Solicitud::query()->find($solicitud);

        if (! $model) {
            return response()->json([
                'message' => 'Solicitud no encontrada.',
                'data' => null,
            ], 404);
        }

        try {
            $cancelada = $service->cancelar($model);
        } catch (SolicitudException $exception) {
            return $this->mapException($exception);
        } catch (InventarioException $exception) {
            return $this->mapInventarioException($exception);
        }

        return response()->json([
            'message' => 'Solicitud cancelada correctamente.',
            'data' => new SolicitudResource($cancelada),
        ]);
    }

    private function mapException(SolicitudException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'data' => $exception->data,
        ], $exception->status);
    }

    private function mapInventarioException(InventarioException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'data' => $exception->data,
        ], $exception->status);
    }
}
