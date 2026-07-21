<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_alertas', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 80)->unique();
            $table->string('descripcion')->nullable();
            $table->decimal('umbral_verde', 8, 2)->default(15);
            $table->decimal('umbral_amarillo', 8, 2)->default(40);
            $table->decimal('umbral_rojo', 8, 2)->default(40);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('alertas', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 50);
            $table->enum('severidad', ['verde', 'amarillo', 'rojo'])->default('amarillo');
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->text('mensaje');
            $table->json('metadata')->nullable();
            $table->boolean('leida')->default(false);
            $table->timestamps();

            $table->index(['severidad', 'leida']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas');
        Schema::dropIfExists('configuracion_alertas');
    }
};
