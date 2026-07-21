<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Entrega;
use App\Models\ExcelImportStaging;
use App\Models\ProductoAlias;
use App\Support\TextNormalizer;
use Carbon\Carbon;
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
            $cantidadRaw = $this->cellToString($sheet->getCell("C{$row}")->getValue());
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

            $productoId = $this->resolveProductoId($record->producto_raw, $errors, $estado);
            if ($productoId === null) {
                $estado = 'requiere_revision';
            }

            $esDuplicado = ($hashCounts[$record->excel_hash] ?? 0) > 1;

            if ($estado === 'validado' && $productoId && $this->aliasRequiresRevision($record->producto_raw)) {
                $estado = 'requiere_revision';
                $errors[] = 'Producto con alias pendiente de revisión humana';
            }

            $record->update([
                'estado' => $estado,
                'errores_json' => $errors ?: null,
                'area_id' => $area?->id,
                'producto_id' => $productoId,
                'es_posible_duplicado' => $esDuplicado,
            ]);

            $stats[$estado]++;
            if ($esDuplicado) {
                $stats['posibles_duplicados']++;
            }
        }

        return $stats;
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
            if (! $record->producto_id || ! $record->area_id) {
                $skipped++;
                continue;
            }

            if (Entrega::query()->where('staging_id', $record->id)->exists()) {
                $skipped++;
                continue;
            }

            $fecha = $this->parseDate($record->fecha_raw);
            if (! $fecha) {
                $skipped++;
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
            $promoted++;
        }

        return [
            'promoted' => $promoted,
            'skipped' => $skipped,
        ];
    }

    public function getStagingSummary(): array
    {
        return [
            'total' => ExcelImportStaging::count(),
            'by_estado' => ExcelImportStaging::query()
                ->selectRaw('estado, COUNT(*) as total')
                ->groupBy('estado')
                ->pluck('total', 'estado'),
            'requiere_revision' => ExcelImportStaging::where('estado', 'requiere_revision')->count(),
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
