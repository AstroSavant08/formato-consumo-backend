<?php

/**
 * Read-only exhaustive Excel audit — delete after use.
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

$excelPath = 'C:\\xampp\\htdocs\\formato-consumo-frontend\\docs\\FORMATO CONSUMO Y PEDIDO PDTOS ASEO 2026.xlsx';

if (!is_file($excelPath)) {
    fwrite(STDERR, "File not found: {$excelPath}\n");
    exit(1);
}

function cellValue(Worksheet $ws, int $row, int $col): mixed
{
    return $ws->getCell([$col, $row])->getCalculatedValue();
}

function cellRaw(Worksheet $ws, int $row, int $col): mixed
{
    return $ws->getCell([$col, $row])->getValue();
}

function isRowEmpty(Worksheet $ws, int $row, int $maxCol): bool
{
    for ($c = 1; $c <= $maxCol; $c++) {
        $v = cellValue($ws, $row, $c);
        if ($v !== null && trim((string) $v) !== '') {
            return false;
        }
    }

    return true;
}

function rgbFromColor(?Fill $fill): ?string
{
    if (!$fill || $fill->getFillType() === Fill::FILL_NONE) {
        return null;
    }
    $rgb = $fill->getStartColor()->getRGB();

    return ($rgb && $rgb !== '000000') ? $rgb : null;
}

function tryParseDate(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        try {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
    $s = trim((string) $value);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return $s;
    }
    if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}$/', $s)) {
        $parts = explode('/', $s);
        if (count($parts) === 3) {
            $y = (int) $parts[2];
            if ($y < 100) {
                $y += 2000;
            }

            return sprintf('%04d-%02d-%02d', $y, (int) $parts[1], (int) $parts[0]);
        }
    }

    return null;
}

function normalizeText(?string $s): string
{
    $s = trim((string) $s);
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

    return mb_strtoupper($s, 'UTF-8');
}

$reader = IOFactory::createReaderForFile($excelPath);
$reader->setReadDataOnly(false);
$spreadsheet = $reader->load($excelPath);
$report = [
    'file' => [
        'name' => basename($excelPath),
        'path' => $excelPath,
        'size_bytes' => filesize($excelPath),
        'sheet_count' => $spreadsheet->getSheetCount(),
        'sheet_names' => $spreadsheet->getSheetNames(),
    ],
    'sheets' => [],
    'global' => [
        'formulas' => [],
        'merged_ranges' => [],
        'data_validations' => [],
        'table_structures' => [],
        'colors_used' => [],
        'years_found' => [],
        'months_found' => [],
        'dates_found' => [],
        'money_values' => [],
        'product_candidates' => [],
        'area_candidates' => [],
        'stock_keywords' => [],
        'formula_summary' => [],
    ],
];

$monthNames = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];
$stockKeywords = ['STOCK', 'EXISTEN', 'INVENT', 'RESERV', 'SALDO', 'MINIMO', 'MÁXIMO', 'MAXIMO', 'DISPON', 'PENDIENT', 'DEBIDO', 'COMPROM'];

foreach ($spreadsheet->getAllSheets() as $sheet) {
    $title = $sheet->getTitle();
    $dim = $sheet->calculateWorksheetDimension();
    $range = Coordinate::rangeBoundaries($dim);
    $minCol = $range[0][0];
    $maxCol = $range[1][0];
    $minRow = $range[0][1];
    $maxRow = $range[1][1];

    $merged = [];
    foreach ($sheet->getMergeCells() as $mergeRange) {
        $merged[] = $mergeRange;
        $report['global']['merged_ranges'][] = ['sheet' => $title, 'range' => $mergeRange];
    }

    $validations = [];
    foreach ($sheet->getDataValidationCollection() as $coord => $dv) {
        $validations[] = [
            'cell' => $coord,
            'type' => $dv->getType(),
            'formula1' => $dv->getFormula1(),
            'formula2' => $dv->getFormula2(),
            'allow_blank' => $dv->getAllowBlank(),
            'show_dropdown' => $dv->getShowDropDown(),
        ];
        $report['global']['data_validations'][] = array_merge(['sheet' => $title], end($validations));
    }

    $tables = [];
    if (method_exists($sheet, 'getTableCollection')) {
        foreach ($sheet->getTableCollection() as $table) {
            $tables[] = [
                'name' => $table->getName(),
                'range' => $table->getRange(),
            ];
            $report['global']['table_structures'][] = array_merge(['sheet' => $title], end($tables));
        }
    }

    $autoFilter = $sheet->getAutoFilter()?->getRange();

    $usedRows = [];
    $usedCols = [];
    $nonEmptyCells = 0;
    $formulaCells = [];
    $headerCandidates = [];
    $rowSamples = [];
    $sheetColors = [];
    $sheetYears = [];
    $sheetDates = [];
    $sheetMoney = [];
    $sheetProducts = [];
    $sheetAreas = [];
    $sheetStockHits = [];

    for ($r = $minRow; $r <= $maxRow; $r++) {
        $rowHasData = false;
        for ($c = $minCol; $c <= $maxCol; $c++) {
            $cell = $sheet->getCell([$c, $r]);
            $val = $cell->getCalculatedValue();
            $raw = $cell->getValue();
            $str = trim((string) $val);
            if ($str !== '') {
                $rowHasData = true;
                $nonEmptyCells++;
                $usedRows[$r] = true;
                $usedCols[$c] = true;

                $norm = normalizeText($str);
                foreach ($monthNames as $m) {
                    if (str_contains($norm, $m)) {
                        $report['global']['months_found'][$m] = ($report['global']['months_found'][$m] ?? 0) + 1;
                    }
                }
                if (preg_match('/\b(20\d{2})\b/', $str, $ym)) {
                    $sheetYears[$ym[1]] = ($sheetYears[$ym[1]] ?? 0) + 1;
                    $report['global']['years_found'][$ym[1]] = ($report['global']['years_found'][$ym[1]] ?? 0) + 1;
                }
                $parsedDate = tryParseDate($raw ?? $val);
                if ($parsedDate) {
                    $sheetDates[] = $parsedDate;
                    $report['global']['dates_found'][] = $parsedDate;
                }
                if (is_numeric($val) && (float) $val > 1000 && (float) $val < 100000000) {
                    $sheetMoney[] = ['cell' => Coordinate::stringFromColumnIndex($c).$r, 'value' => (float) $val];
                }
                foreach ($stockKeywords as $kw) {
                    if (str_contains($norm, $kw)) {
                        $sheetStockHits[] = ['cell' => Coordinate::stringFromColumnIndex($c).$r, 'text' => $str];
                    }
                }
            }

            if (is_string($raw) && str_starts_with($raw, '=')) {
                $coord = Coordinate::stringFromColumnIndex($c).$r;
                $formulaCells[] = [
                    'cell' => $coord,
                    'formula' => $raw,
                    'calculated' => $val,
                ];
                $report['global']['formulas'][] = [
                    'sheet' => $title,
                    'cell' => $coord,
                    'formula' => $raw,
                    'calculated' => $val,
                ];
            }

            $rgb = rgbFromColor($cell->getStyle()->getFill());
            if ($rgb && !in_array($rgb, ['FFFFFF', 'FFFFFFFF'], true)) {
                $sheetColors[$rgb] = ($sheetColors[$rgb] ?? 0) + 1;
                $report['global']['colors_used'][$rgb] = ($report['global']['colors_used'][$rgb] ?? 0) + 1;
            }
        }
        if ($rowHasData && count($rowSamples) < 8) {
            $cells = [];
            for ($c = $minCol; $c <= min($maxCol, $minCol + 15); $c++) {
                $v = cellValue($sheet, $r, $c);
                if ($v !== null && trim((string) $v) !== '') {
                    $cells[Coordinate::stringFromColumnIndex($c)] = $v;
                }
            }
            if ($cells) {
                $rowSamples[] = ['row' => $r, 'cells' => $cells];
            }
        }
    }

    // Header scan first 15 rows
    for ($r = $minRow; $r <= min($maxRow, $minRow + 20); $r++) {
        $headers = [];
        for ($c = $minCol; $c <= $maxCol; $c++) {
            $v = cellValue($sheet, $r, $c);
            if ($v !== null && trim((string) $v) !== '') {
                $headers[Coordinate::stringFromColumnIndex($c)] = trim((string) $v);
            }
        }
        if (count($headers) >= 2) {
            $headerCandidates[] = ['row' => $r, 'headers' => $headers];
        }
    }

    // Detect product-like first column text rows (heuristic for list sheets)
    for ($r = $minRow; $r <= $maxRow; $r++) {
        $first = trim((string) cellValue($sheet, $r, $minCol));
        $second = trim((string) cellValue($sheet, $r, $minCol + 1));
        if ($first !== '' && mb_strlen($first) > 3 && !is_numeric($first)) {
            if (!preg_match('/^(TOTAL|SUBTOTAL|PRODUCTO|#|N°|NO\.|ITEM)/iu', $first)) {
                $sheetProducts[] = $first;
            }
        }
        foreach ([$first, $second] as $txt) {
            if (preg_match('/\b(AREA|ÁREA|GERENCIA|PLANTA|OFICINA|BODEGA|COCINA|ADMIN)/iu', $txt)) {
                $sheetAreas[] = $txt;
            }
        }
    }

    sort($sheetDates);
    $uniqueProducts = array_values(array_unique($sheetProducts));

    $report['sheets'][$title] = [
        'dimension' => $dim,
        'min_col' => Coordinate::stringFromColumnIndex($minCol),
        'max_col' => Coordinate::stringFromColumnIndex($maxCol),
        'min_row' => $minRow,
        'max_row' => $maxRow,
        'used_rows_count' => count($usedRows),
        'used_cols_count' => count($usedCols),
        'non_empty_cells' => $nonEmptyCells,
        'merged_cells_count' => count($merged),
        'merged_ranges' => array_slice($merged, 0, 50),
        'data_validations_count' => count($validations),
        'data_validations' => $validations,
        'tables' => $tables,
        'auto_filter' => $autoFilter,
        'formula_count' => count($formulaCells),
        'formulas_sample' => array_slice($formulaCells, 0, 40),
        'header_candidates' => array_slice($headerCandidates, 0, 8),
        'row_samples' => $rowSamples,
        'colors' => $sheetColors,
        'years' => $sheetYears,
        'date_min' => $sheetDates[0] ?? null,
        'date_max' => $sheetDates ? $sheetDates[count($sheetDates) - 1] : null,
        'dates_count' => count($sheetDates),
        'money_samples' => array_slice($sheetMoney, 0, 20),
        'stock_keyword_hits' => array_slice($sheetStockHits, 0, 30),
        'product_like_values_count' => count($sheetProducts),
        'product_unique_count' => count($uniqueProducts),
        'products_unique_sample' => array_slice($uniqueProducts, 0, 80),
        'areas_sample' => array_values(array_unique(array_slice($sheetAreas, 0, 40))),
    ];

    foreach (array_slice($uniqueProducts, 0, 200) as $p) {
        $report['global']['product_candidates'][normalizeText($p)] = $p;
    }
}

// Formula type summary
$formulaTypes = [];
foreach ($report['global']['formulas'] as $f) {
    $fn = preg_match('/=([A-Z]+)\(/', $f['formula'], $m) ? $m[1] : 'OTHER';
    $formulaTypes[$fn] = ($formulaTypes[$fn] ?? 0) + 1;
}
$report['global']['formula_function_counts'] = $formulaTypes;
$report['global']['formula_total'] = count($report['global']['formulas']);
$report['global']['dates_found'] = array_values(array_unique($report['global']['dates_found']));
sort($report['global']['dates_found']);
$report['global']['date_range'] = [
    'min' => $report['global']['dates_found'][0] ?? null,
    'max' => $report['global']['dates_found'] ? $report['global']['dates_found'][count($report['global']['dates_found']) - 1] : null,
    'count' => count($report['global']['dates_found']),
];
$report['global']['product_candidates'] = array_values($report['global']['product_candidates']);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
