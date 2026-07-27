<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexMovimientoInventarioRequest;
use App\Http\Resources\MovimientoInventarioResource;
use App\Models\MovimientoInventario;
use Illuminate\Http\JsonResponse;

class MovimientoInventarioController extends Controller
{
    public function index(IndexMovimientoInventarioRequest $request): JsonResponse
    {
        $query = MovimientoInventario::query()
            ->with(['producto', 'usuario'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('producto_id')) {
            $query->where('producto_id', $request->integer('producto_id'));
        }

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->string('tipo'));
        }

        if ($request->filled('fecha_desde')) {
            $query->whereDate('created_at', '>=', $request->string('fecha_desde'));
        }

        if ($request->filled('fecha_hasta')) {
            $query->whereDate('created_at', '<=', $request->string('fecha_hasta'));
        }

        $perPage = 50;
        $movimientos = $query->paginate($perPage);

        return response()->json([
            'data' => MovimientoInventarioResource::collection($movimientos),
            'meta' => [
                'current_page' => $movimientos->currentPage(),
                'last_page' => $movimientos->lastPage(),
                'total' => $movimientos->total(),
                'per_page' => $movimientos->perPage(),
            ],
        ]);
    }
}
