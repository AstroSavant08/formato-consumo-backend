<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumoPlanPedidoEspecial extends Model
{
    protected $table = 'consumo_plan_pedidos_especiales';

    protected $fillable = [
        'consumo_plan_id',
        'orden',
        'descripcion',
        'cantidad',
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'cantidad' => 'decimal:2',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ConsumoPlan::class, 'consumo_plan_id');
    }
}
