<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->nullable()->constrained('categorias')->nullOnDelete();
            $table->string('nombre');
            $table->string('nombre_normalizado')->index();
            $table->string('unidad_default', 20)->nullable();
            $table->decimal('stock_minimo_referencia', 12, 2)->nullable();
            $table->boolean('activo')->default(true);
            $table->boolean('es_desarrollo')->default(false);
            $table->boolean('es_historico_excel')->default(false);
            $table->timestamps();

            $table->unique(['nombre_normalizado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
