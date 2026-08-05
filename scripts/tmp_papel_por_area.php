<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ExcelImportStaging;

$papel = ExcelImportStaging::query()
    ->where('producto_raw', 'PAPEL HIGIENICO')
    ->where('estado', 'requiere_revision')
    ->selectRaw('area_raw, count(*) as c')
    ->groupBy('area_raw')
    ->orderByDesc('c')
    ->get()
    ->map(fn ($r) => ['area' => $r->area_raw ?: '(vacía)', 'count' => (int) $r->c])
    ->values()
    ->all();

$aromatica = ExcelImportStaging::query()
    ->where('producto_raw', 'AROMATICA')
    ->where('estado', 'requiere_revision')
    ->first(['fila_excel', 'area_raw', 'cantidad_raw', 'unidad_raw', 'fecha_raw', 'errores_json']);

echo json_encode([
    'papel_higienico_pendiente_por_area' => $papel,
    'papel_total_pendiente' => array_sum(array_column($papel, 'count')),
    'aromatica_pendiente' => $aromatica ? [
        'fila_excel' => $aromatica->fila_excel,
        'area' => $aromatica->area_raw,
        'cantidad' => $aromatica->cantidad_raw,
        'unidad' => $aromatica->unidad_raw,
        'fecha' => $aromatica->fecha_raw,
        'errores' => $aromatica->errores_json,
    ] : null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
