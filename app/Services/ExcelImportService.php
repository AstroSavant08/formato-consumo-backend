<?php

namespace App\Services;

use App\Exceptions\StagingHomologacionException;
use App\Models\Area;
use App\Models\Entrega;
use App\Models\ExcelImportHomologacion;
use App\Models\ExcelImportStaging;
use App\Models\Producto;
use App\Models\ProductoAlias;
use App\Support\TextNormalizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelImportService
{
    public function importToStaging(string $filePath): array
    {
        if (! is_readable($filePath)) {
            throw new \RuntimeException("No se puede leer el archivo: {$filePath}");
        }

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheetByName('BD') ?? $spreadsheet->getActiveSheet();
        $imported = 0;
        $skipped = 0;

        $highestRow = $sheet->getHighestDataRow();

        for ($row = 2; $row <= $highestRow; $row++) {
            $fechaRaw = $this->cellToString($sheet->getCell("A{$row}")->getValue());
            $productoRaw = TextNormalizer::fixEncoding($this->cellToString($sheet->getCell("B{$row}")->getValue()));
            $cantidadRaw = $this->cellToString($sheet->getCell("C{$row}")->getCalculatedValue());
            $unidadRaw = TextNormalizer::fixEncoding($this->cellToString($sheet->getCell("D{$row}")->getValue()));
            $areaRaw = $this->cellToString($sheet->getCell("E{$row}")->getValue());
            $quienRecibeRaw = $this->cellToString($sheet->getCell("F{$row}")->getValue());
            $entregaRaw = $this->cellToString($sheet->getCell("G{$row}")->getValue());

            if ($this->isEmptyRow($fechaRaw, $productoRaw, $cantidadRaw, $unidadRaw, $areaRaw, $quienRecibeRaw, $entregaRaw)) {
                $skipped++;
                continue;
            }

            $hash = hash('sha256', implode('|', [
                $fechaRaw,
                TextNormalizer::normalize($productoRaw ?? ''),
                $cantidadRaw,
                TextNormalizer::normalize($areaRaw ?? ''),
                TextNormalizer::normalize($quienRecibeRaw ?? ''),
            ]));

            ExcelImportStaging::query()->updateOrCreate(
                ['fila_excel' => $row],
                [
                    'fecha_raw' => $fechaRaw,
                    'producto_raw' => $productoRaw,
                    'cantidad_raw' => $cantidadRaw,
                    'unidad_raw' => $unidadRaw,
                    'area_raw' => $areaRaw,
                    'quien_recibe_raw' => $quienRecibeRaw,
                    'entrega_raw' => $entregaRaw,
                    'estado' => 'pendiente',
                    'excel_hash' => $hash,
                    'errores_json' => null,
                    'es_posible_duplicado' => false,
                    'producto_id' => null,
                    'area_id' => null,
                ]
            );

            $imported++;
        }

        return [
            'imported' => $imported,
            'skipped_empty_rows' => $skipped,
            'total_rows_scanned' => max(0, $highestRow - 1),
        ];
    }

    public function validateStaging(): array
    {
        $stats = [
            'validado' => 0,
            'requiere_revision' => 0,
            'rechazado' => 0,
            'posibles_duplicados' => 0,
        ];

        $hashCounts = ExcelImportStaging::query()
            ->whereNotNull('excel_hash')
            ->pluck('excel_hash')
            ->countBy()
            ->all();

        $records = ExcelImportStaging::query()->orderBy('fila_excel')->get();

        foreach ($records as $record) {
            $validation = $this->buildValidationResult($record, $hashCounts);

            $record->update([
                'estado' => $validation['estado'],
                'errores_json' => $validation['errores_json'],
                'area_id' => $validation['area_id'],
                'producto_id' => $validation['producto_id'],
                'es_posible_duplicado' => $validation['es_posible_duplicado'],
            ]);

            $stats[$validation['estado']]++;
            if ($validation['es_posible_duplicado']) {
                $stats['posibles_duplicados']++;
            }
        }

        return $stats;
    }

    /**
     * @param  array<int>  $stagingIds
     * @return array{
     *     validados: array<int, array<string, mixed>>,
     *     requieren_revision: array<int, array<string, mixed>>,
     *     omitidos: array<int, array<string, mixed>>,
     *     errores: array<int, array<string, mixed>>
     * }
     */
    public function validateSelectedStaging(array $stagingIds): array
    {
        return DB::transaction(function () use ($stagingIds) {
            $report = [
                'validados' => [],
                'requieren_revision' => [],
                'omitidos' => [],
                'errores' => [],
            ];

            $hashCounts = ExcelImportStaging::query()
                ->whereNotNull('excel_hash')
                ->pluck('excel_hash')
                ->countBy()
                ->all();

            $records = ExcelImportStaging::query()
                ->with(['homologacion.productoDestino'])
                ->whereIn('id', $stagingIds)
                ->get()
                ->keyBy('id');

            foreach ($stagingIds as $stagingId) {
                $record = $records->get($stagingId);

                if (! $record) {
                    $report['errores'][] = [
                        'staging_id' => $stagingId,
                        'motivo' => 'Registro de staging no encontrado.',
                    ];
                    continue;
                }

                if ($record->estado === 'importado') {
                    $report['omitidos'][] = $this->buildValidateOmitidoEntry(
                        $record,
                        'No se puede validar un registro ya importado a entregas.'
                    );
                    continue;
                }

                if ($record->estado === 'rechazado') {
                    $report['omitidos'][] = $this->buildValidateOmitidoEntry(
                        $record,
                        'No se puede validar un registro rechazado.'
                    );
                    continue;
                }

                $validation = $this->buildValidationResult($record, $hashCounts);

                $record->update([
                    'estado' => $validation['estado'],
                    'errores_json' => $validation['errores_json'],
                    'area_id' => $validation['area_id'],
                    'producto_id' => $validation['producto_id'],
                    'es_posible_duplicado' => $validation['es_posible_duplicado'],
                ]);

                $entry = $this->buildValidateReportEntry($record, $validation);

                if ($validation['estado'] === 'validado') {
                    $report['validados'][] = $entry;
                } else {
                    $report['requieren_revision'][] = $entry;
                }
            }

            return $report;
        });
    }

    /**
     * @return array{
     *     estado: string,
     *     errores_json: array<int, string>|null,
     *     area_id: int|null,
     *     producto_id: int|null,
     *     es_posible_duplicado: bool
     * }
     */
    private function buildValidationResult(ExcelImportStaging $record, array $hashCounts): array
    {
        $errors = [];
        $estado = 'validado';

        $fecha = $this->parseDate($record->fecha_raw);
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

        $area = $this->resolveArea($record->area_raw);
        if (! $area) {
            $errors[] = 'Área no reconocida';
            $estado = 'requiere_revision';
        }

        $resolvedViaHomologacion = false;
        $productoId = $this->resolveHomologacionProductoId($record);

        if ($productoId !== null) {
            $resolvedViaHomologacion = true;
        } else {
            $productoId = $this->resolveProductoId($record->producto_raw, $errors, $estado);
            if ($productoId === null) {
                $estado = 'requiere_revision';
            }
        }

        $esDuplicado = ($hashCounts[$record->excel_hash] ?? 0) > 1;

        if (
            $estado === 'validado'
            && $productoId
            && ! $resolvedViaHomologacion
            && $this->aliasRequiresRevision($record->producto_raw)
        ) {
            $estado = 'requiere_revision';
            $errors[] = 'Producto con alias pendiente de revisión humana';
        }

        return [
            'estado' => $estado,
            'errores_json' => $errors ?: null,
            'area_id' => $area?->id,
            'producto_id' => $productoId,
            'es_posible_duplicado' => $esDuplicado,
        ];
    }

    /**
     * @param  array<string, mixed>  $validation
     * @return array<string, mixed>
     */
    private function buildValidateReportEntry(ExcelImportStaging $record, array $validation): array
    {
        $producto = $validation['producto_id']
            ? Producto::query()->find($validation['producto_id'])
            : null;
        $area = $validation['area_id']
            ? Area::query()->find($validation['area_id'])
            : null;

        return [
            'staging_id' => $record->id,
            'fila_excel' => $record->fila_excel,
            'producto_raw' => $record->producto_raw,
            'estado' => $validation['estado'],
            'producto_id' => $validation['producto_id'],
            'area_id' => $validation['area_id'],
            'errores_json' => $validation['errores_json'],
            'es_posible_duplicado' => $validation['es_posible_duplicado'],
            'producto_resuelto' => $producto ? [
                'id' => $producto->id,
                'nombre' => $producto->nombre,
            ] : null,
            'area_resuelta' => $area ? [
                'id' => $area->id,
                'codigo' => $area->codigo,
                'nombre' => $area->nombre,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildValidateOmitidoEntry(ExcelImportStaging $record, string $motivo): array
    {
        return [
            'staging_id' => $record->id,
            'fila_excel' => $record->fila_excel,
            'producto_raw' => $record->producto_raw,
            'estado' => $record->estado,
            'motivo' => $motivo,
        ];
    }

    public function promoteValidated(): array
    {
        $promoted = 0;
        $skipped = 0;

        $records = ExcelImportStaging::query()
            ->where('estado', 'validado')
            ->orderBy('fila_excel')
            ->get();

        foreach ($records as $record) {
            if (Entrega::query()->where('staging_id', $record->id)->exists()) {
                $skipped++;
                continue;
            }

            $payload = $this->buildPromotedEntregaPayload($record);
            if ($payload === null) {
                $skipped++;
                continue;
            }

            Entrega::query()->create($payload);
            $record->update(['estado' => 'importado']);
            $promoted++;
        }

        return [
            'promoted' => $promoted,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<int>  $stagingIds
     * @return array{
     *     promovidos: array<int, array<string, mixed>>,
     *     omitidos: array<int, array<string, mixed>>,
     *     errores: array<int, array<string, mixed>>
     * }
     */
    public function promoteSelectedStaging(array $stagingIds, bool $confirmarPromocion = false): array
    {
        if (! $confirmarPromocion) {
            throw new StagingHomologacionException(
                'Se requiere confirmación explícita para promover registros históricos seleccionados.',
                422
            );
        }

        return DB::transaction(function () use ($stagingIds) {
            $report = [
                'promovidos' => [],
                'omitidos' => [],
                'errores' => [],
            ];

            $records = ExcelImportStaging::query()
                ->with(['producto'])
                ->whereIn('id', $stagingIds)
                ->get()
                ->keyBy('id');

            foreach ($stagingIds as $stagingId) {
                $record = $records->get($stagingId);

                if (! $record) {
                    $report['errores'][] = [
                        'staging_id' => $stagingId,
                        'fila_excel' => null,
                        'error' => 'Registro de staging no encontrado.',
                    ];
                    continue;
                }

                if ($record->estado !== 'validado') {
                    $report['omitidos'][] = $this->buildPromoteOmitidoEntry(
                        $record,
                        'El registro no está en estado validado.'
                    );
                    continue;
                }

                if (Entrega::query()->where('staging_id', $record->id)->exists()) {
                    $report['omitidos'][] = $this->buildPromoteOmitidoEntry(
                        $record,
                        'Ya existe una entrega asociada a este registro de staging.'
                    );
                    continue;
                }

                if (! $record->producto_id) {
                    $report['omitidos'][] = $this->buildPromoteOmitidoEntry(
                        $record,
                        'El registro no tiene producto resuelto.'
                    );
                    continue;
                }

                if (! $record->area_id) {
                    $report['omitidos'][] = $this->buildPromoteOmitidoEntry(
                        $record,
                        'El registro no tiene área resuelta.'
                    );
                    continue;
                }

                $payload = $this->buildPromotedEntregaPayload($record);
                if ($payload === null) {
                    $report['omitidos'][] = $this->buildPromoteOmitidoEntry(
                        $record,
                        'La fecha histórica no es válida.'
                    );
                    continue;
                }

                $entrega = Entrega::query()->create($payload);
                $record->update(['estado' => 'importado']);

                $report['promovidos'][] = $this->buildPromoteReportEntry($record, $entrega, $payload);
            }

            return $report;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildPromotedEntregaPayload(ExcelImportStaging $record): ?array
    {
        if (! $record->producto_id || ! $record->area_id) {
            return null;
        }

        $fecha = $this->parseDate($record->fecha_raw);
        if (! $fecha) {
            return null;
        }

        return [
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
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildPromoteReportEntry(
        ExcelImportStaging $record,
        Entrega $entrega,
        array $payload,
    ): array {
        $producto = $record->relationLoaded('producto')
            ? $record->producto
            : Producto::query()->find($record->producto_id);

        return [
            'staging_id' => $record->id,
            'fila_excel' => $record->fila_excel,
            'entrega_id' => $entrega->id,
            'producto_raw' => $record->producto_raw,
            'producto_resuelto' => $producto ? [
                'id' => $producto->id,
                'nombre' => $producto->nombre,
            ] : null,
            'fecha' => $payload['fecha'] instanceof Carbon
                ? $payload['fecha']->toDateString()
                : (string) $payload['fecha'],
            'cantidad' => (float) $record->cantidad_raw,
            'unidad' => $payload['unidad'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPromoteOmitidoEntry(ExcelImportStaging $record, string $motivo): array
    {
        return [
            'staging_id' => $record->id,
            'fila_excel' => $record->fila_excel,
            'motivo' => $motivo,
        ];
    }

    public function getStagingSummary(): array
    {
        $byEstado = ExcelImportStaging::query()
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return [
            'total' => ExcelImportStaging::count(),
            'by_estado' => $byEstado,
            'requiere_revision' => (int) ($byEstado['requiere_revision'] ?? 0),
            'validado' => (int) ($byEstado['validado'] ?? 0),
            'importado' => (int) ($byEstado['importado'] ?? 0),
            'rechazado' => (int) ($byEstado['rechazado'] ?? 0),
            'homologaciones_activas' => ExcelImportHomologacion::count(),
            'posibles_duplicados' => ExcelImportStaging::where('es_posible_duplicado', true)->count(),
            'importados' => ExcelImportStaging::where('estado', 'importado')->count(),
            'entregas_historicas' => Entrega::where('fuente', 'excel_historico')->count(),
        ];
    }

    private function resolveArea(?string $areaRaw): ?Area
    {
        if ($areaRaw === null || trim($areaRaw) === '') {
            return null;
        }

        return Area::query()
            ->where('codigo', TextNormalizer::normalize($areaRaw))
            ->first();
    }

    private function resolveHomologacionProductoId(ExcelImportStaging $record): ?int
    {
        $homologacion = ExcelImportHomologacion::query()
            ->where('staging_id', $record->id)
            ->first();

        if (! $homologacion) {
            return null;
        }

        $producto = Producto::query()->find($homologacion->producto_id_destino);

        if (! $producto || ! $producto->activo || $producto->es_historico_excel) {
            return null;
        }

        return $producto->id;
    }

    private function resolveProductoId(?string $productoRaw, array &$errors, string &$estado): ?int
    {
        if ($productoRaw === null || trim($productoRaw) === '') {
            $errors[] = 'Producto vacío';
            $estado = 'requiere_revision';

            return null;
        }

        $normalized = TextNormalizer::normalize($productoRaw);

        $alias = ProductoAlias::query()
            ->where('alias_normalizado', $normalized)
            ->where('fuente', 'excel')
            ->first();

        if ($alias?->producto_id) {
            return $alias->producto_id;
        }

        $producto = Producto::query()
            ->where('nombre_normalizado', $normalized)
            ->first();

        if ($producto) {
            return $producto->id;
        }

        $errors[] = 'Producto no resuelto en catálogo';
        $estado = 'requiere_revision';

        return null;
    }

    private function aliasRequiresRevision(?string $productoRaw): bool
    {
        if (! $productoRaw) {
            return true;
        }

        $alias = ProductoAlias::query()
            ->where('alias_normalizado', TextNormalizer::normalize($productoRaw))
            ->where('fuente', 'excel')
            ->first();

        return $alias?->requiere_revision ?? false;
    }

    private function parseDate(?string $value): ?Carbon
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

    private function cellToString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return trim((string) $value);
    }

    private function isEmptyRow(?string ...$values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && trim($value) !== '') {
                return false;
            }
        }

        return true;
    }
}
