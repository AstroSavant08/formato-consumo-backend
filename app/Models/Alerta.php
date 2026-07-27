<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alerta extends Model
{
    protected $table = 'alertas';

    protected $fillable = [
        'tipo',
        'severidad',
        'producto_id',
        'area_id',
        'mensaje',
        'metadata',
        'leida',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'leida' => 'boolean',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }
}
