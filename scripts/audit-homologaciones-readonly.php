<?php

/**
 * Auditoría solo lectura de excel_import_homologaciones.
 * No modifica datos.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ExcelImportHomologacion;
use App\Models\ExcelImportStaging;
use App\Services\ExcelImportService;

$homologaciones = ExcelImportHomologacion::query()
    ->with(['staging', 'productoDestino'])
    ->orderBy('id')
    ->get();

$report = [
    'total_homologaciones' => $homologaciones->count(),
    'con_homologacion_staging_count' => ExcelImportStaging::query()->whereHas('homologacion')->count(),
    'summary' => app(ExcelImportService::class)->getStagingSummary(),
    'homologaciones' => $homologaciones->map(fn ($h) => [
        'id' => $h->id,
        'staging_id' => $h->staging_id,
        'fila_excel' => $h->staging?->fila_excel,
        'producto_raw' => $h->staging?->producto_raw,
        'area_raw' => $h->staging?->area_raw,
        'estado_staging' => $h->staging?->estado,
        'producto_id_destino' => $h->producto_id_destino,
        'producto_destino' => $h->productoDestino?->nombre,
        'confirmado_por' => $h->confirmado_por,
        'fecha_confirmacion' => $h->fecha_confirmacion?->toDateTimeString(),
        'notas' => $h->notas,
        'created_at' => $h->created_at?->toDateTimeString(),
        'updated_at' => $h->updated_at?->toDateTimeString(),
    ])->values()->all(),
    'area_filter_man_sample' => ExcelImportStaging::query()
        ->where(function ($builder) {
            $term = 'MAN';
            $builder->where('area_raw', 'like', '%'.$term.'%')
                ->orWhereHas('area', function ($areaQuery) use ($term) {
                    $areaQuery->where('nombre', 'like', '%'.$term.'%')
                        ->orWhere('codigo', 'like', '%'.$term.'%');
                });
        })
        ->with('area')
        ->orderBy('fila_excel')
        ->limit(20)
        ->get()
        ->map(fn ($r) => [
            'fila_excel' => $r->fila_excel,
            'producto_raw' => $r->producto_raw,
            'area_raw' => $r->area_raw,
            'area_resuelta_nombre' => $r->area?->nombre,
            'area_resuelta_codigo' => $r->area?->codigo,
            'match_reason_guess' => str_contains(mb_strtoupper($r->area_raw ?? ''), 'MAN') ? 'area_raw' : 'area_resuelta',
        ])
        ->values()
        ->all(),
        'area_filter_man_breakdown' => ExcelImportStaging::query()
        ->where(function ($builder) {
            $term = 'MAN';
            $builder->where('area_raw', 'like', '%'.$term.'%')
                ->orWhereHas('area', function ($areaQuery) use ($term) {
                    $areaQuery->where('nombre', 'like', '%'.$term.'%')
                        ->orWhere('codigo', 'like', '%'.$term.'%');
                });
        })
        ->selectRaw('area_raw, COUNT(*) as total')
        ->groupBy('area_raw')
        ->orderByDesc('total')
        ->get()
        ->map(fn ($r) => ['area_raw' => $r->area_raw, 'total' => (int) $r->total])
        ->values()
        ->all(),
    'areas_catalog_with_man' => \App\Models\Area::query()
        ->where('nombre', 'like', '%MAN%')
        ->orWhere('codigo', 'like', '%MAN%')
        ->get(['id', 'codigo', 'nombre'])
        ->map(fn ($a) => ['id' => $a->id, 'codigo' => $a->codigo, 'nombre' => $a->nombre])
        ->values()
        ->all(),
    'fecha_serial_samples' => ExcelImportStaging::query()
        ->where('fecha_raw', 'regexp', '^[0-9]+$')
        ->orderBy('fila_excel')
        ->limit(5)
        ->get(['fila_excel', 'producto_raw', 'fecha_raw'])
        ->map(fn ($r) => [
            'fila_excel' => $r->fila_excel,
            'producto_raw' => $r->producto_raw,
            'fecha_raw' => $r->fecha_raw,
        ])
        ->values()
        ->all(),
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
