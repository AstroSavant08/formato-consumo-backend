<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    public const ADMIN = 'admin';

    public const SUPERVISOR = 'supervisor';

    public const SOLICITANTE = 'solicitante';

    public const ALMACEN = 'almacen';

    protected $fillable = ['nombre', 'descripcion'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
