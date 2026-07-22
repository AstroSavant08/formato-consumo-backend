<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumoPlanMes extends Model
{
    protected $table = 'consumo_plan_meses';
    protected $fillable = [
        'consumo_plan_linea_id',
        'mes',
        'cantidad',
        'existencia',
        'dinero_solicitar',
    ];

    protected function casts(): array
    {
        return [
            'mes' => 'integer',
            'cantidad' => 'decimal:2',
            'existencia' => 'decimal:2',
            'dinero_solicitar' => 'decimal:2',
        ];
    }

    public function linea(): BelongsTo
    {
        return $this->belongsTo(ConsumoPlanLinea::class, 'consumo_plan_linea_id');
    }
}
