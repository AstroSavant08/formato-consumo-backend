<?php

namespace App\Console\Commands;

use App\Services\ExcelImportService;
use Illuminate\Console\Command;

class ValidateStagingCommand extends Command
{
    protected $signature = 'consumo:validate-staging';

    protected $description = 'Valida registros en excel_import_staging';

    public function handle(ExcelImportService $service): int
    {
        $result = $service->validateStaging();

        $this->table(['Estado', 'Cantidad'], collect($result)->map(fn ($v, $k) => [$k, $v]));

        return self::SUCCESS;
    }
}
