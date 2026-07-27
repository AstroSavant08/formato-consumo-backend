<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InventarioException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInventarioInicialRequest;
use App\Http\Requests\RegistrarAjusteInventarioRequest;
use App\Http\Requests\RegistrarEntradaInventarioRequest;
use App\Http\Resources\InventarioResource;
use App\Http\Resources\MovimientoInventarioResource;
use App\Models\Inventario;
use App\Models\Producto;
use App\Services\InventarioService;
use Illuminate\Http\JsonResponse;

class InventarioController extends Controller
{
    public function index(): JsonResponse
    {
        $inventarios = Inventario::query()
            ->with('producto')
            ->orderBy('producto_id')
            ->get();

        return response()->json([
            'data' => InventarioResource::collection($inventarios),
            'meta' => [
                'total' => $inventarios->count(),
            ],
        ]);
    }

    public function show(int $producto): JsonResponse
    {
        if (! Producto::query()->whereKey($producto)->exists()) {
            return response()->json([
                'message' => 'Producto no encontrado.',
                'data' => null,
            ], 404);
        }

        $inventario = Inventario::query()
            ->with('producto')
            ->where('producto_id', $producto)
            ->first();

        if (! $inventario) {
            return response()->json([
                'message' => 'El producto no tiene inventario configurado.',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'data' => new InventarioResource($inventario),
        ]);
    }

    public function storeInicial(
        CreateInventarioInicialRequest $request,
        int $producto,
        InventarioService $service,
    ): JsonResponse {
        try {
            $inventario = $service->crearInventarioInicial(
                $producto,
                (float) $request->input('stock_inicial'),
                $request->has('stock_minimo') ? (float) $request->input('stock_minimo') : null,
            );
        } catch (InventarioException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'data' => $exception->data,
            ], $exception->status);
        }

        $inventario->load('producto');

        return response()->json([
            'message' => 'Inventario inicial creado correctamente.',
            'data' => new InventarioResource($inventario),
        ], 201);
    }

    public function registrarEntrada(
        RegistrarEntradaInventarioRequest $request,
        int $producto,
        InventarioService $service,
    ): JsonResponse {
        try {
            $movimiento = $service->registrarEntrada(
                $producto,
                (float) $request->input('cantidad'),
                $request->input('referencia_tipo'),
                $request->input('referencia_id'),
                $request->input('observaciones'),
                null,
                $request->input('unidad'),
            );
        } catch (InventarioException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'data' => $exception->data,
            ], $exception->status);
        }

        $movimiento->load(['producto', 'usuario']);
        $inventario = Inventario::query()->with('producto')->where('producto_id', $producto)->first();

        return response()->json([
            'message' => 'Entrada de inventario registrada correctamente.',
            'data' => [
                'movimiento' => new MovimientoInventarioResource($movimiento),
                'stock_anterior' => (float) $movimiento->stock_anterior,
                'stock_posterior' => (float) $movimiento->stock_posterior,
                'inventario' => $inventario ? new InventarioResource($inventario) : null,
            ],
        ], 201);
    }

    public function registrarAjuste(
        RegistrarAjusteInventarioRequest $request,
        int $producto,
        InventarioService $service,
    ): JsonResponse {
        try {
            $movimiento = $service->registrarAjuste(
                $producto,
                (float) $request->input('nuevo_stock'),
                $request->input('observaciones'),
            );
        } catch (InventarioException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'data' => $exception->data,
            ], $exception->status);
        }

        $movimiento->load(['producto', 'usuario']);
        $inventario = Inventario::query()->with('producto')->where('producto_id', $producto)->first();

        return response()->json([
            'message' => 'Ajuste de inventario registrado correctamente.',
            'data' => [
                'movimiento' => new MovimientoInventarioResource($movimiento),
                'stock_anterior' => (float) $movimiento->stock_anterior,
                'stock_posterior' => (float) $movimiento->stock_posterior,
                'inventario' => $inventario ? new InventarioResource($inventario) : null,
            ],
        ], 201);
    }
}
