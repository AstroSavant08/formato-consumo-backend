<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudDetalle extends Model
{
    protected $table = 'solicitud_detalles';

    protected $fillable = [
        'solicitud_id',
        'producto_id',
        'cantidad_solicitada',
        'unidad',
        'cantidad_aprobada',
        'precio_unitario',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_solicitada' => 'decimal:2',
            'cantidad_aprobada' => 'decimal:2',
            'precio_unitario' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(Solicitud::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
