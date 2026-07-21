<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfiguracionAlerta extends Model
{
    protected $table = 'configuracion_alertas';

    protected $fillable = [
        'clave',
        'descripcion',
        'umbral_verde',
        'umbral_amarillo',
        'umbral_rojo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'umbral_verde' => 'decimal:2',
            'umbral_amarillo' => 'decimal:2',
            'umbral_rojo' => 'decimal:2',
            'activo' => 'boolean',
        ];
    }
}
