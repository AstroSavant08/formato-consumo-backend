<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ExcelImportStaging;
use App\Models\Producto;

$rows = ExcelImportStaging::query()
    ->where('estado', 'requiere_revision')
    ->get(['id', 'producto_raw', 'area_raw', 'errores_json', 'producto_id']);

$byProduct = [];
$errorCounts = [];

foreach ($rows as $row) {
    $key = trim((string) $row->producto_raw) ?: '(vacío)';
    $byProduct[$key] = ($byProduct[$key] ?? 0) + 1;

    foreach ((array) ($row->errores_json ?? []) as $err) {
        $errorCounts[$err] = ($errorCounts[$err] ?? 0) + 1;
    }
}

arsort($byProduct);
arsort($errorCounts);

$topProducts = array_slice($byProduct, 0, 20, true);

$suggestions = [];
foreach (array_keys($topProducts) as $raw) {
    if ($raw === '(vacío)') {
        continue;
    }
    $norm = App\Support\TextNormalizer::normalize($raw);
    $match = Producto::query()->where('nombre_normalizado', $norm)->first();
    $suggestions[$raw] = $match?->nombre;
}

echo json_encode([
    'total_requiere_revision' => $rows->count(),
    'top_errores' => $errorCounts,
    'top_productos_raw' => $topProducts,
    'match_exacto_catalogo' => array_filter($suggestions),
    'sin_match_top20' => array_keys(array_filter($suggestions, fn ($v) => $v === null)),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
