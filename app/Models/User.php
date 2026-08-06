<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @var list<string> */
    public const ROLES_MANAGE_SOLICITUDES = [
        Role::SOLICITANTE,
        Role::SUPERVISOR,
        Role::ADMIN,
    ];

    /** @var list<string> */
    public const ROLES_FULL_ACCESS = [
        Role::ADMIN,
        Role::SUPERVISOR,
    ];

    /** @var list<string> */
    public const ROLES_ENTREGAS = [
        Role::ADMIN,
        Role::SUPERVISOR,
        Role::ALMACEN,
    ];

    /** @var list<string> */
    public const ROLES_REVIEW_SOLICITUDES = [
        Role::SUPERVISOR,
        Role::ADMIN,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'area_id',
        'activo',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function hasRole(string ...$roles): bool
    {
        $nombre = $this->relationLoaded('role')
            ? $this->role?->nombre
            : $this->role()->value('nombre');

        return $nombre !== null && in_array($nombre, $roles, true);
    }

    public function canManageSolicitudes(): bool
    {
        return $this->hasRole(...self::ROLES_MANAGE_SOLICITUDES);
    }

    public function canReviewSolicitudes(): bool
    {
        return $this->hasRole(...self::ROLES_REVIEW_SOLICITUDES);
    }

    public function canAccessFullApp(): bool
    {
        return $this->hasRole(...self::ROLES_FULL_ACCESS);
    }

    public function canRegisterEntregas(): bool
    {
        return $this->hasRole(...self::ROLES_ENTREGAS);
    }
}
