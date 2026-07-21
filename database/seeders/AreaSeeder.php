<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Support\TextNormalizer;
use Illuminate\Database\Seeder;

class AreaSeeder extends Seeder
{
    public function run(): void
    {
        $areas = [
            'ADMINISTRATIVO',
            'LABORATORIO',
            'ALMACEN',
            'PORTERIA',
            'PRODUCCION',
            'DISTRIBUCION',
            'MANTENIMIENTO',
            'OFICINAS',
            'BODEGA REDDI',
            'MERCADEO Y VENTAS',
            'AFUERA',
            'TALENTO HUMANO',
        ];

        foreach ($areas as $nombre) {
            Area::query()->updateOrCreate(
                ['codigo' => TextNormalizer::normalize($nombre)],
                [
                    'nombre' => $nombre,
                    'activo' => true,
                    'es_desarrollo' => false,
                ]
            );
        }
    }
}
