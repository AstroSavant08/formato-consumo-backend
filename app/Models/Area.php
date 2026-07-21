<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Area extends Model
{
    protected $fillable = [
        'codigo',
        'nombre',
        'activo',
        'es_desarrollo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'es_desarrollo' => 'boolean',
        ];
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(Entrega::class);
    }
}
