<?php

namespace App\Services;

use App\Models\Persona;
use App\Support\CedulaNormalizer;
use InvalidArgumentException;

class PersonaService
{
    public function findByCedula(string $cedula): ?Persona
    {
        $normalized = CedulaNormalizer::normalize($cedula);

        if ($normalized === '') {
            return null;
        }

        return Persona::query()
            ->where('cedula', $normalized)
            ->where('activo', true)
            ->first();
    }

    /**
     * Busca por cédula o crea/actualiza en catálogo.
     */
    public function resolverParaEntrega(string $cedula, string $nombreCompleto): Persona
    {
        $cedulaNorm = CedulaNormalizer::normalize($cedula);
        $nombre = trim($nombreCompleto);

        if ($cedulaNorm === '') {
            throw new InvalidArgumentException('La cédula es obligatoria.');
        }

        if ($nombre === '') {
            throw new InvalidArgumentException('El nombre de quien retira es obligatorio.');
        }

        $persona = Persona::query()->where('cedula', $cedulaNorm)->first();

        if ($persona) {
            if (mb_strtoupper($persona->nombre_completo) !== mb_strtoupper($nombre)) {
                $persona->update(['nombre_completo' => $nombre]);
            }

            return $persona->fresh();
        }

        return Persona::query()->create([
            'cedula' => $cedulaNorm,
            'nombre_completo' => $nombre,
            'activo' => true,
        ]);
    }
}
