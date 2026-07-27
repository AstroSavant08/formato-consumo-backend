<?php

namespace App\Support;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ExcelDatePresenter
{
    /**
     * Convierte fecha_raw del Excel histórico a texto legible sin alterar el valor original.
     */
    public static function present(?string $fechaRaw): ?string
    {
        $parsed = self::parse($fechaRaw);

        if ($parsed === null) {
            return $fechaRaw !== null && trim($fechaRaw) !== '' ? trim($fechaRaw) : null;
        }

        return $parsed->format('d/m/Y');
    }

    public static function parse(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        try {
            if (is_numeric($value)) {
                return Carbon::instance(Date::excelToDateTimeObject((float) $value));
            }

            if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $value) === 1) {
                return Carbon::createFromFormat('d/m/Y', $value);
            }

            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
