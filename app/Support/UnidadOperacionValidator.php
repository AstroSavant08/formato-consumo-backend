<?php

namespace App\Support;

use App\Models\Producto;

class UnidadOperacionValidator
{
    /**
     * Fase 1 + 5.9: delega en UnidadConversionService para validar coincidencia
     * exacta o conversión compatible (ml↔l, g↔kg).
     */
    public static function validarCoincidenciaExacta(Producto $producto, string $unidadOperacion): void
    {
        app(UnidadConversionService::class)->resolverCantidadEnUnidadBase(
            $producto,
            1.0,
            $unidadOperacion,
        );
    }
}
