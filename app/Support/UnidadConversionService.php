<?php



namespace App\Support;



use App\Exceptions\InventarioException;

use App\Models\Producto;



class UnidadConversionService

{

    private const FACTOR = 1000.0;



    /**

     * Resuelve la cantidad operativa expresada en la unidad base del producto.

     *

     * Política unidad_default NULL: no valida ni convierte; devuelve la cantidad tal cual.

     * Política unidad omitida en entrada: no valida ni convierte (comportamiento previo).

     */

    public function resolverCantidadEnUnidadBase(

        Producto $producto,

        float $cantidad,

        ?string $unidadOperacion,

    ): float {

        if ($producto->unidad_default === null || trim((string) $producto->unidad_default) === '') {

            return $this->redondear($cantidad);

        }



        if ($unidadOperacion === null || trim($unidadOperacion) === '') {

            return $this->redondear($cantidad);

        }



        $unidadBase = TextNormalizer::normalizeUnit($producto->unidad_default);

        $unidadRecibida = TextNormalizer::normalizeUnit($unidadOperacion);



        if ($unidadBase === null || $unidadRecibida === null) {

            throw new InventarioException(

                'La unidad de la operación no coincide con la unidad del producto.',

                422,

                [

                    'unidad_producto' => $unidadBase ?? TextNormalizer::normalize($producto->unidad_default),

                    'unidad_recibida' => $unidadRecibida ?? TextNormalizer::normalize($unidadOperacion),

                ]

            );

        }



        if ($unidadBase === $unidadRecibida) {

            return $this->redondear($cantidad);

        }



        if ($this->esParConvertible($unidadRecibida, $unidadBase)) {

            return $this->redondear($this->convertir($cantidad, $unidadRecibida, $unidadBase));

        }



        throw new InventarioException(

            'La unidad de la operación no coincide con la unidad del producto.',

            422,

            [

                'unidad_producto' => $unidadBase,

                'unidad_recibida' => $unidadRecibida,

            ]

        );

    }



    private function esParConvertible(string $unidadRecibida, string $unidadBase): bool

    {

        return match ($unidadRecibida) {

            'ML' => $unidadBase === 'L',

            'L' => $unidadBase === 'ML',

            'G' => $unidadBase === 'KG',

            'KG' => $unidadBase === 'G',

            default => false,

        };

    }



    private function convertir(float $cantidad, string $unidadRecibida, string $unidadBase): float

    {

        if ($unidadRecibida === 'ML' && $unidadBase === 'L') {

            return $cantidad / self::FACTOR;

        }



        if ($unidadRecibida === 'L' && $unidadBase === 'ML') {

            return $cantidad * self::FACTOR;

        }



        if ($unidadRecibida === 'G' && $unidadBase === 'KG') {

            return $cantidad / self::FACTOR;

        }



        if ($unidadRecibida === 'KG' && $unidadBase === 'G') {

            return $cantidad * self::FACTOR;

        }



        throw new InventarioException(

            'La unidad de la operación no coincide con la unidad del producto.',

            422,

            [

                'unidad_producto' => $unidadBase,

                'unidad_recibida' => $unidadRecibida,

            ]

        );

    }



    private function redondear(float $cantidad): float

    {

        return round($cantidad, 2);

    }

}


