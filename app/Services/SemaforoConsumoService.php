<?php

namespace App\Services;

use App\Models\Producto;

class SemaforoConsumoService
{
    public const SEVERIDAD_VERDE = 'verde';

    public const SEVERIDAD_AMARILLO = 'amarillo';

    public const SEVERIDAD_ROJO = 'rojo';

    public const SEVERIDAD_SIN_DATOS = 'sin_datos';

    public function __construct(
        private readonly PromedioHistoricoService $promedioHistoricoService,
    ) {}

    /**
     * @return array{
     *     producto_id: int,
     *     mes: int,
     *     anio: int,
     *     area_id: int|null,
     *     consumo_actual: float,
     *     promedio_historico: float|null,
     *     variacion_porcentual: float|null,
     *     severidad: string,
     *     unidad_base: string|null,
     *     anios_historico_considerados: int[],
     *     totales_por_anio: array<int, float>,
     *     entregas_omitidas: int,
     *     mensaje: string,
     *     configuracion: array<string, mixed>|null
     * }
     */
    public function evaluar(
        Producto $producto,
        int $mes,
        int $anio,
        ?int $areaId = null,
    ): array {
        $consumoActual = $this->promedioHistoricoService->sumarConsumoMes($producto, $mes, $anio, $areaId);
        $historico = $this->promedioHistoricoService->calcularPromedioHistoricoMensual(
            $producto,
            $mes,
            $anio,
            $areaId,
        );

        $config = $this->promedioHistoricoService->obtenerConfiguracionVariacion();
        $variacion = $this->promedioHistoricoService->calcularVariacionPorcentual(
            $consumoActual['total'],
            $historico['promedio'],
        );

        $severidad = $this->resolverSeveridad($variacion, $historico['promedio'], $consumoActual['total']);
        $mensaje = $this->construirMensaje(
            $producto,
            $mes,
            $anio,
            $consumoActual['total'],
            $historico['promedio'],
            $variacion,
            $severidad,
        );

        return [
            'producto_id' => $producto->id,
            'mes' => $mes,
            'anio' => $anio,
            'area_id' => $areaId,
            'consumo_actual' => $consumoActual['total'],
            'promedio_historico' => $historico['promedio'],
            'variacion_porcentual' => $variacion,
            'severidad' => $severidad,
            'unidad_base' => $producto->unidad_default,
            'anios_historico_considerados' => $historico['anios_considerados'],
            'totales_por_anio' => $historico['totales_por_anio'],
            'entregas_omitidas' => $consumoActual['entregas_omitidas'] + $historico['entregas_omitidas'],
            'mensaje' => $mensaje,
            'configuracion' => $config ? [
                'clave' => $config->clave,
                'umbral_verde' => (float) $config->umbral_verde,
                'umbral_amarillo' => (float) $config->umbral_amarillo,
                'umbral_rojo' => (float) $config->umbral_rojo,
                'activo' => (bool) $config->activo,
            ] : null,
        ];
    }

    private function resolverSeveridad(?float $variacion, ?float $promedio, float $consumoActual): string
    {
        if ($promedio === null) {
            return self::SEVERIDAD_SIN_DATOS;
        }

        if ($variacion === null) {
            return self::SEVERIDAD_SIN_DATOS;
        }

        $config = $this->promedioHistoricoService->obtenerConfiguracionVariacion();

        $umbralVerde = $config ? (float) $config->umbral_verde : 15.0;
        $umbralAmarillo = $config ? (float) $config->umbral_amarillo : 40.0;

        if ($variacion <= $umbralVerde) {
            return self::SEVERIDAD_VERDE;
        }

        if ($variacion <= $umbralAmarillo) {
            return self::SEVERIDAD_AMARILLO;
        }

        if ($consumoActual > $promedio) {
            return self::SEVERIDAD_ROJO;
        }

        return self::SEVERIDAD_AMARILLO;
    }

    private function construirMensaje(
        Producto $producto,
        int $mes,
        int $anio,
        float $consumoActual,
        ?float $promedio,
        ?float $variacion,
        string $severidad,
    ): string {
        $nombreMes = $this->nombreMes($mes);

        if ($promedio === null || $variacion === null) {
            return sprintf(
                'No hay histórico suficiente para evaluar el consumo de %s en %s %d.',
                $producto->nombre,
                $nombreMes,
                $anio,
            );
        }

        $direccion = $consumoActual >= $promedio ? 'por encima' : 'por debajo';

        return match ($severidad) {
            self::SEVERIDAD_VERDE => sprintf(
                'Consumo de %s en %s %d dentro del rango normal (%.2f vs promedio %.2f, variación %.1f%%).',
                $producto->nombre,
                $nombreMes,
                $anio,
                $consumoActual,
                $promedio,
                $variacion,
            ),
            self::SEVERIDAD_AMARILLO => sprintf(
                'Consumo de %s en %s %d %s del promedio histórico (%.2f vs %.2f, variación %.1f%%).',
                $producto->nombre,
                $nombreMes,
                $anio,
                $direccion,
                $consumoActual,
                $promedio,
                $variacion,
            ),
            self::SEVERIDAD_ROJO => sprintf(
                'Consumo excesivo de %s en %s %d respecto al promedio histórico (%.2f vs %.2f, variación %.1f%%).',
                $producto->nombre,
                $nombreMes,
                $anio,
                $consumoActual,
                $promedio,
                $variacion,
            ),
            default => sprintf(
                'Evaluación de consumo de %s en %s %d.',
                $producto->nombre,
                $nombreMes,
                $anio,
            ),
        };
    }

    private function nombreMes(int $mes): string
    {
        $meses = [
            1 => 'enero',
            2 => 'febrero',
            3 => 'marzo',
            4 => 'abril',
            5 => 'mayo',
            6 => 'junio',
            7 => 'julio',
            8 => 'agosto',
            9 => 'septiembre',
            10 => 'octubre',
            11 => 'noviembre',
            12 => 'diciembre',
        ];

        return $meses[$mes] ?? (string) $mes;
    }
}
