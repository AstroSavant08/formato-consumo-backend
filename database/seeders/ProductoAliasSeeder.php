<?php

namespace Database\Seeders;

use App\Models\Producto;
use App\Models\ProductoAlias;
use App\Support\TextNormalizer;
use Illuminate\Database\Seeder;

class ProductoAliasSeeder extends Seeder
{
    public function run(): void
    {
        $altaConfianza = require database_path('data/aliases_alta_confianza.php');
        $pendientes = require database_path('data/aliases_pendientes_revision.php');

        foreach ($altaConfianza as $aliasKey => $config) {
            $producto = Producto::query()
                ->where('nombre_normalizado', TextNormalizer::normalize($config['producto']))
                ->first();

            if (! $producto) {
                continue;
            }

            ProductoAlias::query()->updateOrCreate(
                [
                    'alias_normalizado' => $aliasKey,
                    'fuente' => 'excel',
                ],
                [
                    'producto_id' => $producto->id,
                    'alias' => $aliasKey,
                    'confianza' => $config['confianza'],
                    'revisado' => $config['revisado'],
                    'requiere_revision' => false,
                    'notas' => 'Mapeo automático de alta confianza',
                ]
            );
        }

        foreach ($pendientes as $aliasKey => $notas) {
            $productoHistorico = Producto::query()
                ->where('nombre_normalizado', $aliasKey)
                ->where('es_historico_excel', true)
                ->first();

            ProductoAlias::query()->updateOrCreate(
                [
                    'alias_normalizado' => $aliasKey,
                    'fuente' => 'excel',
                ],
                [
                    'producto_id' => $productoHistorico?->id,
                    'alias' => str_replace('_', ' ', $aliasKey),
                    'confianza' => 0,
                    'revisado' => false,
                    'requiere_revision' => true,
                    'notas' => $notas,
                ]
            );
        }

        // Alias pendientes para variantes frontend relacionadas (sin fusión automática)
        $frontendPendientes = [
            'Papel higienico - dispensadores',
            'Papel higienico - planta y oficinas pq',
            'Jabon Liquido (lavamanos)',
            'Jabon Lavaloza liquido',
            'Escobillon para baño',
            'Jabon Fab en polvo',
            'Insecticida zancudos',
            'Insecticida cucaracha',
            'Filtros cafetera # 2',
            'Filtros cafetera # 8',
            'Zabra - esponja para lavar losa',
        ];

        foreach ($frontendPendientes as $nombre) {
            $producto = Producto::query()
                ->where('nombre_normalizado', TextNormalizer::normalize($nombre))
                ->first();

            if (! $producto) {
                continue;
            }

            ProductoAlias::query()->updateOrCreate(
                [
                    'alias_normalizado' => TextNormalizer::normalize($nombre),
                    'fuente' => 'frontend_pendiente',
                ],
                [
                    'producto_id' => $producto->id,
                    'alias' => $nombre,
                    'confianza' => 0,
                    'revisado' => false,
                    'requiere_revision' => true,
                    'notas' => 'Variante frontend relacionada con ambigüedad histórica; no fusionar automáticamente',
                ]
            );
        }
    }
}
