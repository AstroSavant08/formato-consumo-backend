<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Producto;
use App\Support\TextNormalizer;

$map = [
    'BOLSA NEGRA MEDIANA INDUSTRIAL' => 'Bolsa NEGRA - basura (65 * 80)',
    'BOLSA VERDE MEDIANA INDUSTRIAL' => 'Bolsa verde (48 * 50) cocinetas',
    'BOLSA BLANCA MEDIANA INDUSTRIAL' => 'Bolsa blanca - (65*80)',
    'AXION' => 'Jabon Lavaloza liquido',
    'DETERGENTE' => 'Jabon Fab en polvo',
    'RAID LATA' => 'Insecticida zancudos',
    'JABON DE MANO' => 'Jabon Liquido (lavamanos)',
    'JABON PARA MANOS' => 'Jabon Liquido (lavamanos)',
    'PAPEL HIGIENICO' => 'Papel higienico - planta y oficinas pq (confirmar con IMPADOC)',
    'AROMATICA' => 'Aromaticas',
];

$out = [];
foreach ($map as $excel => $catalogName) {
    $p = Producto::query()
        ->where('nombre_normalizado', TextNormalizer::normalize($catalogName))
        ->first(['id', 'nombre', 'es_historico_excel']);
    $out[] = [
        'excel' => $excel,
        'sugerido_catalogo' => $catalogName,
        'producto_id' => $p?->id,
        'encontrado' => (bool) $p,
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
