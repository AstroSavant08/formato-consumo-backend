<?php

namespace Database\Seeders;

use App\Models\ConfiguracionAlerta;
use Illuminate\Database\Seeder;

class ConfiguracionAlertaSeeder extends Seeder
{
    public function run(): void
    {
        ConfiguracionAlerta::query()->updateOrCreate(
            ['clave' => 'consumo_variacion_porcentual'],
            [
                'descripcion' => 'Variación porcentual de consumo vs promedio histórico',
                'umbral_verde' => 15,
                'umbral_amarillo' => 40,
                'umbral_rojo' => 40,
                'activo' => true,
            ]
        );
    }
}
