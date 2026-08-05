<?php

/** Read-only BD snapshot for Excel comparison — delete after use. */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Area;
use App\Models\Producto;
use App\Models\ProductoAlias;

$productos = Producto::query()
    ->orderBy('id')
    ->get(['id', 'nombre', 'stock_minimo_referencia', 'es_historico_excel', 'activo', 'es_desarrollo']);

$areas = Area::query()->orderBy('id')->get(['id', 'nombre', 'activo']);
$aliases = ProductoAlias::query()->get(['producto_id', 'alias']);

echo json_encode([
    'productos_operativos' => $productos->where('es_historico_excel', false)->where('activo', true)->values(),
    'productos_historicos' => $productos->where('es_historico_excel', true)->values(),
    'productos_all' => $productos,
    'areas' => $areas,
    'aliases' => $aliases,
    'counts' => [
        'productos_total' => $productos->count(),
        'productos_operativos' => $productos->where('es_historico_excel', false)->where('activo', true)->count(),
        'productos_historicos' => $productos->where('es_historico_excel', true)->count(),
        'areas' => $areas->count(),
        'aliases' => $aliases->count(),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
