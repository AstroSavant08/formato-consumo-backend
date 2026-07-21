<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->foreignId('area_id')->constrained('areas');
            $table->foreignId('producto_id')->constrained('productos');
            $table->decimal('cantidad', 14, 2);
            $table->string('unidad', 20);
            $table->string('quien_recibe')->nullable();
            $table->string('entregado_por')->nullable();
            $table->enum('fuente', ['excel_historico', 'sistema'])->default('sistema');
            $table->unsignedInteger('excel_fila')->nullable();
            $table->string('excel_hash', 64)->nullable()->index();
            $table->boolean('es_posible_duplicado')->default(false);
            $table->foreignId('staging_id')->nullable()->constrained('excel_import_staging')->nullOnDelete();
            $table->foreignId('solicitud_id')->nullable();
            $table->timestamps();

            $table->index(['fecha', 'area_id']);
            $table->index(['fecha', 'producto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas');
    }
};
