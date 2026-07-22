<?php

namespace App\Services;

use App\Models\ConsumoPlan;
use App\Models\ConsumoPlanLinea;
use App\Models\ConsumoPlanMes;
use App\Models\ConsumoPlanPedidoEspecial;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FormatoPedidoService
{
    public function findPlan(int $anio): ?ConsumoPlan
    {
        return ConsumoPlan::query()
            ->where('anio', $anio)
            ->where('tipo', ConsumoPlan::TIPO_FORMATO_PEDIDO)
            ->with(['lineas.meses', 'pedidosEspeciales'])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsert(int $anio, array $payload): ConsumoPlan
    {
        if ($payload['anio'] !== $anio) {
            throw new InvalidArgumentException('El año del payload no coincide con el parámetro de ruta.');
        }

        return DB::transaction(function () use ($anio, $payload) {
            $firma = $payload['firma'] ?? [];

            $plan = ConsumoPlan::query()->updateOrCreate(
                [
                    'anio' => $anio,
                    'tipo' => ConsumoPlan::TIPO_FORMATO_PEDIDO,
                ],
                [
                    'fecha_pedido' => $firma['fecha'] ?? null,
                    'solicitado_por' => $firma['solicitado_por'] ?? null,
                    'autorizado_por' => $firma['autorizado_por'] ?? null,
                    'cantidad_dinero_solicitada' => $firma['cantidad_dinero'] ?? null,
                ]
            );

            ConsumoPlanLinea::query()
                ->where('consumo_plan_id', $plan->id)
                ->delete();

            ConsumoPlanPedidoEspecial::query()
                ->where('consumo_plan_id', $plan->id)
                ->delete();

            foreach ($payload['productos'] as $productoData) {
                $linea = ConsumoPlanLinea::query()->create([
                    'consumo_plan_id' => $plan->id,
                    'producto_id' => $productoData['producto_id'] ?? null,
                    'nombre_producto' => $productoData['nombre'],
                    'stock_debido' => $productoData['stock_debido'],
                    'dinero_solicitado' => $productoData['dinero_solicitado'] ?? null,
                    'orden' => $productoData['orden'],
                ]);

                $mesesNormalizados = $this->normalizeMeses($productoData['meses']);

                foreach ($mesesNormalizados as $mesData) {
                    ConsumoPlanMes::query()->create([
                        'consumo_plan_linea_id' => $linea->id,
                        'mes' => $mesData['mes'],
                        'cantidad' => $mesData['cantidad'],
                        'existencia' => 0,
                        'dinero_solicitar' => null,
                    ]);
                }
            }

            foreach ($payload['pedido_especial'] ?? [] as $especialData) {
                ConsumoPlanPedidoEspecial::query()->create([
                    'consumo_plan_id' => $plan->id,
                    'orden' => $especialData['orden'],
                    'descripcion' => $especialData['que'],
                    'cantidad' => $especialData['cantidad'] ?? null,
                ]);
            }

            return $plan->fresh(['lineas.meses', 'pedidosEspeciales']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $meses
     * @return array<int, array{mes: int, cantidad: float|int|string}>
     */
    private function normalizeMeses(array $meses): array
    {
        $porMes = [];

        foreach ($meses as $mesData) {
            $mes = (int) $mesData['mes'];
            $porMes[$mes] = [
                'mes' => $mes,
                'cantidad' => $mesData['cantidad'] ?? 0,
            ];
        }

        $normalizados = [];

        for ($mes = 0; $mes <= 11; $mes++) {
            $normalizados[] = $porMes[$mes] ?? [
                'mes' => $mes,
                'cantidad' => 0,
            ];
        }

        return $normalizados;
    }
}
