<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumo_planes', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('anio');
            $table->string('tipo', 50)->default('consumo_anio');
            $table->timestamps();

            $table->unique(['anio', 'tipo']);
        });

        Schema::create('consumo_plan_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumo_plan_id')->constrained('consumo_planes')->cascadeOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->string('nombre_producto');
            $table->decimal('stock_debido', 14, 2)->default(0);
            $table->unsignedInteger('orden');
            $table->timestamps();

            $table->index(['consumo_plan_id', 'orden']);
        });

        Schema::create('consumo_plan_meses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumo_plan_linea_id')->constrained('consumo_plan_lineas')->cascadeOnDelete();
            $table->unsignedTinyInteger('mes');
            $table->decimal('cantidad', 14, 2)->default(0);
            $table->decimal('existencia', 14, 2)->default(0);
            $table->decimal('dinero_solicitar', 14, 2)->nullable();
            $table->timestamps();

            $table->unique(['consumo_plan_linea_id', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumo_plan_meses');
        Schema::dropIfExists('consumo_plan_lineas');
        Schema::dropIfExists('consumo_planes');
    }
};
