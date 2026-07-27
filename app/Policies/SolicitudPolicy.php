<?php

namespace App\Policies;

use App\Models\Solicitud;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SolicitudPolicy
{
    public function create(User $user): Response|bool
    {
        return $user->canManageSolicitudes()
            ? true
            : Response::deny('No tiene permiso para crear solicitudes.');
    }

    public function update(User $user, Solicitud $solicitud): Response|bool
    {
        return $user->canManageSolicitudes()
            ? true
            : Response::deny('No tiene permiso para modificar solicitudes.');
    }

    public function aprobar(User $user, Solicitud $solicitud): Response|bool
    {
        return $user->canReviewSolicitudes()
            ? true
            : Response::deny('No tiene permiso para aprobar solicitudes.');
    }

    public function rechazar(User $user, Solicitud $solicitud): Response|bool
    {
        return $user->canReviewSolicitudes()
            ? true
            : Response::deny('No tiene permiso para rechazar solicitudes.');
    }

    public function cancelar(User $user, Solicitud $solicitud): Response|bool
    {
        return $user->canReviewSolicitudes()
            ? true
            : Response::deny('No tiene permiso para cancelar solicitudes.');
    }
}
