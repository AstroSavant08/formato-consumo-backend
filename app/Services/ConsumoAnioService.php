<?php

namespace App\Services;

use App\Models\ConsumoPlan;
use App\Models\ConsumoPlanLinea;
use App\Models\ConsumoPlanMes;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ConsumoAnioService
{
    public function findPlan(int $anio): ?ConsumoPlan
    {
        return ConsumoPlan::query()
            ->where('anio', $anio)
            ->where('tipo', ConsumoPlan::TIPO_CONSUMO_ANIO)
            ->with(['lineas.meses'])
            ->first();
    }

    /**
     * @param  array{anio: int, productos: array<int, array<string, mixed>>}  $payload
     */
    public function upsert(int $anio, array $payload): ConsumoPlan
    {
        if ($payload['anio'] !== $anio) {
            throw new InvalidArgumentException('El año del payload no coincide con el parámetro de ruta.');
        }

        return DB::transaction(function () use ($anio, $payload) {
            $plan = ConsumoPlan::query()->firstOrCreate(
                [
                    'anio' => $anio,
                    'tipo' => ConsumoPlan::TIPO_CONSUMO_ANIO,
                ]
            );

            ConsumoPlanLinea::query()
                ->where('consumo_plan_id', $plan->id)
                ->delete();

            foreach ($payload['productos'] as $productoData) {
                $linea = ConsumoPlanLinea::query()->create([
                    'consumo_plan_id' => $plan->id,
                    'producto_id' => $productoData['producto_id'] ?? null,
                    'nombre_producto' => $productoData['nombre'],
                    'stock_debido' => $productoData['stock_debido'],
                    'orden' => $productoData['orden'],
                ]);

                $mesesNormalizados = $this->normalizeMeses($productoData['meses']);

                foreach ($mesesNormalizados as $mesData) {
                    ConsumoPlanMes::query()->create([
                        'consumo_plan_linea_id' => $linea->id,
                        'mes' => $mesData['mes'],
                        'cantidad' => $mesData['cantidad'],
                        'existencia' => $mesData['existencia'],
                        'dinero_solicitar' => $mesData['dinero_solicitar'],
                    ]);
                }
            }

            return $plan->fresh(['lineas.meses']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $meses
     * @return array<int, array{mes: int, cantidad: float|int|string, existencia: float|int|string, dinero_solicitar: float|int|string|null}>
     */
    private function normalizeMeses(array $meses): array
    {
        $porMes = [];

        foreach ($meses as $mesData) {
            $mes = (int) $mesData['mes'];
            $porMes[$mes] = [
                'mes' => $mes,
                'cantidad' => $mesData['cantidad'] ?? 0,
                'existencia' => $mesData['existencia'] ?? 0,
                'dinero_solicitar' => $mesData['dinero_solicitar'] ?? null,
            ];
        }

        $normalizados = [];

        for ($mes = 0; $mes <= 11; $mes++) {
            $normalizados[] = $porMes[$mes] ?? [
                'mes' => $mes,
                'cantidad' => 0,
                'existencia' => 0,
                'dinero_solicitar' => null,
            ];
        }

        return $normalizados;
    }
}
