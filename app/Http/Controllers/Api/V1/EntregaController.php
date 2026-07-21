<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEntregaRequest;
use App\Http\Resources\EntregaResource;
use App\Models\Entrega;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntregaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Entrega::query()->with(['area', 'producto']);

        if ($request->filled('fuente')) {
            $query->where('fuente', $request->string('fuente'));
        }

        if ($request->filled('area_id')) {
            $query->where('area_id', $request->integer('area_id'));
        }

        if ($request->filled('producto_id')) {
            $query->where('producto_id', $request->integer('producto_id'));
        }

        if ($request->filled('desde')) {
            $query->whereDate('fecha', '>=', $request->string('desde'));
        }

        if ($request->filled('hasta')) {
            $query->whereDate('fecha', '<=', $request->string('hasta'));
        }

        $entregas = $query->orderByDesc('fecha')->paginate(50);

        return response()->json([
            'data' => EntregaResource::collection($entregas),
            'meta' => [
                'current_page' => $entregas->currentPage(),
                'last_page' => $entregas->lastPage(),
                'total' => $entregas->total(),
            ],
        ]);
    }

    public function store(StoreEntregaRequest $request): JsonResponse
    {
        $entrega = Entrega::query()->create([
            'fecha' => $request->validated('fecha'),
            'producto_id' => $request->validated('producto_id'),
            'area_id' => $request->validated('area_id'),
            'cantidad' => $request->validated('cantidad'),
            'unidad' => $request->validated('unidad'),
            'quien_recibe' => $request->validated('quien_recibe'),
            'entregado_por' => $request->validated('entregado_por'),
            'fuente' => 'sistema',
        ]);

        $entrega->load(['area', 'producto']);

        return response()->json([
            'data' => new EntregaResource($entrega),
        ], 201);
    }
}
