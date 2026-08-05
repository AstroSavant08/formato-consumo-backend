<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Producto;

$operativos = Producto::query()
    ->where('activo', true)
    ->where('es_historico_excel', false)
    ->orderBy('nombre')
    ->get(['id', 'nombre']);

$historicos = Producto::query()
    ->where('activo', true)
    ->where('es_historico_excel', true)
    ->orderBy('nombre')
    ->get(['id', 'nombre']);

echo json_encode([
    'operativos_count' => $operativos->count(),
    'historicos_count' => $historicos->count(),
    'operativos' => $operativos->pluck('nombre'),
    'historicos' => $historicos->pluck('nombre'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
