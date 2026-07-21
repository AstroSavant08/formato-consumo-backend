<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventario extends Model
{
    protected $table = 'inventarios';

    protected $fillable = [
        'producto_id',
        'stock_fisico',
        'stock_reserva',
        'stock_minimo',
        'stock_comprometido',
    ];

    protected function casts(): array
    {
        return [
            'stock_fisico' => 'decimal:2',
            'stock_reserva' => 'decimal:2',
            'stock_minimo' => 'decimal:2',
            'stock_comprometido' => 'decimal:2',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function getStockDisponibleAttribute(): float
    {
        return (float) $this->stock_fisico
            - (float) $this->stock_reserva
            - (float) $this->stock_comprometido;
    }
}
