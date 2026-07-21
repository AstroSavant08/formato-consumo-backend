<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Entrega;
use App\Models\Producto;
use App\Models\ProductoAlias;

$results = [];

$entrega = Entrega::with('producto')->where('fuente', 'excel_historico')->first();
$results['entrega_producto'] = [
    'sample_id' => $entrega?->id,
    'producto_loaded' => $entrega?->producto !== null,
    'producto_nombre' => $entrega?->producto?->nombre,
    'broken_count' => Entrega::where('fuente', 'excel_historico')->whereDoesntHave('producto')->count(),
];

$entregaArea = Entrega::with('area')->where('fuente', 'excel_historico')->first();
$results['entrega_area'] = [
    'sample_id' => $entregaArea?->id,
    'area_loaded' => $entregaArea?->area !== null,
    'area_nombre' => $entregaArea?->area?->nombre,
    'broken_count' => Entrega::where('fuente', 'excel_historico')->whereDoesntHave('area')->count(),
];

$producto = Producto::with('categoria')
    ->whereHas('entregas', fn ($q) => $q->where('fuente', 'excel_historico'))
    ->first();

$results['producto_categoria'] = [
    'sample_id' => $producto?->id,
    'categoria_loaded' => $producto?->categoria !== null,
    'categoria_nombre' => $producto?->categoria?->nombre,
    'broken_count' => Producto::whereHas('entregas', fn ($q) => $q->where('fuente', 'excel_historico'))
        ->whereDoesntHave('categoria')
        ->count(),
];

$productoAliases = Producto::with('aliases')->find(29);
$results['producto_aliases'] = [
    'sample_id' => 29,
    'aliases_count' => $productoAliases?->aliases?->count(),
    'aliases_sample' => $productoAliases?->aliases?->pluck('alias')->take(5)->values()->all(),
    'broken_alias_refs' => ProductoAlias::whereNotNull('producto_id')->whereDoesntHave('producto')->count(),
];

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
