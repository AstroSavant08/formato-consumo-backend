<?php

namespace App\Services;

use App\Models\ConfiguracionAlerta;
use App\Models\Producto;
use App\Support\UnidadConversionService;

class PromedioHistoricoService
{
    public const CLAVE_CONFIG_VARIACION = 'consumo_variacion_porcentual';

    public const FUENTES_CONSUMO = ['excel_historico', 'sistema'];

    public function __construct(
        private readonly UnidadConversionService $unidadConversionService,
    ) {}

    /**
     * Suma el consumo de un producto en un mes calendario (unidad base del producto).
     *
     * @return array{total: float, entregas_consideradas: int, entregas_omitidas: int}
     */
    public function sumarConsumoMes(
        Producto $producto,
        int $mes,
        int $anio,
        ?int $areaId = null,
    ): array {
        $query = $producto->entregas()
            ->whereIn('fuente', self::FUENTES_CONSUMO)
            ->whereYear('fecha', $anio)
            ->whereMonth('fecha', $mes);

        if ($areaId !== null) {
            $query->where('area_id', $areaId);
        }

        $total = 0.0;
        $consideradas = 0;
        $omitidas = 0;

        foreach ($query->get(['cantidad', 'unidad']) as $entrega) {
            try {
                $total += $this->unidadConversionService->resolverCantidadEnUnidadBase(
                    $producto,
                    (float) $entrega->cantidad,
                    $entrega->unidad,
                );
                $consideradas++;
            } catch (\Throwable) {
                $omitidas++;
            }
        }

        return [
            'total' => round($total, 2),
            'entregas_consideradas' => $consideradas,
            'entregas_omitidas' => $omitidas,
        ];
    }

    /**
     * Promedio del consumo del mismo mes en años anteriores al año evaluado.
     *
     * @return array{
     *     promedio: float|null,
     *     anios_considerados: int[],
     *     totales_por_anio: array<int, float>,
     *     entregas_omitidas: int
     * }
     */
    public function calcularPromedioHistoricoMensual(
        Producto $producto,
        int $mes,
        int $anioEvaluado,
        ?int $areaId = null,
    ): array {
        $yearsQuery = $producto->entregas()
            ->whereIn('fuente', self::FUENTES_CONSUMO)
            ->whereMonth('fecha', $mes)
            ->whereYear('fecha', '<', $anioEvaluado);

        if ($areaId !== null) {
            $yearsQuery->where('area_id', $areaId);
        }

        $anios = $yearsQuery
            ->pluck('fecha')
            ->map(fn ($fecha) => (int) $fecha->format('Y'))
            ->unique()
            ->sort()
            ->values()
            ->all();
        $totalesPorAnio = [];
        $omitidas = 0;

        foreach ($anios as $anio) {
            $resultado = $this->sumarConsumoMes($producto, $mes, $anio, $areaId);
            $totalesPorAnio[$anio] = $resultado['total'];
            $omitidas += $resultado['entregas_omitidas'];
        }

        if ($totalesPorAnio === []) {
            return [
                'promedio' => null,
                'anios_considerados' => [],
                'totales_por_anio' => [],
                'entregas_omitidas' => $omitidas,
            ];
        }

        $promedio = round(array_sum($totalesPorAnio) / count($totalesPorAnio), 2);

        return [
            'promedio' => $promedio,
            'anios_considerados' => $anios,
            'totales_por_anio' => $totalesPorAnio,
            'entregas_omitidas' => $omitidas,
        ];
    }

    /**
     * Variación porcentual absoluta respecto al promedio histórico.
     */
    public function calcularVariacionPorcentual(float $consumoActual, ?float $promedioHistorico): ?float
    {
        if ($promedioHistorico === null) {
            return null;
        }

        if ($promedioHistorico == 0.0) {
            return $consumoActual == 0.0 ? 0.0 : 100.0;
        }

        return round(abs(($consumoActual - $promedioHistorico) / $promedioHistorico) * 100, 2);
    }

    public function obtenerConfiguracionVariacion(): ?ConfiguracionAlerta
    {
        return ConfiguracionAlerta::query()
            ->where('clave', self::CLAVE_CONFIG_VARIACION)
            ->first();
    }
}
