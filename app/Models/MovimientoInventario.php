<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoInventario extends Model
{
    protected $table = 'movimientos_inventario';

    protected $fillable = [
        'producto_id',
        'tipo',
        'cantidad',
        'stock_anterior',
        'stock_posterior',
        'referencia_tipo',
        'referencia_id',
        'usuario_id',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:2',
            'stock_anterior' => 'decimal:2',
            'stock_posterior' => 'decimal:2',
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
