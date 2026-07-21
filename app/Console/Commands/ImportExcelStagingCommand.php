<?php

namespace App\Console\Commands;

use App\Services\ExcelImportService;
use Illuminate\Console\Command;

class ImportExcelStagingCommand extends Command
{
    protected $signature = 'consumo:import-excel-staging {path?}';

    protected $description = 'Importa el Excel histórico a la tabla excel_import_staging (solo lectura del archivo)';

    public function handle(ExcelImportService $service): int
    {
        $path = $this->argument('path')
            ?? base_path('../formato-consumo-frontend/docs/Consumo_DESARROLLO.xlsx');

        $this->info("Importando desde: {$path}");

        $result = $service->importToStaging($path);

        $this->table(['Métrica', 'Valor'], collect($result)->map(fn ($v, $k) => [$k, $v]));

        return self::SUCCESS;
    }
}
