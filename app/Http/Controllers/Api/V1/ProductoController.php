<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductoResource;
use App\Models\Producto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Producto::query()->with('aliases')->where('activo', true);

        if ($request->filled('historico')) {
            $query->where('es_historico_excel', filter_var($request->historico, FILTER_VALIDATE_BOOLEAN));
        }

        $productos = $query->orderBy('nombre')->paginate(100);

        return response()->json([
            'data' => ProductoResource::collection($productos),
            'meta' => [
                'current_page' => $productos->currentPage(),
                'last_page' => $productos->lastPage(),
                'total' => $productos->total(),
            ],
        ]);
    }
}
