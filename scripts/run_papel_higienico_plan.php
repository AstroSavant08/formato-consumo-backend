<?php

/**
 * Homologación controlada PAPEL HIGIENICO + AROMATICA fila 356.
 * NO promueve/importa a entregas.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ExcelImportStaging;
use App\Models\Producto;
use App\Services\ExcelImportService;
use App\Services\StagingHomologacionService;
use Illuminate\Support\Facades\DB;

const DISPENSADORES_ID = 36;
const PLANTA_OFICINAS_ID = 37;
const ACTOR = 'Jhoan/Plan seguro PAPEL HIGIENICO';

$areaMap = [
    'ADMINISTRATIVO' => DISPENSADORES_ID,
    'LABORATORIO' => DISPENSADORES_ID,
    'PORTERIA' => DISPENSADORES_ID,
    'ALMACEN' => PLANTA_OFICINAS_ID,
    'PRODUCCION' => PLANTA_OFICINAS_ID,
    'MANTENIMIENTO' => PLANTA_OFICINAS_ID,
];

$homologacionService = app(StagingHomologacionService::class);
$importService = app(ExcelImportService::class);

$report = [
    'backup_note' => 'C:\\backups\\consumo_pre_papel_higienico_2026-08-03.sql',
    'homologacion' => [],
    'aromatica' => null,
    'validacion' => null,
    'summary_after' => null,
];

DB::transaction(function () use ($areaMap, $homologacionService, $importService, &$report) {
    foreach ($areaMap as $area => $productoId) {
        $ids = ExcelImportStaging::query()
            ->where('estado', 'requiere_revision')
            ->whereRaw('UPPER(TRIM(producto_raw)) = ?', ['PAPEL HIGIENICO'])
            ->whereRaw('UPPER(TRIM(area_raw)) = ?', [$area])
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            $report['homologacion'][$area] = ['ids' => 0, 'skipped' => true];
            continue;
        }

        $producto = Producto::query()->findOrFail($productoId);
        $bulk = $homologacionService->homologarBulk(
            $ids,
            $producto->id,
            "Homologación Marcela+plan seguro — {$area} → {$producto->nombre}",
            ACTOR,
            true,
        );

        $report['homologacion'][$area] = [
            'producto_id' => $productoId,
            'producto' => $producto->nombre,
            'ids_count' => count($ids),
            'bulk' => $bulk,
        ];
    }

    $aromatica = ExcelImportStaging::query()->where('fila_excel', 356)->first();
    if ($aromatica && $aromatica->estado === 'requiere_revision') {
        $aromatica->cantidad_raw = '10';
        $aromatica->unidad_raw = 'UND';
        $aromatica->save();

        $destino = Producto::query()
            ->where('activo', true)
            ->where('es_historico_excel', false)
            ->where('nombre', 'Aromaticas')
            ->first();

        if (! $destino) {
            $report['aromatica'] = [
                'staging_id' => $aromatica->id,
                'cantidad_fixed' => '10',
                'unidad_fixed' => 'UND',
                'homologado' => false,
                'motivo' => 'No se encontró producto aromática/ambiental operativo en catálogo',
            ];
        } else {
            $homologacionService->homologar(
                $aromatica->id,
                $destino->id,
                'Marcela: administración suele pedir 10 UND',
                ACTOR,
                true,
            );
            $report['aromatica'] = [
                'staging_id' => $aromatica->id,
                'producto_id' => $destino->id,
                'producto' => $destino->nombre,
                'cantidad_fixed' => '10',
                'unidad_fixed' => 'UND',
                'homologado' => true,
            ];
        }
    }

    $toValidate = ExcelImportStaging::query()
        ->where('estado', 'requiere_revision')
        ->where(function ($q) {
            $q->whereRaw('UPPER(TRIM(producto_raw)) = ?', ['PAPEL HIGIENICO'])
                ->orWhere('fila_excel', 356);
        })
        ->whereHas('homologacion')
        ->pluck('id')
        ->all();

    $report['validacion'] = $importService->validateSelectedStaging($toValidate);
});

$report['summary_after'] = $importService->getStagingSummary();

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
