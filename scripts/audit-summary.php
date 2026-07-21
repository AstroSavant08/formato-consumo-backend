<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Producto;
use App\Models\ProductoAlias;
use App\Models\Entrega;
use App\Models\ExcelImportStaging;
use App\Support\TextNormalizer;

$aliases = [
    'PAPEL HIGIENICO', 'PAPEL HIGIENICO PEQUEÑO', 'JABON DE MANO', 'JABON PARA MANOS',
    'AXION', 'JABON LOZA LIQUIDO', 'BOLSA BLANCA MEDIANA INDUSTRIAL', 'BOLSA NEGRA MEDIANA INDUSTRIAL',
    'BOLSA VERDE MEDIANA INDUSTRIAL', 'CEPILLO DE BAÑO', 'ESCOBILLON', 'DETERGENTE',
    'RAID LATA', 'FILTRO DE 2', 'FILTRO DE 8', 'SABRA',
];

function parseDate(?string $raw): ?string {
    if (!$raw || trim($raw) === '') return null;
    try {
        if (is_numeric($raw)) {
            return \Carbon\Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$raw))->format('Y-m-d');
        }
        return \Carbon\Carbon::parse($raw)->format('Y-m-d');
    } catch (\Throwable) { return null; }
}

function getRecords(string $alias) {
    $norm = TextNormalizer::normalize($alias);
    return ExcelImportStaging::all()->filter(function ($r) use ($norm) {
        return TextNormalizer::normalize(TextNormalizer::fixEncoding($r->producto_raw) ?? '') === $norm;
    })->values();
}

$candidateMap = [
    'PAPEL HIGIENICO' => ['Papel higienico - dispensadores', 'Papel higienico - planta y oficinas pq'],
    'PAPEL HIGIENICO PEQUEÑO' => ['Papel higienico - dispensadores', 'Papel higienico - planta y oficinas pq'],
    'JABON DE MANO' => ['Jabon Liquido (lavamanos)'],
    'JABON PARA MANOS' => ['Jabon Liquido (lavamanos)', 'JABON DE MANO'],
    'AXION' => ['Jabon Lavaloza liquido'],
    'JABON LOZA LIQUIDO' => ['Jabon Lavaloza liquido'],
    'BOLSA BLANCA MEDIANA INDUSTRIAL' => ['Bolsa blanca - (65*80)', 'Bolsa Blanca - (48*50) papeleras'],
    'BOLSA NEGRA MEDIANA INDUSTRIAL' => ['Bolsa NEGRA - basura (65 * 80)', 'Bolsa Negra - canecas (48*50)'],
    'BOLSA VERDE MEDIANA INDUSTRIAL' => ['Bolsa verde (48 * 50) cocinetas'],
    'CEPILLO DE BAÑO' => ['Escobillon para baño'],
    'ESCOBILLON' => ['Escobillon para baño', 'CEPILLO DE BAÑO'],
    'DETERGENTE' => ['Jabon Fab en polvo'],
    'RAID LATA' => ['Insecticida zancudos', 'Insecticida cucaracha'],
    'FILTRO DE 2' => ['Filtros cafetera # 2'],
    'FILTRO DE 8' => ['Filtros cafetera # 8'],
    'SABRA' => ['Zabra - esponja para lavar losa'],
];

$out = [];
foreach ($aliases as $alias) {
    $recs = getRecords($alias);
    $dates = $recs->map(fn($r) => parseDate($r->fecha_raw))->filter()->sort()->values();

    $units = [];
    foreach ($recs as $r) {
        $u = TextNormalizer::normalizeUnit($r->unidad_raw) ?? trim($r->unidad_raw ?? 'SIN UNIDAD') ?: 'SIN UNIDAD';
        $units[$u] = ($units[$u] ?? 0) + 1;
    }

    $areas = [];
    foreach ($recs as $r) {
        $a = trim($r->area_raw ?? 'SIN AREA');
        if (!isset($areas[$a])) $areas[$a] = ['count'=>0,'qty'=>0];
        $areas[$a]['count']++;
        $areas[$a]['qty'] += (float)($r->cantidad_raw ?: 0);
    }
    uasort($areas, fn($x,$y) => $y['count'] <=> $x['count']);

    $years = [];
    foreach ($recs as $r) {
        $d = parseDate($r->fecha_raw);
        if ($d) { $y = substr($d,0,4); $years[$y] = ($years[$y]??0)+1; }
    }

    $months = [];
    foreach ($recs as $r) {
        $d = parseDate($r->fecha_raw);
        if ($d) { $m = substr($d,0,7); $months[$m] = ($months[$m]??0)+1; }
    }
    arsort($months);
    $topMonths = array_slice($months, 0, 5, true);

    $examples = $recs->take(8)->map(fn($r) => [
        'fecha' => parseDate($r->fecha_raw),
        'alias' => TextNormalizer::fixEncoding($r->producto_raw),
        'cantidad' => $r->cantidad_raw,
        'unidad' => $r->unidad_raw,
        'area' => $r->area_raw,
        'quien_recibe' => $r->quien_recibe_raw,
        'entregado_por' => $r->entrega_raw,
    ])->values()->all();

    $candidates = [];
    foreach ($candidateMap[$alias] ?? [] as $name) {
        $p = Producto::where('nombre', $name)->first();
        if (!$p && $name === 'JABON DE MANO') {
            $p = Producto::where('nombre_normalizado', TextNormalizer::normalize('JABON DE MANO'))->first();
        }
        if ($p) {
            $candidates[] = [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'unidad_default' => $p->unidad_default,
                'es_historico_excel' => (bool)$p->es_historico_excel,
                'entregas' => Entrega::where('producto_id', $p->id)->count(),
            ];
        }
    }

    $histProduct = Producto::where('nombre_normalizado', TextNormalizer::normalize($alias))->where('es_historico_excel', true)->first();

    $related = ProductoAlias::where('requiere_revision', true)
        ->where('alias', '!=', $alias)
        ->pluck('alias')
        ->filter(function($a) use ($alias) {
            $w1 = explode(' ', TextNormalizer::normalize($alias))[0];
            $w2 = explode(' ', TextNormalizer::normalize($a))[0];
            return $w1 === $w2 || levenshtein(TextNormalizer::normalize($alias), TextNormalizer::normalize($a)) < 8;
        })->values()->all();

    $out[] = [
        'alias' => $alias,
        'records' => $recs->count(),
        'total_qty' => $recs->sum(fn($r) => (float)($r->cantidad_raw?:0)),
        'first_date' => $dates->first(),
        'last_date' => $dates->last(),
        'units' => $units,
        'areas' => $areas,
        'years' => $years,
        'top_months' => $topMonths,
        'examples' => $examples,
        'candidates' => $candidates,
        'historico_product_id' => $histProduct?->id,
        'historico_product_nombre' => $histProduct?->nombre,
        'related_aliases' => $related,
        'estados' => $recs->countBy('estado')->all(),
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
