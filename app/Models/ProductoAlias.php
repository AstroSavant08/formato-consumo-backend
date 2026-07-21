<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductoAlias extends Model
{
    protected $table = 'producto_aliases';

    protected $fillable = [
        'producto_id',
        'alias',
        'alias_normalizado',
        'fuente',
        'confianza',
        'revisado',
        'requiere_revision',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'confianza' => 'decimal:2',
            'revisado' => 'boolean',
            'requiere_revision' => 'boolean',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
