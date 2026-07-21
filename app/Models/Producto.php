<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Producto extends Model
{
    protected $fillable = [
        'categoria_id',
        'nombre',
        'nombre_normalizado',
        'unidad_default',
        'stock_minimo_referencia',
        'activo',
        'es_desarrollo',
        'es_historico_excel',
    ];

    protected function casts(): array
    {
        return [
            'stock_minimo_referencia' => 'decimal:2',
            'activo' => 'boolean',
            'es_desarrollo' => 'boolean',
            'es_historico_excel' => 'boolean',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ProductoAlias::class);
    }

    public function inventario(): HasOne
    {
        return $this->hasOne(Inventario::class);
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(Entrega::class);
    }
}
