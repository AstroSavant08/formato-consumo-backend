<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Entrega extends Model
{
    protected $fillable = [
        'fecha',
        'area_id',
        'producto_id',
        'cantidad',
        'unidad',
        'quien_recibe',
        'entregado_por',
        'fuente',
        'excel_fila',
        'excel_hash',
        'es_posible_duplicado',
        'staging_id',
        'solicitud_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'cantidad' => 'decimal:2',
            'es_posible_duplicado' => 'boolean',
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function staging(): BelongsTo
    {
        return $this->belongsTo(ExcelImportStaging::class, 'staging_id');
    }
}
