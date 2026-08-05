<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Area;
use App\Models\Entrega;
use App\Models\ExcelImportHomologacion;
use App\Models\ExcelImportStaging;
use App\Models\Producto;
use App\Models\ProductoAlias;

$staging = ExcelImportStaging::query()
    ->selectRaw('estado, count(*) as c')
    ->groupBy('estado')
    ->pluck('c', 'estado');

$entregas = Entrega::query()
    ->selectRaw('fuente, count(*) as c')
    ->groupBy('fuente')
    ->pluck('c', 'fuente');

$fecha = Entrega::query()
    ->selectRaw('min(fecha) as min_fecha, max(fecha) as max_fecha')
    ->first();

echo json_encode([
    'staging_por_estado' => $staging,
    'staging_total' => array_sum($staging->all()),
    'entregas_por_fuente' => $entregas,
    'entregas_total' => Entrega::count(),
    'entregas_fecha_min' => $fecha?->min_fecha,
    'entregas_fecha_max' => $fecha?->max_fecha,
    'productos_catalogo' => Producto::count(),
    'areas' => Area::count(),
    'aliases_producto' => ProductoAlias::count(),
    'homologaciones_manuales' => ExcelImportHomologacion::count(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
