<?php

namespace App\Services;

use App\Models\Alerta;
use App\Models\ConfiguracionAlerta;
use App\Models\Inventario;

class AlertaInventarioService
{
    public const TIPO_STOCK_MINIMO = 'stock_minimo';

    public const CLAVE_CONFIG_STOCK_MINIMO = 'stock_minimo';

    /**
     * Evalúa el stock disponible frente al mínimo configurado.
     *
     * Política MVP de configuración: si no existe fila en configuracion_alertas
     * para stock_minimo, la alerta se considera habilitada por defecto.
     *
     * Política de deduplicación: no crea otra alerta no leída equivalente
     * para el mismo producto y tipo.
     *
     * Política de recuperación: cuando stock_disponible >= stock_minimo,
     * las alertas activas (no leídas) de stock mínimo se marcan como leídas.
     */
    public function verificarStockMinimo(Inventario $inventario): ?Alerta
    {
        if (! $this->alertaStockMinimoHabilitada()) {
            return null;
        }

        $stockDisponible = $inventario->stock_disponible;
        $stockMinimo = (float) $inventario->stock_minimo;

        if ($stockDisponible >= $stockMinimo) {
            $this->resolverAlertasStockMinimoActivas($inventario->producto_id);

            return null;
        }

        return $this->crearAlertaStockMinimoSiNoExiste($inventario);
    }

    private function alertaStockMinimoHabilitada(): bool
    {
        $configuracion = ConfiguracionAlerta::query()
            ->where('clave', self::CLAVE_CONFIG_STOCK_MINIMO)
            ->first();

        if ($configuracion === null) {
            return true;
        }

        return (bool) $configuracion->activo;
    }

    private function resolverAlertasStockMinimoActivas(int $productoId): void
    {
        Alerta::query()
            ->where('tipo', self::TIPO_STOCK_MINIMO)
            ->where('producto_id', $productoId)
            ->where('leida', false)
            ->update(['leida' => true]);
    }

    private function crearAlertaStockMinimoSiNoExiste(Inventario $inventario): ?Alerta
    {
        $alertaExistente = Alerta::query()
            ->where('tipo', self::TIPO_STOCK_MINIMO)
            ->where('producto_id', $inventario->producto_id)
            ->where('leida', false)
            ->exists();

        if ($alertaExistente) {
            return null;
        }

        $stockDisponible = $inventario->stock_disponible;
        $stockMinimo = (float) $inventario->stock_minimo;

        return Alerta::query()->create([
            'tipo' => self::TIPO_STOCK_MINIMO,
            'severidad' => 'amarillo',
            'producto_id' => $inventario->producto_id,
            'area_id' => null,
            'mensaje' => sprintf(
                'El stock disponible (%.2f) está por debajo del mínimo configurado (%.2f).',
                $stockDisponible,
                $stockMinimo,
            ),
            'metadata' => [
                'stock_fisico' => (float) $inventario->stock_fisico,
                'stock_reserva' => (float) $inventario->stock_reserva,
                'stock_comprometido' => (float) $inventario->stock_comprometido,
                'stock_disponible' => $stockDisponible,
                'stock_minimo' => $stockMinimo,
            ],
            'leida' => false,
        ]);
    }
}
