<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExcelImportHomologacion extends Model
{
    protected $table = 'excel_import_homologaciones';

    protected $fillable = [
        'staging_id',
        'producto_id_destino',
        'confirmado_por',
        'fecha_confirmacion',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'fecha_confirmacion' => 'datetime',
        ];
    }

    public function staging(): BelongsTo
    {
        return $this->belongsTo(ExcelImportStaging::class, 'staging_id');
    }

    public function productoDestino(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id_destino');
    }
}
