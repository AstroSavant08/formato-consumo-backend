<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Producto;
use App\Models\ProductoAlias;
use App\Models\Entrega;
use App\Models\ExcelImportStaging;
use App\Support\TextNormalizer;
use Illuminate\Support\Facades\DB;

$aliasesToAudit = [
    'PAPEL HIGIENICO',
    'PAPEL HIGIENICO PEQUEÑO',
    'JABON DE MANO',
    'JABON PARA MANOS',
    'AXION',
    'JABON LOZA LIQUIDO',
    'BOLSA BLANCA MEDIANA INDUSTRIAL',
    'BOLSA NEGRA MEDIANA INDUSTRIAL',
    'BOLSA VERDE MEDIANA INDUSTRIAL',
    'CEPILLO DE BAÑO',
    'ESCOBILLON',
    'DETERGENTE',
    'RAID LATA',
    'FILTRO DE 2',
    'FILTRO DE 8',
    'SABRA',
];

function matchStagingRecords(string $aliasName)
{
    $normalized = TextNormalizer::normalize($aliasName);

    return ExcelImportStaging::query()
        ->where(function ($q) use ($aliasName, $normalized) {
            $q->whereRaw('UPPER(TRIM(producto_raw)) = ?', [mb_strtoupper(trim($aliasName))])
                ->orWhereRaw('UPPER(TRIM(producto_raw)) LIKE ?', ['%' . str_replace(' ', '%', mb_strtoupper(trim($aliasName))) . '%']);
        })
        ->get()
        ->filter(function ($r) use ($normalized) {
            $raw = TextNormalizer::normalize(TextNormalizer::fixEncoding($r->producto_raw) ?? '');

            return $raw === $normalized;
        })
        ->values();
}

function parseDate(?string $raw): ?string
{
    if (!$raw || trim($raw) === '') {
        return null;
    }
    try {
        if (is_numeric($raw)) {
            return \Carbon\Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw))->format('Y-m-d');
        }

        return \Carbon\Carbon::parse($raw)->format('Y-m-d');
    } catch (\Throwable) {
        return $raw;
    }
}

function findCandidates(string $aliasName): array
{
    $keywords = array_filter(explode(' ', TextNormalizer::normalize($aliasName)));
    $productos = Producto::query()->where('activo', true)->get();
    $candidates = [];

    foreach ($productos as $p) {
        $pn = TextNormalizer::normalize($p->nombre);
        $score = 0;
        $reasons = [];

        foreach ($keywords as $kw) {
            if (strlen($kw) < 3) {
                continue;
            }
            if (str_contains($pn, $kw)) {
                $score += 1;
                $reasons[] = "Contiene '{$kw}'";
            }
        }

        if ($pn === TextNormalizer::normalize($aliasName)) {
            $score += 5;
            $reasons[] = 'Nombre normalizado idéntico';
        }

        if (similar_text($pn, TextNormalizer::normalize($aliasName)) / max(strlen($pn), 1) > 0.5) {
            $score += 0.5;
        }

        if ($score > 0) {
            $entregasCount = Entrega::where('producto_id', $p->id)->count();
            $candidates[] = [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'unidad_default' => $p->unidad_default,
                'es_historico_excel' => $p->es_historico_excel,
                'entregas_count' => $entregasCount,
                'score' => $score,
                'reasons' => $reasons,
            ];
        }
    }

    usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

    return array_slice($candidates, 0, 5);
}

function confidenceLabel(float $score, bool $hasStrongMatch): string
{
    if ($hasStrongMatch && $score >= 5) {
        return 'Alta';
    }
    if ($score >= 2) {
        return 'Media';
    }
    if ($score >= 1) {
        return 'Baja';
    }

    return 'Insuficiente';
}

function suggestAction(array $audit): string
{
    $records = $audit['records_count'];
    $topCandidate = $audit['candidates'][0] ?? null;
    $confidence = $topCandidate['confidence'] ?? 'Insuficiente';

    if ($confidence === 'Alta' && $records >= 10) {
        return 'ALIAS_PROBABLE';
    }
    if ($confidence === 'Insuficiente' || !$topCandidate) {
        return $records >= 5 ? 'PRODUCTO_INDEPENDIENTE_PROBABLE' : 'REQUIERE_CONFIRMACION_NEGOCIO';
    }
    if (in_array($audit['alias'], ['PAPEL HIGIENICO PEQUEÑO', 'JABON PARA MANOS'], true)) {
        return 'VARIANTE_PROBABLE';
    }

    return 'REQUIERE_CONFIRMACION_NEGOCIO';
}

