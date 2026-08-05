<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ExcelImportStaging;

$out = [];
foreach (['BOLSA NEGRA MEDIANA INDUSTRIAL', 'JABON DE MANO', 'AROMATICA'] as $producto) {
    $rows = ExcelImportStaging::query()
        ->where('producto_raw', $producto)
        ->with('homologacion.productoDestino')
        ->get();

    $byEstado = $rows->groupBy('estado')->map->count();
    $notValid = $rows->where('estado', '!=', 'validado')->map(fn ($row) => [
        'fila_excel' => $row->fila_excel,
        'estado' => $row->estado,
        'destino' => $row->homologacion?->productoDestino?->nombre,
        'errores_json' => $row->errores_json,
    ])->values();

    $out[$producto] = [
        'by_estado' => $byEstado,
        'not_validado' => $notValid,
    ];
}

$aromaticaPending = ExcelImportStaging::query()
    ->where('producto_raw', 'AROMATICA')
    ->where('estado', 'requiere_revision')
    ->with('homologacion.productoDestino')
    ->first();

$out['aromatica_pending'] = $aromaticaPending ? [
    'fila_excel' => $aromaticaPending->fila_excel,
    'estado' => $aromaticaPending->estado,
    'destino' => $aromaticaPending->homologacion?->productoDestino?->nombre,
    'errores_json' => $aromaticaPending->errores_json,
] : null;

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
