<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            $table->string('cedula', 20)->unique();
            $table->string('nombre_completo', 255);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('nombre_completo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personas');
    }
};