$report = [];

foreach ($aliasesToAudit as $aliasName) {
    $records = matchStagingRecords($aliasName);
    $dates = $records->map(fn ($r) => parseDate($r->fecha_raw))->filter()->sort()->values();

    $units = $records->groupBy(fn ($r) => TextNormalizer::normalizeUnit($r->unidad_raw) ?? trim($r->unidad_raw ?? 'SIN UNIDAD'))
        ->map(fn ($g) => ['count' => $g->count(), 'total_qty' => $g->sum(fn ($r) => (float) ($r->cantidad_raw ?: 0))]);

    $areas = $records->groupBy(fn ($r) => trim($r->area_raw ?? 'SIN AREA'))
        ->map(fn ($g) => ['count' => $g->count(), 'total_qty' => $g->sum(fn ($r) => (float) ($r->cantidad_raw ?: 0))])
        ->sortByDesc('count');

    $byYear = $records->groupBy(function ($r) {
        $d = parseDate($r->fecha_raw);

        return $d ? substr($d, 0, 4) : 'SIN FECHA';
    })->map->count();

    $byMonth = $records->groupBy(function ($r) {
        $d = parseDate($r->fecha_raw);

        return $d ? substr($d, 0, 7) : 'SIN FECHA';
    })->sortByDesc(fn ($g) => $g->count())->take(5);

    $examples = $records->take(10)->map(fn ($r) => [
        'fecha' => parseDate($r->fecha_raw),
        'alias' => TextNormalizer::fixEncoding($r->producto_raw),
        'cantidad' => $r->cantidad_raw,
        'unidad' => $r->unidad_raw,
        'area' => $r->area_raw,
        'quien_recibe' => $r->quien_recibe_raw,
        'entregado_por' => $r->entrega_raw,
        'estado' => $r->estado,
    ])->values();

    $candidatesRaw = findCandidates($aliasName);
    $candidates = array_map(function ($c) use ($aliasName) {
        $c['confidence'] = confidenceLabel($c['score'], TextNormalizer::normalize($c['nombre']) === TextNormalizer::normalize($aliasName));

        return $c;
    }, $candidatesRaw);

    $relatedAliases = ProductoAlias::query()
        ->where('requiere_revision', true)
        ->get()
        ->filter(function ($a) use ($aliasName) {
            $an = TextNormalizer::normalize($a->alias);
            $target = TextNormalizer::normalize($aliasName);
            if ($an === $target) {
                return false;
            }
            $words = array_filter(explode(' ', $target));
            foreach ($words as $w) {
                if (strlen($w) >= 4 && str_contains($an, $w)) {
                    return true;
                }
            }

            return false;
        })
        ->pluck('alias')
        ->values()
        ->all();

    $relatedProducts = Producto::query()
        ->where('es_historico_excel', true)
        ->orWhere('nombre', 'like', '%' . explode(' ', $aliasName)[0] . '%')
        ->get()
        ->filter(function ($p) use ($aliasName) {
            $pn = TextNormalizer::normalize($p->nombre);
            $an = TextNormalizer::normalize($aliasName);

            return $pn !== $an && (
                str_contains($pn, explode(' ', $an)[0]) ||
                str_contains($an, explode(' ', $pn)[0])
            );
        })
        ->pluck('nombre')
        ->take(8)
        ->values()
        ->all();

    $audit = [
        'alias' => $aliasName,
        'alias_normalized' => TextNormalizer::normalize($aliasName),
        'records_count' => $records->count(),
        'total_qty' => $records->sum(fn ($r) => (float) ($r->cantidad_raw ?: 0)),
        'first_date' => $dates->first(),
        'last_date' => $dates->last(),
        'units' => $units,
        'areas' => $areas,
        'by_year' => $byYear,
        'top_months' => $byMonth,
        'examples' => $examples,
        'candidates' => $candidates,
        'related_aliases' => $relatedAliases,
        'related_products' => $relatedProducts,
        'staging_estados' => $records->countBy('estado'),
    ];

    $audit['suggested_action'] = suggestAction($audit);
    $report[] = $audit;
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
