<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Role;
use App\Models\User;
use App\Support\TextNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $solicitanteRole = Role::query()->where('nombre', 'solicitante')->first();
        $supervisorRole = Role::query()->where('nombre', 'supervisor')->first();
        $adminRole = Role::query()->where('nombre', 'admin')->first();
        $almacenRole = Role::query()->where('nombre', 'almacen')->first();
        $area = Area::query()->where('codigo', TextNormalizer::normalize('MANTENIMIENTO'))->first();

        $users = [
            [
                'email' => 'solicitante@impadoc.test',
                'name' => 'Usuario Solicitante',
                'password' => 'password',
                'role_id' => $solicitanteRole?->id,
            ],
            [
                'email' => 'supervisor@impadoc.test',
                'name' => 'Usuario Supervisor',
                'password' => 'password',
                'role_id' => $supervisorRole?->id,
            ],
            [
                'email' => 'marcela@impadoc.test',
                'name' => 'Marcela (Admin)',
                'password' => '1234561',
                'role_id' => $adminRole?->id,
            ],
            [
                'email' => 'almacen@impadoc.test',
                'name' => 'Almacén IMPADOC',
                'password' => '123',
                'role_id' => $almacenRole?->id,
            ],
        ];

        foreach ($users as $data) {
            User::query()->updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make($data['password']),
                    'role_id' => $data['role_id'],
                    'area_id' => $area?->id,
                    'activo' => true,
                ],
            );
        }
    }
}
