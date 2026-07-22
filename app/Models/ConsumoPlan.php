<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsumoPlan extends Model
{
    public const TIPO_CONSUMO_ANIO = 'consumo_anio';

    public const TIPO_FORMATO_PEDIDO = 'formato_pedido';

    protected $table = 'consumo_planes';

    protected $fillable = [
        'anio',
        'tipo',
        'fecha_pedido',
        'solicitado_por',
        'autorizado_por',
        'cantidad_dinero_solicitada',
    ];

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
        ];
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(ConsumoPlanLinea::class)->orderBy('orden');
    }

    public function pedidosEspeciales(): HasMany
    {
        return $this->hasMany(ConsumoPlanPedidoEspecial::class)->orderBy('orden');
    }
}
