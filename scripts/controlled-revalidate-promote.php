<?php

/**
 * Revalidación y promoción controlada para aliases autorizados únicamente.
 * No ejecuta validate-staging ni promote-staging globales.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Area;
use App\Models\Entrega;
use App\Models\ExcelImportStaging;
use App\Models\ProductoAlias;
use App\Support\TextNormalizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

const AUTHORIZED_ALIASES = [
    'JABON LOZA LIQUIDO',
    'BOLSA VERDE MEDIANA INDUSTRIAL',
    'ESCOBILLON',
    'FILTRO DE 2',
    'FILTRO DE 8',
    'SABRA',
    'JABON DE MANO',
    'JABON PARA MANOS',
];

const PENDING_ALIASES = [
    'PAPEL HIGIENICO',
    'BOLSA BLANCA MEDIANA INDUSTRIAL',
    'BOLSA NEGRA MEDIANA INDUSTRIAL',
    'CEPILLO DE BANO',
    'CEPILLO DE BAÑO',
    'DETERGENTE',
    'RAID LATA',
    'AXION',
];

const EXPECTED_PRODUCTO_IDS = [
    'JABON LOZA LIQUIDO' => 28,
    'BOLSA VERDE MEDIANA INDUSTRIAL' => 12,
    'ESCOBILLON' => 18,
    'FILTRO DE 2' => 21,
    'FILTRO DE 8' => 22,
    'SABRA' => 48,
    'JABON DE MANO' => 29,
    'JABON PARA MANOS' => 29,
];

function normalizeAlias(?string $productoRaw): ?string
{
    if ($productoRaw === null || trim($productoRaw) === '') {
        return null;
    }

    return TextNormalizer::normalize(TextNormalizer::fixEncoding($productoRaw) ?? $productoRaw);
}

function parseDate(?string $value): ?Carbon
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    try {
        if (is_numeric($value)) {
            return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value));
        }

        return Carbon::parse($value);
    } catch (\Throwable) {
        return null;
    }
}

function resolveArea(?string $areaRaw): ?Area
{
    if ($areaRaw === null || trim($areaRaw) === '') {
        return null;
    }

    return Area::query()
        ->where('codigo', TextNormalizer::normalize($areaRaw))
        ->first();
}

function resolveProductoId(?string $productoRaw, array &$errors, string &$estado): ?int
{
    if ($productoRaw === null || trim($productoRaw) === '') {
        $errors[] = 'Producto vacío';
        $estado = 'requiere_revision';

        return null;
    }

    $normalized = normalizeAlias($productoRaw);

    $alias = ProductoAlias::query()
        ->where('alias_normalizado', $normalized)
        ->where('fuente', 'excel')
        ->first();

    if ($alias?->producto_id) {
        return $alias->producto_id;
    }

    $producto = \App\Models\Producto::query()
        ->where('nombre_normalizado', $normalized)
        ->first();

    if ($producto) {
        return $producto->id;
    }

    $errors[] = 'Producto no resuelto en catálogo';
    $estado = 'requiere_revision';

    return null;
}

function isAuthorizedRecord(ExcelImportStaging $record): bool
{
    $normalized = normalizeAlias($record->producto_raw);

    return $normalized !== null && in_array($normalized, AUTHORIZED_ALIASES, true);
}

function isPendingRecord(ExcelImportStaging $record): bool
{
    $normalized = normalizeAlias($record->producto_raw);

    if ($normalized === null) {
        return false;
    }

    if (in_array($normalized, PENDING_ALIASES, true)) {
        return true;
    }

    return in_array($normalized, array_map(
        fn (string $alias) => TextNormalizer::normalize($alias),
        PENDING_ALIASES
    ), true);
}

function snapshotPendingRecords(): array
{
    return ExcelImportStaging::query()
        ->orderBy('id')
        ->get()
        ->filter(fn (ExcelImportStaging $record) => isPendingRecord($record))
        ->map(fn (ExcelImportStaging $record) => [
            'id' => $record->id,
            'estado' => $record->estado,
            'producto_id' => $record->producto_id,
            'area_id' => $record->area_id,
            'errores_json' => $record->errores_json,
            'es_posible_duplicado' => $record->es_posible_duplicado,
            'updated_at' => (string) $record->updated_at,
        ])
        ->values()
        ->all();
}

function snapshotPendingAliases(): array
{
    return ProductoAlias::query()
        ->whereIn('alias_normalizado', array_map(
            fn (string $alias) => TextNormalizer::normalize($alias),
            PENDING_ALIASES
        ))
        ->orderBy('alias')
        ->get(['id', 'alias', 'producto_id', 'revisado', 'requiere_revision', 'updated_at'])
        ->map(fn ($alias) => $alias->toArray())
        ->values()
        ->all();
}

$before = [
    'total_staging' => ExcelImportStaging::count(),
    'importado' => ExcelImportStaging::where('estado', 'importado')->count(),
    'requiere_revision' => ExcelImportStaging::where('estado', 'requiere_revision')->count(),
    'entregas_historicas' => Entrega::where('fuente', 'excel_historico')->count(),
];

$perAliasBefore = [];
foreach (AUTHORIZED_ALIASES as $alias) {
    $perAliasBefore[$alias] = [
        'identificados' => 0,
        'by_estado' => [],
    ];
}

$authorizedRecords = ExcelImportStaging::query()->orderBy('fila_excel')->get()->filter(fn ($r) => isAuthorizedRecord($r));
foreach ($authorizedRecords as $record) {
    $alias = normalizeAlias($record->producto_raw);
    $perAliasBefore[$alias]['identificados']++;
    $perAliasBefore[$alias]['by_estado'][$record->estado] = ($perAliasBefore[$alias]['by_estado'][$record->estado] ?? 0) + 1;
}

$pendingRecordsBefore = snapshotPendingRecords();
$pendingAliasesBefore = snapshotPendingAliases();

$hashCounts = ExcelImportStaging::query()
    ->whereNotNull('excel_hash')
    ->pluck('excel_hash')
    ->countBy()
    ->all();

$promotedTotal = 0;
$promotedSkipped = 0;

DB::transaction(function () use (
    $authorizedRecords,
    $hashCounts,
    &$promotedTotal,
    &$promotedSkipped
) {
    foreach ($authorizedRecords as $record) {
        $aliasKey = normalizeAlias($record->producto_raw);
        $errors = [];
        $estado = 'validado';

        $fecha = parseDate($record->fecha_raw);
        if (! $fecha) {
            $errors[] = 'Fecha inválida o vacía';
            $estado = 'requiere_revision';
        }

        if ($record->cantidad_raw === null || trim((string) $record->cantidad_raw) === '') {
            $errors[] = 'Cantidad vacía';
            $estado = 'requiere_revision';
        } elseif (! is_numeric($record->cantidad_raw)) {
            $errors[] = 'Cantidad no numérica';
            $estado = 'requiere_revision';
        }

        if ($record->unidad_raw === null || trim((string) $record->unidad_raw) === '') {
            $errors[] = 'Unidad vacía';
            $estado = 'requiere_revision';
        }

        $area = resolveArea($record->area_raw);
        if (! $area) {
            $errors[] = 'Área no reconocida';
            $estado = 'requiere_revision';
        }

        $productoId = resolveProductoId($record->producto_raw, $errors, $estado);
        if ($productoId === null) {
            $estado = 'requiere_revision';
        }

        $expectedProductoId = EXPECTED_PRODUCTO_IDS[$aliasKey] ?? null;
        if ($estado === 'validado' && $productoId !== $expectedProductoId) {
            $errors[] = 'Producto resuelto no coincide con equivalencia autorizada';
            $estado = 'requiere_revision';
        }

        $esDuplicado = ($hashCounts[$record->excel_hash] ?? 0) > 1;

        $record->update([
            'estado' => $estado,
            'errores_json' => $errors ?: null,
            'area_id' => $area?->id,
            'producto_id' => $productoId,
            'es_posible_duplicado' => $esDuplicado,
        ]);
    }

    $recordsToPromote = ExcelImportStaging::query()
        ->where('estado', 'validado')
        ->orderBy('fila_excel')
        ->get()
        ->filter(fn ($record) => isAuthorizedRecord($record));

    foreach ($recordsToPromote as $record) {
        $aliasKey = normalizeAlias($record->producto_raw);

        if (! $record->producto_id || ! $record->area_id) {
            $promotedSkipped++;
            continue;
        }

        if (Entrega::query()->where('staging_id', $record->id)->exists()) {
            $promotedSkipped++;
            continue;
        }

        $fecha = parseDate($record->fecha_raw);
        if (! $fecha) {
            $promotedSkipped++;
            continue;
        }

        Entrega::query()->create([
            'fecha' => $fecha,
            'area_id' => $record->area_id,
            'producto_id' => $record->producto_id,
            'cantidad' => (float) $record->cantidad_raw,
            'unidad' => TextNormalizer::normalizeUnit($record->unidad_raw) ?? $record->unidad_raw,
            'quien_recibe' => $record->quien_recibe_raw,
            'entregado_por' => $record->entrega_raw,
            'fuente' => 'excel_historico',
            'excel_fila' => $record->fila_excel,
            'excel_hash' => $record->excel_hash,
            'es_posible_duplicado' => $record->es_posible_duplicado,
            'staging_id' => $record->id,
        ]);

        $record->update(['estado' => 'importado']);
        $promotedTotal++;
    }
});

$perAliasResults = [];
$revalidatedTotal = 0;

foreach (AUTHORIZED_ALIASES as $alias) {
    $aliasRecords = ExcelImportStaging::query()
        ->get()
        ->filter(function ($record) use ($alias) {
            return normalizeAlias($record->producto_raw) === $alias;
        });

    $identificados = $aliasRecords->count();
    $importados = $aliasRecords->where('estado', 'importado')->count();
    $pendientes = $aliasRecords->where('estado', 'requiere_revision')->count();
    $validadosSinPromover = $aliasRecords->where('estado', 'validado')->count();
    $revalidados = $importados + $validadosSinPromover;

    $perAliasResults[$alias] = [
        'identificados' => $identificados,
        'revalidados' => $revalidados,
        'promovidos' => $importados,
        'pendientes' => $pendientes + $validadosSinPromover,
    ];

    $revalidatedTotal += $revalidados;
}

$pendingRecordsAfter = snapshotPendingRecords();
$pendingAliasesAfter = snapshotPendingAliases();

$after = [
    'total_staging' => ExcelImportStaging::count(),
    'importado' => ExcelImportStaging::where('estado', 'importado')->count(),
    'requiere_revision' => ExcelImportStaging::where('estado', 'requiere_revision')->count(),
    'entregas_historicas' => Entrega::where('fuente', 'excel_historico')->count(),
];

$pendingRecordsChanged = array_values(array_filter($pendingRecordsAfter, function ($afterRow) use ($pendingRecordsBefore) {
    $beforeRow = collect($pendingRecordsBefore)->firstWhere('id', $afterRow['id']);

    return $beforeRow !== $afterRow;
}));

$pendingAliasesChanged = array_values(array_filter($pendingAliasesAfter, function ($afterRow) use ($pendingAliasesBefore) {
    $beforeRow = collect($pendingAliasesBefore)->firstWhere('id', $afterRow['id']);

    return $beforeRow !== $afterRow;
}));

$authorizedProductChecks = ExcelImportStaging::query()
    ->get()
    ->filter(fn ($record) => isAuthorizedRecord($record))
    ->groupBy(fn ($record) => normalizeAlias($record->producto_raw))
    ->map(function ($records, $alias) {
        $expected = EXPECTED_PRODUCTO_IDS[$alias] ?? null;
        $importados = $records->where('estado', 'importado')->count();
        $pendientes = $records->where('estado', 'requiere_revision')->count();
        $wrongProducto = $records->filter(fn ($r) => $r->producto_id !== $expected)->count();

        return [
            'expected_producto_id' => $expected,
            'importados' => $importados,
            'pendientes' => $pendientes,
            'wrong_producto_id' => $wrongProducto,
        ];
    })
    ->all();

echo json_encode([
    'operation' => 'controlled_revalidate_promote',
    'global_commands_skipped' => [
        'consumo:validate-staging' => 'Procesa los 1163 registros; no ejecutado.',
        'consumo:promote-staging' => 'Promueve todos los validados; no ejecutado.',
    ],
    'before' => $before,
    'per_alias' => array_values(array_map(
        fn (string $alias) => [
            'alias' => $alias,
            'registros_identificados' => $perAliasResults[$alias]['identificados'],
            'revalidados' => $perAliasResults[$alias]['revalidados'],
            'promovidos' => $perAliasResults[$alias]['promovidos'],
            'pendientes' => $perAliasResults[$alias]['pendientes'],
        ],
        AUTHORIZED_ALIASES
    )),
    'revalidated_total' => $revalidatedTotal,
    'promoted_total' => $promotedTotal,
    'promotion_skipped' => $promotedSkipped,
    'after' => $after,
    'integrity' => [
        'pending_staging_records_changed' => count($pendingRecordsChanged),
        'pending_aliases_changed' => count($pendingAliasesChanged),
        'authorized_product_checks' => $authorizedProductChecks,
        'axion_producto_id' => ProductoAlias::query()->where('alias', 'AXION')->value('producto_id'),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
