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
        $area = Area::query()->where('codigo', TextNormalizer::normalize('MANTENIMIENTO'))->first();

        $users = [
            [
                'email' => 'solicitante@impadoc.test',
                'name' => 'Usuario Solicitante',
                'role_id' => $solicitanteRole?->id,
            ],
            [
                'email' => 'supervisor@impadoc.test',
                'name' => 'Usuario Supervisor',
                'role_id' => $supervisorRole?->id,
            ],
        ];

        foreach ($users as $data) {
            User::query()->updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                    'role_id' => $data['role_id'],
                    'area_id' => $area?->id,
                    'activo' => true,
                ],
            );
        }
    }
}
