<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExcelImportStaging extends Model
{
    protected $table = 'excel_import_staging';

    protected $fillable = [
        'fila_excel',
        'fecha_raw',
        'producto_raw',
        'cantidad_raw',
        'unidad_raw',
        'area_raw',
        'quien_recibe_raw',
        'entrega_raw',
        'errores_json',
        'estado',
        'excel_hash',
        'es_posible_duplicado',
        'producto_id',
        'area_id',
    ];

    protected function casts(): array
    {
        return [
            'errores_json' => 'array',
            'es_posible_duplicado' => 'boolean',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(Entrega::class, 'staging_id');
    }
}
