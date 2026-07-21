<?php

namespace Database\Seeders;

use App\Models\Categoria;
use Illuminate\Database\Seeder;

class CategoriaSeeder extends Seeder
{
    public function run(): void
    {
        Categoria::query()->updateOrCreate(
            ['nombre' => 'Productos A & C'],
            [
                'descripcion' => 'Catálogo general de productos de consumo A & C',
                'activo' => true,
            ]
        );
    }
}
