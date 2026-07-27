<?php

namespace App\Services;

use App\Models\Alerta;
use App\Models\Producto;

class AlertaConsumoService
{
    public const TIPO_CONSUMO_VARIACION = 'consumo_variacion';

    public function __construct(
        private readonly SemaforoConsumoService $semaforoConsumoService,
    ) {}

    /**
     * Evalúa el semáforo y persiste una alerta si la severidad lo amerita.
     *
     * @return array{evaluacion: array<string, mixed>, alerta: Alerta|null, alerta_creada: bool}
     */
    public function evaluarYCrearAlerta(
        Producto $producto,
        int $mes,
        int $anio,
        ?int $areaId = null,
    ): array {
        $evaluacion = $this->semaforoConsumoService->evaluar($producto, $mes, $anio, $areaId);

        if (! $this->debeCrearAlerta($evaluacion)) {
            return [
                'evaluacion' => $evaluacion,
                'alerta' => null,
                'alerta_creada' => false,
            ];
        }

        $alerta = $this->crearOActualizarAlerta($evaluacion);
        $alertaCreada = $alerta->wasRecentlyCreated;

        return [
            'evaluacion' => $evaluacion,
            'alerta' => $alerta,
            'alerta_creada' => $alertaCreada,
        ];
    }

    /**
     * @param  array<string, mixed>  $evaluacion
     */
    private function debeCrearAlerta(array $evaluacion): bool
    {
        $config = $evaluacion['configuracion'] ?? null;

        if ($config !== null && ($config['activo'] ?? true) === false) {
            return false;
        }

        $severidad = $evaluacion['severidad'] ?? SemaforoConsumoService::SEVERIDAD_SIN_DATOS;

        return in_array($severidad, [
            SemaforoConsumoService::SEVERIDAD_AMARILLO,
            SemaforoConsumoService::SEVERIDAD_ROJO,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $evaluacion
     */
    private function crearOActualizarAlerta(array $evaluacion): Alerta
    {
        $metadata = [
            'mes' => $evaluacion['mes'],
            'anio' => $evaluacion['anio'],
            'consumo_actual' => $evaluacion['consumo_actual'],
            'promedio_historico' => $evaluacion['promedio_historico'],
            'variacion_porcentual' => $evaluacion['variacion_porcentual'],
            'anios_historico_considerados' => $evaluacion['anios_historico_considerados'],
            'totales_por_anio' => $evaluacion['totales_por_anio'],
        ];

        $query = Alerta::query()
            ->where('tipo', self::TIPO_CONSUMO_VARIACION)
            ->where('producto_id', $evaluacion['producto_id'])
            ->where('leida', false)
            ->where('metadata->mes', $evaluacion['mes'])
            ->where('metadata->anio', $evaluacion['anio']);

        if ($evaluacion['area_id'] === null) {
            $query->whereNull('area_id');
        } else {
            $query->where('area_id', $evaluacion['area_id']);
        }

        $alertaExistente = $query->first();

        if ($alertaExistente !== null) {
            $alertaExistente->update([
                'severidad' => $evaluacion['severidad'],
                'mensaje' => $evaluacion['mensaje'],
                'metadata' => $metadata,
            ]);

            return $alertaExistente->fresh();
        }

        return Alerta::query()->create([
            'tipo' => self::TIPO_CONSUMO_VARIACION,
            'severidad' => $evaluacion['severidad'],
            'producto_id' => $evaluacion['producto_id'],
            'area_id' => $evaluacion['area_id'],
            'mensaje' => $evaluacion['mensaje'],
            'metadata' => $metadata,
            'leida' => false,
        ]);
    }
}
