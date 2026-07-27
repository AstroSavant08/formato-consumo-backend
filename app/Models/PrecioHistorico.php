<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrecioHistorico extends Model
{
    protected $table = 'precios_historicos';

    protected $fillable = [
        'producto_id',
        'precio',
        'vigente_desde',
        'vigente_hasta',
        'usuario_id',
    ];

    protected function casts(): array
    {
        return [
            'precio' => 'decimal:2',
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
