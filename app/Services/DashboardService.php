<?php

namespace App\Services;

use App\Models\Alerta;
use App\Models\Area;
use App\Models\Entrega;
use App\Models\ExcelImportStaging;
use App\Models\Inventario;
use App\Models\Solicitud;
use Carbon\Carbon;

class DashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function obtenerResumen(int $anio, int $mes): array
    {
        $inicioMes = Carbon::create($anio, $mes, 1)->startOfDay();
        $finMes = $inicioMes->copy()->endOfMonth();

        return [
            'periodo' => [
                'anio' => $anio,
                'mes' => $mes,
                'mes_nombre' => $this->nombreMes($mes),
                'fecha_desde' => $inicioMes->toDateString(),
                'fecha_hasta' => $finMes->toDateString(),
            ],
            'alertas' => $this->resumenAlertas(),
            'solicitudes' => $this->resumenSolicitudes(),
            'inventario' => $this->resumenInventario(),
            'entregas' => $this->resumenEntregas($inicioMes, $finMes),
            'homologacion' => $this->resumenHomologacion(),
            'generado_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resumenAlertas(): array
    {
        $activas = Alerta::query()->where('leida', false);

        $porSeveridad = (clone $activas)
            ->selectRaw('severidad, count(*) as total')
            ->groupBy('severidad')
            ->pluck('total', 'severidad')
            ->map(fn ($value) => (int) $value)
            ->all();

        $porTipo = (clone $activas)
            ->selectRaw('tipo, count(*) as total')
            ->groupBy('tipo')
            ->pluck('total', 'tipo')
            ->map(fn ($value) => (int) $value)
            ->all();

        $recientes = Alerta::query()
            ->with('producto:id,nombre')
            ->where('leida', false)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn (Alerta $alerta) => [
                'id' => $alerta->id,
                'tipo' => $alerta->tipo,
                'severidad' => $alerta->severidad,
                'mensaje' => $alerta->mensaje,
                'producto_id' => $alerta->producto_id,
                'producto_nombre' => $alerta->producto?->nombre,
                'created_at' => $alerta->created_at?->toIso8601String(),
            ])
            ->all();

        return [
            'activas_total' => array_sum($porSeveridad),
            'por_severidad' => [
                'verde' => $porSeveridad['verde'] ?? 0,
                'amarillo' => $porSeveridad['amarillo'] ?? 0,
                'rojo' => $porSeveridad['rojo'] ?? 0,
            ],
            'por_tipo' => [
                'stock_minimo' => $porTipo['stock_minimo'] ?? 0,
                'consumo_variacion' => $porTipo['consumo_variacion'] ?? 0,
            ],
            'recientes' => $recientes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resumenSolicitudes(): array
    {
        $porEstado = Solicitud::query()
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->map(fn ($value) => (int) $value)
            ->all();

        $pendientesAccion = (int) Solicitud::query()
            ->whereIn('estado', [Solicitud::ESTADO_PENDIENTE, Solicitud::ESTADO_EN_REVISION])
            ->count();

        $recientes = Solicitud::query()
            ->with('area:id,nombre')
            ->orderByDesc('created_at')
            ->limit(6)
            ->get()
            ->map(fn (Solicitud $solicitud) => [
                'id' => $solicitud->id,
                'numero' => $solicitud->numero,
                'estado' => $solicitud->estado,
                'area_id' => $solicitud->area_id,
                'area_nombre' => $solicitud->area?->nombre,
                'total' => (float) $solicitud->total,
                'fecha' => $solicitud->fecha?->toDateString(),
            ])
            ->all();

        return [
            'total' => array_sum($porEstado),
            'pendientes_accion' => $pendientesAccion,
            'por_estado' => [
                'pendiente' => $porEstado[Solicitud::ESTADO_PENDIENTE] ?? 0,
                'en_revision' => $porEstado[Solicitud::ESTADO_EN_REVISION] ?? 0,
                'aprobada' => $porEstado[Solicitud::ESTADO_APROBADA] ?? 0,
                'rechazada' => $porEstado[Solicitud::ESTADO_RECHAZADA] ?? 0,
                'entregada' => $porEstado[Solicitud::ESTADO_ENTREGADA] ?? 0,
                'cancelada' => $porEstado[Solicitud::ESTADO_CANCELADA] ?? 0,
            ],
            'recientes' => $recientes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resumenInventario(): array
    {
        $inventarios = Inventario::query()->with('producto:id,nombre')->get();

        $bajoMinimo = $inventarios->filter(
            fn (Inventario $item) => $item->stock_disponible < (float) $item->stock_minimo
        );

        return [
            'total_configurados' => $inventarios->count(),
            'bajo_minimo_total' => $bajoMinimo->count(),
            'bajo_minimo' => $bajoMinimo->take(8)->map(fn (Inventario $item) => [
                'producto_id' => $item->producto_id,
                'producto_nombre' => $item->producto?->nombre,
                'stock_disponible' => $item->stock_disponible,
                'stock_minimo' => (float) $item->stock_minimo,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resumenEntregas(Carbon $inicioMes, Carbon $finMes): array
    {
        $baseQuery = Entrega::query()
            ->whereBetween('fecha', [$inicioMes->toDateString(), $finMes->toDateString()]);

        $total = (int) (clone $baseQuery)->count();

        $porFuente = (clone $baseQuery)
            ->selectRaw('fuente, count(*) as total')
            ->groupBy('fuente')
            ->pluck('total', 'fuente')
            ->map(fn ($value) => (int) $value)
            ->all();

        $porAreaRows = (clone $baseQuery)
            ->selectRaw('area_id, count(*) as total')
            ->groupBy('area_id')
            ->orderByDesc('total')
            ->limit(8)
            ->get();

        $areas = Area::query()
            ->whereIn('id', $porAreaRows->pluck('area_id')->filter())
            ->pluck('nombre', 'id');

        $porArea = $porAreaRows->map(fn ($row) => [
            'area_id' => $row->area_id,
            'area_nombre' => $areas[$row->area_id] ?? 'Sin área',
            'total' => (int) $row->total,
        ])->all();

        return [
            'mes_total' => $total,
            'por_fuente' => [
                'sistema' => $porFuente['sistema'] ?? 0,
                'excel_historico' => $porFuente['excel_historico'] ?? 0,
            ],
            'por_area' => $porArea,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resumenHomologacion(): array
    {
        $conteos = ExcelImportStaging::query()
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->map(fn ($value) => (int) $value)
            ->all();

        return [
            'staging_total' => array_sum($conteos),
            'requiere_revision' => (int) ($conteos['requiere_revision'] ?? 0),
            'por_estado' => $conteos,
        ];
    }

    private function nombreMes(int $mes): string
    {
        $meses = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];

        return $meses[$mes] ?? (string) $mes;
    }
}
