<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PersonaResource;
use App\Services\PersonaService;
use App\Support\CedulaNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonaController extends Controller
{
    public function __construct(
        private readonly PersonaService $personaService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = \App\Models\Persona::query()->where('activo', true);

        if ($request->filled('cedula')) {
            $cedula = CedulaNormalizer::normalize($request->string('cedula'));
            $query->where('cedula', 'like', $cedula.'%');
        }

        if ($request->filled('q')) {
            $term = trim($request->string('q'));
            $query->where(function ($q) use ($term) {
                $q->where('nombre_completo', 'like', '%'.$term.'%')
                    ->orWhere('cedula', 'like', '%'.CedulaNormalizer::normalize($term).'%');
            });
        }

        $personas = $query
            ->orderBy('nombre_completo')
            ->limit($request->integer('limit', 20))
            ->get();

        return response()->json([
            'data' => PersonaResource::collection($personas),
        ]);
    }

    public function showByCedula(string $cedula): JsonResponse
    {
        $persona = $this->personaService->findByCedula($cedula);

        if (! $persona) {
            return response()->json([
                'message' => 'No se encontró persona con esa cédula.',
            ], 404);
        }

        return response()->json([
            'data' => new PersonaResource($persona),
        ]);
    }
}
