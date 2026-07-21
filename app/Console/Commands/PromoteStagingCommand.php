<?php

namespace App\Console\Commands;

use App\Services\ExcelImportService;
use Illuminate\Console\Command;

class PromoteStagingCommand extends Command
{
    protected $signature = 'consumo:promote-staging';

    protected $description = 'Promueve registros validados de staging a entregas históricas';

    public function handle(ExcelImportService $service): int
    {
        $result = $service->promoteValidated();

        $this->table(['Métrica', 'Valor'], collect($result)->map(fn ($v, $k) => [$k, $v]));

        return self::SUCCESS;
    }
}
