<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Solicitud extends Model
{
    protected $table = 'solicitudes';

    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_EN_REVISION = 'en_revision';

    public const ESTADO_APROBADA = 'aprobada';

    public const ESTADO_RECHAZADA = 'rechazada';

    public const ESTADO_ENTREGADA = 'entregada';

    public const ESTADO_CANCELADA = 'cancelada';

    protected $fillable = [
        'numero',
        'area_id',
        'usuario_id',
        'fecha',
        'estado',
        'justificacion',
        'observaciones',
        'total',
        'aprobado_por',
        'aprobado_at',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'total' => 'decimal:2',
            'aprobado_at' => 'datetime',
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(SolicitudDetalle::class);
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(Entrega::class);
    }

    public function puedeEditarse(): bool
    {
        return in_array($this->estado, [self::ESTADO_PENDIENTE, self::ESTADO_EN_REVISION], true);
    }

    public function tieneCompromisoActivo(): bool
    {
        return $this->estado === self::ESTADO_APROBADA;
    }
}
