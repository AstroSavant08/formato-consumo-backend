<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsumoPlanLinea extends Model
{
    protected $table = 'consumo_plan_lineas';
    protected $fillable = [
        'consumo_plan_id',
        'producto_id',
        'nombre_producto',
        'stock_debido',
        'dinero_solicitado',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'stock_debido' => 'decimal:2',
            'dinero_solicitado' => 'decimal:2',
            'orden' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ConsumoPlan::class, 'consumo_plan_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function meses(): HasMany
    {
        return $this->hasMany(ConsumoPlanMes::class)->orderBy('mes');
    }
}
