<?php

/**
 * Corrección PAPEL HIGIÉNICO — Opción A (Marcela + criterio Excel):
 * - PAPEL HIGIENICO PEQUEÑO (todas las áreas) → dispensadores (36)
 * - PAPEL HIGIENICO en ADMINISTRATIVO → rollo planta/oficinas (37)
 * - Demás áreas ya correctas (Prod/Mant/Almacén=rollo; Lab/Portería=dispensadores)
 *
 * Backup previo: consumo_pre_papel_opcion_a_2026-08-05_1430.sql
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Entrega;
use App\Models\ExcelImportHomologacion;
use App\Models\ExcelImportStaging;
use App\Models\Producto;
use Illuminate\Support\Facades\DB;

const DISPENSADORES_ID = 36;
const ROLLO_ID = 37;
const ACTOR = 'Corrección Opción A PAPEL HIGIÉNICO (post-reunión Marcela)';

$dispensadores = Producto::query()->findOrFail(DISPENSADORES_ID);
$rollo = Producto::query()->findOrFail(ROLLO_ID);

$report = [
    'opcion' => 'A',
    'criterio' => [
        'pequeno_todas_areas' => 'dispensadores',
        'papel_administrativo' => 'rollo',
    ],
    'backup' => 'C:\\backups\\consumo_pre_papel_opcion_a_2026-08-05_1430.sql',
    'updates' => [],
];

DB::transaction(function () use ($dispensadores, $rollo, &$report) {
    $pequenoIds = ExcelImportStaging::query()
        ->whereRaw('UPPER(TRIM(producto_raw)) LIKE ?', ['PAPEL HIGIENICO PEQUE%'])
        ->pluck('id')
        ->all();

    $adminPapelIds = ExcelImportStaging::query()
        ->whereRaw('UPPER(TRIM(producto_raw)) = ?', ['PAPEL HIGIENICO'])
        ->whereRaw('UPPER(TRIM(area_raw)) = ?', ['ADMINISTRATIVO'])
        ->pluck('id')
        ->all();

    $apply = function (array $stagingIds, int $productoId, string $notas) use (&$report) {
        if ($stagingIds === []) {
            return;
        }

        $entregasUpdated = Entrega::query()
            ->whereIn('staging_id', $stagingIds)
            ->where('producto_id', '!=', $productoId)
            ->update(['producto_id' => $productoId]);

        $stagingUpdated = ExcelImportStaging::query()
            ->whereIn('id', $stagingIds)
            ->where('producto_id', '!=', $productoId)
            ->update(['producto_id' => $productoId]);

        $homologUpdated = 0;
        ExcelImportHomologacion::query()
            ->whereIn('staging_id', $stagingIds)
            ->each(function (ExcelImportHomologacion $homolog) use ($productoId, $notas, &$homologUpdated) {
                if ((int) $homolog->producto_id_destino === $productoId) {
                    return;
                }
                $prev = trim((string) $homolog->notas);
                $homolog->update([
                    'producto_id_destino' => $productoId,
                    'confirmado_por' => ACTOR,
                    'notas' => $prev === '' ? $notas : "{$prev} | {$notas}",
                ]);
                $homologUpdated++;
            });

        $report['updates'][] = [
            'staging_ids' => count($stagingIds),
            'producto_id' => $productoId,
            'entregas_updated' => $entregasUpdated,
            'staging_updated' => $stagingUpdated,
            'homologaciones_updated' => $homologUpdated,
            'notas' => $notas,
        ];
    };

    $apply(
        $pequenoIds,
        DISPENSADORES_ID,
        'Opción A: PEQUEÑO → dispensadores (tal cual)'
    );

    $apply(
        $adminPapelIds,
        ROLLO_ID,
        'Opción A: PAPEL HIGIENICO admin → rollo (gerencias/baños privados)'
    );
});

$report['after'] = [
    'entregas_por_producto' => Entrega::query()
        ->where('fuente', 'excel_historico')
        ->whereIn('staging_id', ExcelImportStaging::query()
            ->whereRaw('UPPER(TRIM(producto_raw)) LIKE ?', ['PAPEL HIGIENICO%'])
            ->select('id'))
        ->join('productos', 'productos.id', '=', 'entregas.producto_id')
        ->selectRaw('productos.nombre, COUNT(*) as n')
        ->groupBy('productos.nombre')
        ->pluck('n', 'nombre')
        ->all(),
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
