<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexAlertaRequest;
use App\Http\Resources\AlertaResource;
use App\Models\Alerta;
use Illuminate\Http\JsonResponse;

class AlertaController extends Controller
{
    public function index(IndexAlertaRequest $request): JsonResponse
    {
        $query = Alerta::query()
            ->with('producto')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('producto_id')) {
            $query->where('producto_id', $request->integer('producto_id'));
        }

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->string('tipo'));
        }

        if ($request->has('leida')) {
            $query->where('leida', $request->boolean('leida'));
        }

        $perPage = 50;
        $alertas = $query->paginate($perPage);

        return response()->json([
            'data' => AlertaResource::collection($alertas),
            'meta' => [
                'current_page' => $alertas->currentPage(),
                'last_page' => $alertas->lastPage(),
                'total' => $alertas->total(),
                'per_page' => $alertas->perPage(),
            ],
        ]);
    }
}
