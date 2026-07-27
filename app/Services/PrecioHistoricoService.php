<?php

namespace App\Services;

use App\Models\PrecioHistorico;
use App\Models\Producto;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PrecioHistoricoService
{
    /**
     * Precio vigente para un producto en una fecha (inclusive).
     */
    public function resolverPrecioVigente(Producto $producto, ?Carbon $fecha = null): ?PrecioHistorico
    {
        $fecha ??= Carbon::today();

        return PrecioHistorico::query()
            ->where('producto_id', $producto->id)
            ->whereDate('vigente_desde', '<=', $fecha)
            ->where(function ($query) use ($fecha) {
                $query->whereNull('vigente_hasta')
                    ->orWhereDate('vigente_hasta', '>=', $fecha);
            })
            ->orderByDesc('vigente_desde')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  int[]  $productoIds
     * @return array<int, array{producto_id: int, precio: float|null, precio_historico_id: int|null, vigente_desde: string|null}>
     */
    public function resolverPreciosVigentes(array $productoIds, ?Carbon $fecha = null): array
    {
        $fecha ??= Carbon::today();
        $resultado = [];

        foreach ($productoIds as $productoId) {
            $productoId = (int) $productoId;
            $registro = PrecioHistorico::query()
                ->where('producto_id', $productoId)
                ->whereDate('vigente_desde', '<=', $fecha)
                ->where(function ($query) use ($fecha) {
                    $query->whereNull('vigente_hasta')
                        ->orWhereDate('vigente_hasta', '>=', $fecha);
                })
                ->orderByDesc('vigente_desde')
                ->orderByDesc('id')
                ->first();

            $resultado[$productoId] = [
                'producto_id' => $productoId,
                'precio' => $registro ? (float) $registro->precio : null,
                'precio_historico_id' => $registro?->id,
                'vigente_desde' => $registro?->vigente_desde?->toDateString(),
            ];
        }

        return $resultado;
    }

    public function calcularSubtotal(float $cantidad, ?float $precioUnitario): float
    {
        if ($precioUnitario === null) {
            return 0.0;
        }

        return round($cantidad * $precioUnitario, 2);
    }

    /**
     * @return Collection<int, PrecioHistorico>
     */
    public function listarPorProducto(int $productoId): Collection
    {
        return PrecioHistorico::query()
            ->where('producto_id', $productoId)
            ->orderByDesc('vigente_desde')
            ->orderByDesc('id')
            ->get();
    }

    public function registrarPrecio(
        Producto $producto,
        float $precio,
        Carbon $vigenteDesde,
        ?Carbon $vigenteHasta,
        ?int $usuarioId = null,
    ): PrecioHistorico {
        if ($precio < 0) {
            throw new InvalidArgumentException('El precio no puede ser negativo.');
        }

        if ($vigenteHasta !== null && $vigenteHasta->lt($vigenteDesde)) {
            throw new InvalidArgumentException('vigente_hasta no puede ser anterior a vigente_desde.');
        }

        $this->cerrarVigenciasAbiertasAnteriores($producto->id, $vigenteDesde);

        return PrecioHistorico::query()->create([
            'producto_id' => $producto->id,
            'precio' => round($precio, 2),
            'vigente_desde' => $vigenteDesde->toDateString(),
            'vigente_hasta' => $vigenteHasta?->toDateString(),
            'usuario_id' => $usuarioId,
        ]);
    }

    private function cerrarVigenciasAbiertasAnteriores(int $productoId, Carbon $nuevaVigenciaDesde): void
    {
        $cierre = $nuevaVigenciaDesde->copy()->subDay();

        PrecioHistorico::query()
            ->where('producto_id', $productoId)
            ->whereNull('vigente_hasta')
            ->whereDate('vigente_desde', '<', $nuevaVigenciaDesde)
            ->update(['vigente_hasta' => $cierre->toDateString()]);
    }
}
