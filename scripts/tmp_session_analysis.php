<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ExcelImportHomologacion;
use App\Models\ExcelImportStaging;
use App\Services\ExcelImportService;

$sessionProducts = [
    'BOLSA NEGRA MEDIANA INDUSTRIAL' => 'Bolsa NEGRA - basura (65 * 80)',
    'BOLSA BLANCA MEDIANA INDUSTRIAL' => 'Bolsa blanca - (65*80)',
    'BOLSA VERDE MEDIANA INDUSTRIAL' => 'Bolsa verde (48 * 50) cocinetas',
    'AXION' => 'Jabon Lavaloza liquido',
    'DETERGENTE' => 'Jabon Fab en polvo',
    'RAID LATA' => 'Insecticida zancudos',
    'JABON DE MANO' => 'Jabon Liquido (lavamanos)',
    'JABON PARA MANOS' => 'Jabon Liquido (lavamanos)',
    'AROMATICA' => 'Aromaticas',
];

$summary = app(ExcelImportService::class)->getStagingSummary();

$byProductoRequiere = ExcelImportStaging::query()
    ->where('estado', 'requiere_revision')
    ->selectRaw('producto_raw, count(*) as c')
    ->groupBy('producto_raw')
    ->orderByDesc('c')
    ->get()
    ->map(fn ($r) => ['producto_raw' => $r->producto_raw, 'count' => (int) $r->c])
    ->values()
    ->all();

$sessionCheck = [];
foreach ($sessionProducts as $excelName => $expectedCatalog) {
    $rows = ExcelImportStaging::query()
        ->where('producto_raw', $excelName)
        ->get(['id', 'estado', 'producto_raw']);

    $withHomolog = ExcelImportHomologacion::query()
        ->whereIn('staging_id', $rows->pluck('id'))
        ->with('productoDestino')
        ->get();

    $validados = $rows->where('estado', 'validado')->count();
    $requiere = $rows->where('estado', 'requiere_revision')->count();
    $wrongDestino = $withHomolog->filter(
        fn ($h) => ($h->productoDestino?->nombre ?? '') !== $expectedCatalog
    )->count();

    $sessionCheck[] = [
        'excel' => $excelName,
        'total' => $rows->count(),
        'validados' => $validados,
        'requiere_revision' => $requiere,
        'con_homologacion' => $withHomolog->count(),
        'destino_esperado' => $expectedCatalog,
        'destino_incorrecto' => $wrongDestino,
        'ok' => $requiere === 0 && $wrongDestino === 0 && $validados === $rows->count() && $rows->count() > 0,
    ];
}

$erroresData = ExcelImportStaging::query()
    ->where('estado', 'requiere_revision')
    ->whereNotIn('producto_raw', array_keys($sessionProducts))
    ->where('producto_raw', 'not like', 'PAPEL HIGIENICO%')
    ->selectRaw('producto_raw, count(*) as c')
    ->groupBy('producto_raw')
    ->orderByDesc('c')
    ->get()
    ->map(fn ($r) => ['producto_raw' => $r->producto_raw, 'count' => (int) $r->c])
    ->values()
    ->all();

$papel = ExcelImportStaging::query()
    ->where('producto_raw', 'like', 'PAPEL HIGIENICO%')
    ->selectRaw('producto_raw, estado, count(*) as c')
    ->groupBy('producto_raw', 'estado')
    ->orderBy('producto_raw')
    ->get()
    ->map(fn ($r) => [
        'producto_raw' => $r->producto_raw,
        'estado' => $r->estado,
        'count' => (int) $r->c,
    ])
    ->values()
    ->all();

echo json_encode([
    'summary' => $summary,
    'session_products_check' => $sessionCheck,
    'session_all_ok' => collect($sessionCheck)->every(fn ($r) => $r['ok']),
    'pendientes_por_producto_top' => $byProductoRequiere,
    'pendientes_fuera_papel' => $erroresData,
    'papel_higienico_breakdown' => $papel,
    'homologaciones_totales' => ExcelImportHomologacion::count(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
