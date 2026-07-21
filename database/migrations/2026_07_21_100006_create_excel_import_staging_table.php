<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excel_import_staging', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('fila_excel');
            $table->string('fecha_raw')->nullable();
            $table->string('producto_raw')->nullable();
            $table->string('cantidad_raw')->nullable();
            $table->string('unidad_raw')->nullable();
            $table->string('area_raw')->nullable();
            $table->string('quien_recibe_raw')->nullable();
            $table->string('entrega_raw')->nullable();
            $table->json('errores_json')->nullable();
            $table->enum('estado', [
                'pendiente',
                'validado',
                'importado',
                'rechazado',
                'requiere_revision',
            ])->default('pendiente');
            $table->string('excel_hash', 64)->nullable()->index();
            $table->boolean('es_posible_duplicado')->default(false);
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->timestamps();

            $table->unique(['fila_excel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excel_import_staging');
    }
};
