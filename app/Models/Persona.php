<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Persona extends Model
{
    protected $fillable = [
        'cedula',
        'nombre_completo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function entregasRetira(): HasMany
    {
        return $this->hasMany(Entrega::class, 'persona_retira_id');
    }
}
