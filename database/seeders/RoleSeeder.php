<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['nombre' => 'admin', 'descripcion' => 'Administrador del sistema'],
            ['nombre' => 'supervisor', 'descripcion' => 'Supervisor de área'],
            ['nombre' => 'solicitante', 'descripcion' => 'Usuario solicitante'],
            ['nombre' => 'almacen', 'descripcion' => 'Personal de almacén y entregas'],
        ];

        foreach ($roles as $role) {
            Role::query()->updateOrCreate(['nombre' => $role['nombre']], $role);
        }
    }
}
