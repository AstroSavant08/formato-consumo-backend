<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoriaResource;
use App\Models\Categoria;
use Illuminate\Http\JsonResponse;

class CategoriaController extends Controller
{
    public function index(): JsonResponse
    {
        $categorias = Categoria::query()->where('activo', true)->orderBy('nombre')->get();

        return response()->json([
            'data' => CategoriaResource::collection($categorias),
        ]);
    }
}
