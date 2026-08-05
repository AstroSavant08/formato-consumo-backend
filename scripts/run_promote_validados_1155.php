<?php

/**
 * Promoción controlada de todos los staging en estado validado.
 * Requiere backup previo: consumo_pre_promocion_1155_2026-08-03.sql
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Entrega;
use App\Models\ExcelImportStaging;
use App\Services\ExcelImportService;

$chunkSize = 500;
$service = app(ExcelImportService::class);

$before = $service->getStagingSummary();
$beforeEntregas = Entrega::query()->where('fuente', 'excel_historico')->count();

$ids = ExcelImportStaging::query()
    ->where('estado', 'validado')
    ->orderBy('id')
    ->pluck('id')
    ->all();

$chunks = array_chunk($ids, $chunkSize);
$totals = [
    'backup' => 'C:\\backups\\consumo_pre_promocion_1155_2026-08-03.sql',
    'ids_solicitados' => count($ids),
    'chunks' => count($chunks),
    'promovidos' => 0,
    'omitidos' => 0,
    'errores' => 0,
    'errores_detalle' => [],
    'omitidos_detalle' => [],
];

foreach ($chunks as $index => $chunk) {
    $report = $service->promoteSelectedStaging($chunk, true);
    $totals['promovidos'] += count($report['promovidos']);
    $totals['omitidos'] += count($report['omitidos']);
    $totals['errores'] += count($report['errores']);

    if ($report['errores'] !== []) {
        $totals['errores_detalle'] = array_merge($totals['errores_detalle'], array_slice($report['errores'], 0, 10));
    }
    if ($report['omitidos'] !== []) {
        $totals['omitidos_detalle'] = array_merge($totals['omitidos_detalle'], array_slice($report['omitidos'], 0, 10));
    }

    $totals['chunks_done'][] = [
        'chunk' => $index + 1,
        'size' => count($chunk),
        'promovidos' => count($report['promovidos']),
        'omitidos' => count($report['omitidos']),
        'errores' => count($report['errores']),
    ];
}

$after = $service->getStagingSummary();
$afterEntregas = Entrega::query()->where('fuente', 'excel_historico')->count();

echo json_encode([
    'before' => [
        'staging' => $before,
        'entregas_historicas' => $beforeEntregas,
    ],
    'promotion' => $totals,
    'after' => [
        'staging' => $after,
        'entregas_historicas' => $afterEntregas,
        'entregas_nuevas' => $afterEntregas - $beforeEntregas,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
