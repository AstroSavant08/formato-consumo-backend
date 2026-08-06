<?php

namespace Database\Seeders;

use App\Models\Persona;
use Illuminate\Database\Seeder;

class PersonaSeeder extends Seeder
{
    public function run(): void
    {
        $personas = [
            ['cedula' => '25619959', 'nombre_completo' => 'VIVIAN JHOANA'],
            ['cedula' => '31528113', 'nombre_completo' => 'RAMOS BOSSA MARIA DEL SOCORRO'],
            ['cedula' => '1107085013', 'nombre_completo' => 'MONTENEGRO VARA YOINER ALEXIS'],
        ];

        foreach ($personas as $data) {
            Persona::query()->updateOrCreate(
                ['cedula' => $data['cedula']],
                [
                    'nombre_completo' => $data['nombre_completo'],
                    'activo' => true,
                ],
            );
        }
    }
}
