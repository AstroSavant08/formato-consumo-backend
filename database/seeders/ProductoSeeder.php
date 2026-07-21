<?php

namespace Database\Seeders;

use App\Models\Categoria;
use App\Models\Producto;
use App\Support\TextNormalizer;
use Illuminate\Database\Seeder;

class ProductoSeeder extends Seeder
{
    public function run(): void
    {
        $categoria = Categoria::query()->where('nombre', 'Productos A & C')->firstOrFail();
        $frontend = require database_path('data/productos_frontend.php');
        $excelHistoricos = require database_path('data/productos_excel_historicos.php');

        foreach ($frontend as $item) {
            Producto::query()->updateOrCreate(
                ['nombre_normalizado' => TextNormalizer::normalize($item['nombre'])],
                [
                    'categoria_id' => $categoria->id,
                    'nombre' => $item['nombre'],
                    'stock_minimo_referencia' => $item['stock_minimo_referencia'],
                    'activo' => true,
                    'es_desarrollo' => false,
                    'es_historico_excel' => false,
                ]
            );
        }

        foreach ($excelHistoricos as $nombre) {
            Producto::query()->updateOrCreate(
                ['nombre_normalizado' => TextNormalizer::normalize($nombre)],
                [
                    'categoria_id' => $categoria->id,
                    'nombre' => $nombre,
                    'activo' => true,
                    'es_desarrollo' => false,
                    'es_historico_excel' => true,
                ]
            );
        }
    }
}
