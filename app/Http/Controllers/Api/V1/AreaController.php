<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AreaResource;
use App\Models\Area;
use Illuminate\Http\JsonResponse;

class AreaController extends Controller
{
    public function index(): JsonResponse
    {
        $areas = Area::query()->where('activo', true)->orderBy('nombre')->get();

        return response()->json([
            'data' => AreaResource::collection($areas),
        ]);
    }
}
